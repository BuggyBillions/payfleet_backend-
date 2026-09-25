<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Tier;
use App\Models\TierUpgradeRequest;

class CompanyProfileController extends Controller
{
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::select('id','name','email')
            ->where('email', $request->email)
            ->first();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'User not found.',
            ], 404);
        }

        PasswordResetToken::where('user_id', $user->id)->delete();

        $resetOtp = str_pad(
            random_int(0, 999999),
            6,
            '0',
            STR_PAD_LEFT
        );

        $token = Str::random(64);

        $expiresAt = now()->addMinutes(10);

        PasswordResetToken::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'reset_otp'  => $resetOtp,
            'expires_at' => $expiresAt,
        ]);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(30)
                ->post(
                    rtrim(config('services.termii.base_url'), '/') . '/api/templates/send-email',
                    [
                        'api_key' => config('services.termii.api_key'),
                        'email' => $user->email,
                        'subject' => 'Payfleet - Password Reset Code',
                        'email_configuration_id' => config('services.termii.email_configuration_id'),
                        'template_id' => config('services.termii.forget_template'),
                        'variables' => [
                            'first_name' => $user->first_name,
                            'last_name'  => $user->last_name,
                            'otp'        => (string) $resetOtp,
                        ],
                    ]
                );

            Log::info('Termii Forgot Password Template Response', [
                'user_id'     => $user->id,
                'email'       => $user->email,
                'template_id' => config('services.termii.forget_template'),
                'status'      => $response->status(),
                'body'        => $response->body(),
                'json'        => $response->json(),
            ]);

            if (!$response->successful()) {

                Log::error('Termii Forgot Password Email Failed', [
                    'user_id'     => $user->id,
                    'email'       => $user->email,
                    'template_id' => config('services.termii.forget_template'),
                    'status'      => $response->status(),
                    'body'        => $response->body(),
                ]);

                // Delete the reset record because email was not sent
                PasswordResetToken::where('user_id', $user->id)->delete();

                return response()->json([
                    'status'  => false,
                    'message' => 'We could not send the password reset code. Please try again.',
                ], 500);
            }

        } catch (\Throwable $e) {

            Log::error('Termii Forgot Password Template Exception', [
                'user_id'     => $user->id,
                'email'       => $user->email,
                'template_id' => config('services.termii.forget_template'),
                'error'       => $e->getMessage(),
            ]);

            // Delete the reset record because email was not sent
            PasswordResetToken::where('user_id', $user->id)->delete();

            return response()->json([
                'status'  => false,
                'message' => 'We could not send the password reset code. Please try again.',
            ], 500);
        }

        ActivityLog::create([
            'user_id' => $user->id,
            'action'  => 'Forgot Password',
            'details' => 'User requested a password reset code.',
            'type'    => 'security',
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title'   => 'Password Reset',
            'message' => 'A password reset code has been sent to your email.',
            'type'    => 'security',
        ]);


        return response()->json([
            'status'  => true,
            'message' => 'Password reset code sent successfully.',
            'data' => [
                'token'      => $token,
                'expires_at' => $expiresAt->toDateTimeString(),
            ],
        ], 200);
    }

    public function verifyResetOtp(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'reset_otp' => ['required', 'digits:6']
        ]);

        $reset = PasswordResetToken::where(
            'token',
            $request->token
        )->first();

        if (!$reset) {

            return response()->json([
                'status' => false,
                'message' => 'Invalid token.'
            ], 404);
        }

        if (now()->gt($reset->expires_at)) {

            $reset->delete();

            return response()->json([
                'status' => false,
                'message' => 'OTP has expired.'
            ], 422);
        }

        if ($reset->reset_otp !== $request->reset_otp) {

            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP.'
            ], 422);
        }

        $reset->update([
            'is_verified' => true
        ]);

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully.'
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'min:8']
        ]);

        $reset = PasswordResetToken::where(
            'token',
            $request->token
        )->first();

        if (!$reset) {

            return response()->json([
                'status' => false,
                'message' => 'Invalid token.'
            ], 404);
        }

        if (!$reset->is_verified) {

            return response()->json([
                'status' => false,
                'message' => 'Please verify your OTP first.'
            ], 422);
        }

        if (now()->gt($reset->expires_at)) {

            $reset->delete();

            return response()->json([
                'status' => false,
                'message' => 'Token has expired.'
            ], 422);
        }

        $user = User::find($reset->user_id);

        if (!$user) {

            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $user->update([
            'password' => Hash::make($request->password)
        ]);

        $user->tokens()->delete();

        ActivityLog::create([
            'user_id' => $user->id,
            'action' => 'Password Reset',
            'details' => 'User successfully reset account password.',
            'type' => 'security'
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Password Reset',
            'message' => 'Your password has been reset successfully.',
            'type' => 'security'
        ]);

        $reset->delete();

        return response()->json([
            'status' => true,
            'message' => 'Password reset successfully.'
        ]);
    }

    public function activityLogs(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = $request->get('per_page', 20);

        $logs = ActivityLog::select(
                'id',
                'action',
                'details',
                'type',
                'created_at'
            )
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Activity logs fetched successfully',
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'has_more_pages' => $logs->hasMorePages(),
            ]
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = $request->get('per_page', 20);

        $logs = Notification::select(
                'id',
                'title',
                'message',
                'type',
                'is_read',
                'created_at'
            )
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Notifications  fetched successfully',
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'has_more_pages' => $logs->hasMorePages(),
            ]
        ]);
    }

    public function markAsRead($id)
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', auth()->id())
            ->first();

        if (!$notification) {
            return response()->json([
                'status' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        $notification->update([
            'is_read' => true
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Notification marked as read'
        ]);
    }
    private function isFullCompany(User $user): bool
    {
        return $user->role === 'company';
    } 

    public function moveTier(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 401);
        }

        // Only company accounts can request an upgrade
        if (!$this->isFullCompany($user)) {
            return response()->json([
                'status' => false,
                'message' => 'Only company accounts can request a tier upgrade.'
            ], 403);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company not found.'
            ], 404);
        }

        $validated = $request->validate([
            'requested_tier' => [
                'required',
                'integer',
                'exists:tiers,id'
            ],
        ]);

        $currentTier = (int) $company->tier;
        $requestedTier = (int) $validated['requested_tier'];

        /*
        |--------------------------------------------------------------------------
        | Make sure they are actually moving forward
        |--------------------------------------------------------------------------
        */

        if ($requestedTier <= $currentTier) {
            return response()->json([
                'status' => false,
                'message' => 'You can only upgrade to a higher tier.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Check if there is already a pending request
        |--------------------------------------------------------------------------
        */

        $existingRequest = TierUpgradeRequest::where(
            'company_id',
            $company->id
        )
        ->where('status', 'pending')
        ->first();

        if ($existingRequest) {
            return response()->json([
                'status' => false,
                'message' => 'You already have a pending tier upgrade request.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Tier 2 Requirements
        |--------------------------------------------------------------------------
        */

        if ($requestedTier >= 2) {

            if (empty($company->bvn)) {
                return response()->json([
                    'status' => false,
                    'message' => 'BVN is required before upgrading to Tier 2.'
                ], 422);
            }

            if (empty($company->nin)) {
                return response()->json([
                    'status' => false,
                    'message' => 'NIN is required before upgrading to Tier 2.'
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Tier 3 Requirements
        |--------------------------------------------------------------------------
        */

        if ($requestedTier >= 3) {

            if (empty($company->memart)) {
                return response()->json([
                    'status' => false,
                    'message' => 'MEMART is required before upgrading to Tier 3.'
                ], 422);
            }

            if (empty($company->cac)) {
                return response()->json([
                    'status' => false,
                    'message' => 'CAC is required before upgrading to Tier 3.'
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Create upgrade request
        |--------------------------------------------------------------------------
        */

        $upgradeRequest = TierUpgradeRequest::create([
            'company_id' => $company->id,
            'current_tier' => $currentTier,
            'requested_tier' => $requestedTier,
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Tier upgrade request submitted successfully. Waiting for admin approval.',
            'data' => [
                'id' => $upgradeRequest->id,
                'company_id' => $company->id,
                'current_tier' => $currentTier,
                'requested_tier' => $requestedTier,
                'status' => $upgradeRequest->status,
            ]
        ], 201);
    }

    public function tierUpgradeRequests(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!$admin || $admin->role !== 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Only admins can view tier upgrade requests.'
            ], 403);
        }

        $requests = TierUpgradeRequest::with([
            'company',
            'currentTier',
            'requestedTier',
            'reviewer'
        ])
        ->latest()
        ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Tier upgrade requests fetched successfully.',
            'data' => $requests
        ]);
    }

    public function reviewTierUpgrade(Request $request, $id): JsonResponse
    {
        $admin = $request->user();

        if (!$admin || $admin->role !== 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Only admins can approve tier upgrades.'
            ], 403);
        }

        $validated = $request->validate([
            'action' => [
                'required',
                'in:approve,reject'
            ],
            'admin_note' => [
                'nullable',
                'string',
                'max:1000'
            ],
        ]);

        $upgradeRequest = TierUpgradeRequest::with('company')
            ->find($id);

        if (!$upgradeRequest) {
            return response()->json([
                'status' => false,
                'message' => 'Tier upgrade request not found.'
            ], 404);
        }

        if ($upgradeRequest->status !== 'pending') {
            return response()->json([
                'status' => false,
                'message' => 'This tier upgrade request has already been reviewed.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Reject
        |--------------------------------------------------------------------------
        */

        if ($validated['action'] === 'reject') {

            $upgradeRequest->update([
                'status' => 'rejected',
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'admin_note' => $validated['admin_note'] ?? null,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Tier upgrade request rejected successfully.',
                'data' => $upgradeRequest
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Approve
        |--------------------------------------------------------------------------
        */

        $company = $upgradeRequest->company;

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company attached to this request was not found.'
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Check documents again before approval
        |--------------------------------------------------------------------------
        | This is important because the company may have changed
        | or removed documents after submitting the request.
        |--------------------------------------------------------------------------
        */

        if ($upgradeRequest->requested_tier >= 2) {

            if (empty($company->bvn)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot approve. Company BVN is missing.'
                ], 422);
            }

            if (empty($company->nin)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot approve. Company NIN is missing.'
                ], 422);
            }
        }

        if ($upgradeRequest->requested_tier >= 3) {

            if (empty($company->memart)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot approve. Company MEMART is missing.'
                ], 422);
            }

            if (empty($company->cac)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot approve. Company CAC is missing.'
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Make sure the company's tier has not changed
        |--------------------------------------------------------------------------
        */

        if ((int) $company->tier !== (int) $upgradeRequest->current_tier) {

            return response()->json([
                'status' => false,
                'message' => 'Company tier has changed since this request was created. Please submit a new request.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Update company tier
        |--------------------------------------------------------------------------
        */

        $company->update([
            'tier' => $upgradeRequest->requested_tier
        ]);

        /*
        |--------------------------------------------------------------------------
        | Mark request as approved
        |--------------------------------------------------------------------------
        */

        $upgradeRequest->update([
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_note' => $validated['admin_note'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Tier upgrade approved successfully.',
            'data' => [
                'company_id' => $company->id,
                'previous_tier' => $upgradeRequest->current_tier,
                'new_tier' => $company->tier,
                'status' => 'approved',
            ]
        ]);
    }

    public function myCompanyStats(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        if (!$this->isFullCompany($user)) {
            return response()->json([
                'status' => false,
                'message' => 'Only company accounts can access this endpoint.'
            ], 403);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company not found.'
            ], 404);
        }
        $noOfEmployees = Employee::where(
            'company_id',
            $company->id
        )->count();

        $balance = $company->balance ?? 0;

        $estimatedSalary = Employee::where(
            'company_id',
            $company->id
        )->sum('estimate_pay');
        
        $totalPaid = 0;

        return response()->json([
            'status' => true,
            'message' => 'Company statistics fetched successfully.',
            'data' => [
                'company_id' => $company->id,
                'company_name' => $company->name,
                'no_of_employee' => $noOfEmployees,
                'estimated_salary' => $estimatedSalary,
                'balance' => $balance,
                'total_paid' => $totalPaid,
            ]
        ], 200);
    }
}
