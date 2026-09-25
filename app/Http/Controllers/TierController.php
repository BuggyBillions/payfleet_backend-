<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Company;
use App\Models\Employees;
use App\Models\Notification;
use App\Models\Tier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;


class TierController extends Controller
{
    private function isFullAdmin(User $user): bool
    {
        return $user->role === 'admin';
    }

    public  function createTier(Request $request)
    {
        $admin   =  $request->user();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login again.'
            ], 401);
        }
        
        if (!$this->isFullAdmin($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Only an admin can create Financial Officer.'
            ], 403);
        }

        $validated = $request->validate([
            'name' => [ 'required'],
            'level' => [ 'required'],
            'no_of_staff' => [ 'required', 'string'],
            'amount' => ['sometimes','nullable','string'],
            'requirements' => ['required','string']
        ]);

        DB::beginTransaction();
        try {
            $user = Tier::create([
                'name' =>$validated['name'],
                'level' =>$validated['level'],
                'no_of_staff' =>$validated['no_of_staff'],
                'amount' =>$validated['amount'] ?? null,
                'requirements' =>$validated['requirements'],
            ]);

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' =>'Admin Created Tier',
                'details' => json_encode([
                    'created_finance_id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                ]),
                'type' => 'admin',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' =>'Tier created successfully.',
                'data' =>  $user
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Tier creation failed.',
                'error' =>  $e->getMessage()

            ], 500);
        }
    }

    private function isStaff(User $user): bool
    {
        return (
            $user->role === 'finance'
        );
    }

    private function isSupport(User $user): bool
    {
        return (
            $user->role === 'support'
        );
    }

    private function isCompany(User $user): bool
    {
        return (
            $user->role === 'company'
        );
    }

    private function canManageUsers(User $user): bool
    {
        return ($user->is_active == 1 &&
            (
                $this->isFullAdmin($user) ||
                $this->isStaff($user) || 
                $this->isSupport($user) ||
                $this->isCompany($user)
            )
        );
    }

    public function getTier( Request $request): JsonResponse {
        $admin = $request->user();

        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to view tier upgrade requests.'
            ], 403);
        }

        $search = $request->input('search');
        
        $query = Tier::query()
             ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('no_of_staff', 'LIKE', '%' . $search . '%')
                        ->orWhere('requirements', 'LIKE', '%' . $search . '%')
                        ->orWhere('level', 'LIKE', '%' . $search . '%');
                });
            })
            ->select(['id','name','level','no_of_staff','requirements','created_at']);

        $tier = $query
            ->latest()
            ->paginate($request->get('per_page',20 ));

        return response()->json([
            'status' => true,
            'message' =>'Staff fetched successfully.',
            'data' => $tier
        ]);
    }

    public function eachTier(Request $request , $id) {
        $admin = $request->user();

        if(!$this->isFullAdmin($admin)){
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $user = Tier::query()
            ->where('id', $id)
            ->select(['id','name','level','no_of_staff','amount','requirements','created_at','updated_at',
            ])
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Payfleet Tier not found.'
            ], 404);
        }

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'level' => $user->level,
            'no_of_staff' => $user->no_of_staff,
            'amount' => $user->amount,
            'requiremnts' => $user->requirements,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        return response()->json([
            'status' => true,
            'message' => 'Tier fetched successfully.',
            'data' => $data
        ]);
    }

    public function deleteTier(  Request $request,  $id): JsonResponse {
        $admin = $request->user();
        if (!$this->isFullAdmin($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Only an admin can permanently delete tiers.'
            ], 403);
        }

        $user = Tier::find($id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' =>   'tiers not found.'
            ], 404);
        }

        DB::beginTransaction();
        try {
            $userData = $user->toArray();

            $user->delete();

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' => 'Admin Deleted tier',
                'details' => json_encode([
                    'id' =>  $userData['id'],
                    'email' => $userData['email'] ?? null,
                    'role' => $userData['role'] ?? null,
                ]),
                'type' => 'admin',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Tier deleted successfully.'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Delete failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateTier(Request $request, $id): JsonResponse
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login again.'
            ], 401);
        }

        if (!$this->isFullAdmin($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Only an admin can update tiers.'
            ], 403);
        }

        $tier = Tier::find($id);

        if (!$tier) {
            return response()->json([
                'status' => false,
                'message' => 'Tier not found.'
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'level' => ['sometimes', 'required'],
            'no_of_staff' => ['sometimes', 'required', 'string'],
            'amount' => ['sometimes', 'nullable', 'string'],
            'requirements' => ['sometimes', 'required', 'string'],
        ]);

        DB::beginTransaction();

        try {

            $oldData = [
                'name' => $tier->name,
                'level' => $tier->level,
                'no_of_staff' => $tier->no_of_staff,
                'amount' => $tier->amount,
                'requirements' => $tier->requirements,
            ];

            $tier->update($validated);

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' => 'Admin Updated Tier',
                'details' => json_encode([
                    'tier_id' => $tier->id,
                    'old_data' => $oldData,
                    'updated_data' => [
                        'name' => $tier->name,
                        'level' => $tier->level,
                        'no_of_staff' => $tier->no_of_staff,
                        'amount' => $tier->amount,
                        'requirements' => $tier->requirements,
                    ],
                ]),
                'type' => 'admin',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Tier updated successfully.',
                'data' => $tier->fresh()
            ], 200);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Tier update failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
