<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    private function isAdmin($user): bool
    {
        return $user->role === 'admin';
    }

    private function canUseLeads($user): bool
    {
        return in_array($user->role, ['admin', 'sales','company', 'finance','support'], true);
    }

    /** Admins see all leads, sales experts only their own. */
    private function visibleLeads($user)
    {
        $query = Lead::query();

        if (!$this->isAdmin($user)) {
            $query->where('created_by', $user->id);
        }

        return $query;
    }

    private function deny(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated. Please login again.'], 401);
        }

        if (!$this->canUseLeads($user)) {
            return response()->json(['status' => false, 'message' => 'You are not allowed to manage leads.'], 403);
        }

        return null;
    }

    /** Create a lead */
    public function store(Request $request): JsonResponse
    {
        if ($error = $this->deny($request)) {
            return $error;
        }

        $user = $request->user();

        $validated = $request->validate([
            'company_name'      => ['required', 'string', 'max:255'],
            'address'           => ['required', 'string', 'max:500'],
            'phone'             => ['required', 'string', 'max:50', 'unique:leads,phone'],
            'contact_person'    => ['nullable', 'string', 'max:255'],
            'email'             => ['nullable', 'email', 'max:255'],
            'response'          => ['nullable', 'string'],
            'follow_up_date'    => ['nullable', 'date', 'after_or_equal:today'],
            'follow_up_process' => ['nullable', 'required_with:follow_up_date', Rule::in(Lead::PROCESSES)],
            'status'            => ['nullable', Rule::in(Lead::STATUSES)],
            'notes'             => ['nullable', 'string'],
        ]);

        DB::beginTransaction();
        try {
            $lead = Lead::create($validated + [
                'status'     => $validated['status'] ?? 'new',
                'created_by' => $user->id,
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action'  => 'Lead Created',
                'details' => json_encode([
                    'lead_id' => $lead->id,
                    'company' => $lead->company_name,
                    'phone'   => $lead->phone,
                ]),
                'type'    => 'lead',
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Lead recorded successfully.',
                'data'    => $lead,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Lead creation failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /** List leads with filters: ?status=&search=&from=&to=&follow_up_date= */
    public function index(Request $request): JsonResponse
    {
        if ($error = $this->deny($request)) {
            return $error;
        }

        $query = $this->visibleLeads($request->user())->with('creator:id,name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('follow_up_date')) {
            $query->whereDate('follow_up_date', $request->follow_up_date);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $leads = $query->latest()->paginate(min((int) $request->get('per_page', 20), 100));

        return response()->json(['status' => true, 'data' => $leads]);
    }

    /** Leads whose follow-up is due today or overdue */
    public function due(Request $request): JsonResponse
    {
        if ($error = $this->deny($request)) {
            return $error;
        }

        $leads = $this->visibleLeads($request->user())
            ->whereNotNull('follow_up_date')
            ->whereDate('follow_up_date', '<=', now()->toDateString())
            ->whereNotIn('status', ['converted', 'lost'])
            ->orderBy('follow_up_date')
            ->get();

        return response()->json([
            'status' => true,
            'count'  => $leads->count(),
            'data'   => $leads,
        ]);
    }

    /** One lead with its follow-up history */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($error = $this->deny($request)) {
            return $error;
        }

        $lead = $this->visibleLeads($request->user())
            ->with(['creator:id,name', 'followUps.user:id,name'])
            ->find($id);

        if (!$lead) {
            return response()->json(['status' => false, 'message' => 'Lead not found.'], 404);
        }

        return response()->json(['status' => true, 'data' => $lead]);
    }

    /** Edit a lead */
    public function update(Request $request, int $id): JsonResponse
    {
        if ($error = $this->deny($request)) {
            return $error;
        }

        $user = $request->user();
        $lead = $this->visibleLeads($user)->find($id);

        if (!$lead) {
            return response()->json(['status' => false, 'message' => 'Lead not found.'], 404);
        }

        $validated = $request->validate([
            'company_name'      => ['sometimes', 'required', 'string', 'max:255'],
            'address'           => ['sometimes', 'required', 'string', 'max:500'],
            'phone'             => ['sometimes', 'required', 'string', 'max:50', Rule::unique('leads', 'phone')->ignore($lead->id)],
            'contact_person'    => ['nullable', 'string', 'max:255'],
            'email'             => ['nullable', 'email', 'max:255'],
            'response'          => ['nullable', 'string'],
            'follow_up_date'    => ['nullable', 'date', 'after_or_equal:today'],
            'follow_up_process' => ['nullable', Rule::in(Lead::PROCESSES)],
            'status'            => ['sometimes', Rule::in(Lead::STATUSES)],
            'notes'             => ['nullable', 'string'],
        ]);

        DB::beginTransaction();
        try {
            $lead->update($validated);

            ActivityLog::create([
                'user_id' => $user->id,
                'action'  => 'Lead Updated',
                'details' => json_encode(['lead_id' => $lead->id, 'changes' => array_keys($validated)]),
                'type'    => 'lead',
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Lead updated successfully.',
                'data'    => $lead->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Lead update failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /** Log a follow-up: saves the history row and updates the lead */
    public function addFollowUp(Request $request, int $id): JsonResponse
    {
        if ($error = $this->deny($request)) {
            return $error;
        }

        $user = $request->user();
        $lead = $this->visibleLeads($user)->find($id);

        if (!$lead) {
            return response()->json(['status' => false, 'message' => 'Lead not found.'], 404);
        }

        $validated = $request->validate([
            'contact_method'         => ['required', Rule::in(Lead::PROCESSES)],
            'response'               => ['required', 'string'],
            'status_after'           => ['required', Rule::in(Lead::STATUSES)],
            'next_follow_up_date'    => ['nullable', 'date', 'after_or_equal:today'],
            'next_follow_up_process' => ['nullable', 'required_with:next_follow_up_date', Rule::in(Lead::PROCESSES)],
        ]);

        DB::beginTransaction();
        try {
            $followUp = LeadFollowUp::create($validated + [
                'lead_id' => $lead->id,
                'user_id' => $user->id,
            ]);

            // Closed leads have no next follow-up
            $closed = in_array($validated['status_after'], ['converted', 'lost'], true);

            $lead->update([
                'response'          => $validated['response'],
                'status'            => $validated['status_after'],
                'follow_up_date'    => $closed ? null : ($validated['next_follow_up_date'] ?? null),
                'follow_up_process' => $closed ? null : ($validated['next_follow_up_process'] ?? null),
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'action'  => 'Lead Follow-up',
                'details' => json_encode([
                    'lead_id'      => $lead->id,
                    'method'       => $validated['contact_method'],
                    'status_after' => $validated['status_after'],
                ]),
                'type'    => 'lead',
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Follow-up recorded successfully.',
                'data'    => [
                    'follow_up' => $followUp,
                    'lead'      => $lead->fresh(),
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Could not record follow-up.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /** Delete a lead (admin only) */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated. Please login again.'], 401);
        }

        if (!$this->isAdmin($user)) {
            return response()->json(['status' => false, 'message' => 'Only an admin can delete leads.'], 403);
        }

        $lead = Lead::find($id);

        if (!$lead) {
            return response()->json(['status' => false, 'message' => 'Lead not found.'], 404);
        }

        $lead->delete();

        ActivityLog::create([
            'user_id' => $user->id,
            'action'  => 'Lead Deleted',
            'details' => json_encode(['lead_id' => $id, 'company' => $lead->company_name]),
            'type'    => 'lead',
        ]);

        return response()->json(['status' => true, 'message' => 'Lead deleted successfully.']);
    }
}