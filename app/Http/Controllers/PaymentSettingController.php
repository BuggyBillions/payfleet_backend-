<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PaymentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentSettingController extends Controller
{
    private function isAdmin($user): bool
    {
        return $user->role === 'admin';   // or use your existing isFullAdmin() check
    }

    /** View the settings (any logged-in user) */
    public function show(Request $request): JsonResponse
    {
        if (!$request->user()) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated. Please login again.'], 401);
        }

        $setting = PaymentSetting::current();

        if (!$setting) {
            return response()->json([
                'status'  => false,
                'message' => 'Payment settings have not been created yet.',
            ], 404);
        }

        return response()->json(['status' => true, 'data' => $setting]);
    }

    /** Create the settings: allowed only once */
    public function store(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated. Please login again.'], 401);
        }

        if (!$this->isAdmin($admin)) {
            return response()->json(['status' => false, 'message' => 'Only an admin can create payment settings.'], 403);
        }

        if (PaymentSetting::exists()) {
            return response()->json([
                'status'  => false,
                'message' => 'Payment settings already exist. You can only edit them.',
            ], 409);
        }

        $validated = $request->validate([
            'vat'                    => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'federal_government_fee' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'payfleet_fee'           => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        DB::beginTransaction();
        try {
            $setting = PaymentSetting::create($validated + ['updated_by' => $admin->id]);

            ActivityLog::create([
                'user_id' => $admin->id,
                'action'  => 'Payment Settings Created',
                'details' => json_encode($validated),
                'type'    => 'admin',
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Payment settings created successfully.',
                'data'    => $setting,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            // A second row is blocked by the unique 'singleton' column even if two requests arrive together
            return response()->json([
                'status'  => false,
                'message' => 'Could not create payment settings.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /** Edit the existing settings */
    public function update(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json(['status' => false, 'message' => 'Unauthenticated. Please login again.'], 401);
        }

        if (!$this->isAdmin($admin)) {
            return response()->json(['status' => false, 'message' => 'Only an admin can edit payment settings.'], 403);
        }

        $validated = $request->validate([
            'vat'                    => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'federal_government_fee' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'payfleet_fee'           => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        if (empty($validated)) {
            return response()->json([
                'status'  => false,
                'message' => 'Send at least one of: vat, federal_government_fee, payfleet_fee.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $setting = PaymentSetting::lockForUpdate()->first();

            if (!$setting) {
                DB::rollBack();

                return response()->json([
                    'status'  => false,
                    'message' => 'Payment settings have not been created yet. Create them first.',
                ], 404);
            }

            $old = $setting->only(array_keys($validated));

            $setting->update($validated + ['updated_by' => $admin->id]);

            ActivityLog::create([
                'user_id' => $admin->id,
                'action'  => 'Payment Settings Updated',
                'details' => json_encode(['old' => $old, 'new' => $validated]),
                'type'    => 'admin',
            ]);

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Payment settings updated successfully.',
                'data'    => $setting->fresh(),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Could not update payment settings.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}