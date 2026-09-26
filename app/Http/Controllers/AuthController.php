<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\Employees;
use App\Models\Notification;
use App\Models\Tier;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function Register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255|unique:companies,name',
            'email'    => 'required|email|unique:companies,email',
            'phone'    => 'required|string',
            'about'    => 'required|string',
            'address'  => 'required|string',
            'password' => 'required|string|min:8',
            'logo'     => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
        ]);
        $otp = random_int(100000, 999999);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('company_logos', 'public');
        }
        
        $user = User::create([
           'name'     => $request->name, 
           'email'    => $request->email,
           'phone'    => $request->phone,
           'password' => Hash::make($request->password),
           'otp'            => $otp,
            'otp_expires_at' => now()->addMinutes(20),
        ]);

        $company = Company::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'logo'     => $logoPath,
            'about'    => $request->about,
            'address'  => $request->address,
            'password' => Hash::make($request->password),
            'pin'      => Hash::make('1234'),
            'user_id'  => $user->id,
        ]);
         
        ActivityLog::insert([
            'user_id' => $user->id,
            'action' => 'Account Creation',
            'details' => 'Company account created successfully.',
            'type' => 'system',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Account Creation',
            'message' => 'Company Account created successfully.',
            'type' => 'system',
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
                        'subject' => 'Welcome to Payfleet - Verify Your Account',
                        'email_configuration_id' => config('services.termii.email_configuration_id'),
                        'template_id' => config('services.termii.registration_template'),

                        'variables' => [
                            'name' => $user->name,
                            'otp'        => (string) $otp,
                        ],
                    ]
                );

            Log::info('Termii Registration Template Response', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'template_id' => config('services.termii.registration_template'),
                'status'  => $response->status(),
                'body'    => $response->body(),
                'json'    => $response->json(),
            ]);

            if (!$response->successful()) {

                Log::error('Termii Registration Template Failed', [
                    'user_id' => $user->id,
                    'email'   => $user->email,
                    'template_id' => config('services.termii.registration_template'),
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);

                $user->delete();

                return response()->json([
                    'status'  => false,
                    'message' => 'Registration failed because we could not send the verification email.',
                ], 500);
            }

        } catch (\Throwable $e) {

            Log::error('Termii Registration Template Exception', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'template_id' => config('services.termii.registration_template'),
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            // Delete user if email sending throws an exception
            $user->delete();

            return response()->json([
                'status'  => false,
                'message' => 'Registration failed because we could not send the verification email.',
            ], 500);
        }

        return response()->json([
            'message' => 'Company registered successfully',
            'company' => $company
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::select(
            'id',
            'name',
            'email',
            'is_active',
            'password',
            'role',
            'is_verified'
        )
        ->where('email', $data['email'])
        ->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Incorrect email or password'
            ], 401);
        }

        if (!in_array($user->role, ['company', 'finance', 'admin', 'support'])) {
            return response()->json([
                'message' => 'Email is not authorized to login here'
            ], 403);
        }

        if (!$user->is_verified) {
            return response()->json([
                'message' => 'Please verify your email first'
            ], 403);
        }

        if (!$user->is_active) {
            return response()->json([
                'message' => 'Please activate your account'
            ], 403);
        }

        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        ActivityLog::insert([
            'user_id' => $user->id,
            'action' => 'Login',
            'details' => 'User logged into the application',
            'type' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Notification::create([
            'user_id' => $user->id,
            'title' => 'Login',
            'message' => 'User logged into the application',
            'type' => 'system',
        ]);

        return response()->json([
            'message' => 'Login successful',
            'data' => [
                'token' => $token,
                'user' => $user,
            ]
        ], 200);
    }

    public function verify(Request $request)
    {
        $request->validate([
            'otp' => 'required|digits:6',
        ]);

        $user = User::where('otp', $request->otp)->first();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid verification code.',
            ], 422);
        }

        if (!$user->otp_expires_at || now()->greaterThan($user->otp_expires_at)) {
            return response()->json([
                'status'  => false,
                'message' => 'Verification code has expired. Please request a new code.',
            ], 422);
        }

        $user->update([
            'is_verified'    => 1,
            'email_verified_at' => now(),
            'otp'            => null,
            'otp_expires_at' => null,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Email verified successfully. Your account is now active.',
            'data' => [
                'user_id'    => $user->id,
                'name' => $user->name,
                'email'  => $user->email,
                'is_verified'  => 1,
            ],
        ], 200);
    }

    public function resendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'User not found',
            ], 404);
        }

        if ($user->is_verified) {
            return response()->json([
                'status'  => false,
                'message' => 'Account is already verified',
            ], 422);
        }

        $otp = random_int(100000, 999999);
        $user->update([
            'otp'            => $otp,
            'otp_expires_at' => now()->addMinutes(20),
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
                        'subject' => 'Payfleet - Your New Verification Code',
                        'email_configuration_id' => config('services.termii.email_configuration_id'),
                        'template_id' => config('services.termii.resendotp_template'),
                        'variables' => [
                            'first_name' => $user->first_name,
                            'last_name'  => $user->last_name,
                            'otp'        => (string) $otp,
                        ],
                    ]
                );
            Log::info('Termii Resend OTP Template Response', [
                'user_id'     => $user->id,
                'email'       => $user->email,
                'template_id' => config('services.termii.resendotp_template'),
                'status'      => $response->status(),
                'body'        => $response->body(),
                'json'        => $response->json(),
            ]);

            if (!$response->successful()) {
                Log::error('Termii Resend OTP Template Failed', [
                    'user_id'     => $user->id,
                    'email'       => $user->email,
                    'template_id' => config('services.termii.resendotp_template'),
                    'status'      => $response->status(),
                    'body'        => $response->body(),
                ]);

                return response()->json([
                    'status'  => false,
                    'message' => 'We could not send the verification email. Please try again.',
                ], 500);
            }

        } catch (\Throwable $e) {
            Log::error('Termii Resend OTP Template Exception', [
                'user_id'     => $user->id,
                'email'       => $user->email,
                'template_id' => config('services.termii.resendotp_template'),
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'We could not send the verification email. Please try again.',
            ], 500);
        }

        return response()->json([
            'status'  => true,
            'message' => 'A new verification code has been sent to your email.',
        ], 200);
    }

    public function me(Request $request): JsonResponse
    {
        $user = User::with([
            'company.tierDetails'
        ])->find($request->user()->id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $response = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_verified' => $user->is_verified,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at,
        ];

        if ($user->company) {

            $tier = $user->company->tierDetails;

            $response['company_details'] = [
                'id' => $user->company->id,
                'name' => $user->company->name,
                'email' => $user->company->email,
                'tier' => $tier ? [
                    'id' => $tier->id,
                    'name' => $tier->name,
                    'level' => $tier->level,
                    'created_at' => $tier->created_at,
                ] : null,

                'phone' => $user->company->phone,

                'logo' => $user->company->logo
                    ? asset('storage/' . $user->company->logo)
                    : null,

                'about' => $user->company->about,
                'address' => $user->company->address,
                'created_at' => $user->company->created_at,
            ];
        }

        return response()->json([
            'status' => true,
            'message' => 'User fetched successfully',
            'data' => $response
        ], 200);
    }
    private function isCompany(User $user) :bool{
        return $user->role === 'company';
    }

    private function isFullAdmin(User $user) :bool
    {
        return $user->role === 'admin';
    }

    private function isFinace (User $user) : bool 
    {
        return $user->role === 'finance';
    }
    
    private function canManageUsers(User $user) : bool
    {
        return($user->is_active == 1 &&
            (
                $this->isFullAdmin($user) ||
                $this->isCompany($user)   ||
                $this->isFinace($user)
            )
        );
    }

    public function companyStats(Request $request)
    {
        $user = $request->user();

        if (!$this->canManageUsers($user)) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $totalCompanies = Company::count();
        $totalEmployees = Employees::count();
        $activeAccounts = User::count();

        $tiers =Tier::latest()->get();

        return response()->json([
            'message' => 'Dashboard stats',
            'total_companies' => $totalCompanies,
            'total_employees' => $totalEmployees,
            'active_account' => $activeAccounts,
            'tiers' => $tiers,
        ]);
    }

    public function staffStats(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        if ($user->role === 'admin') {
            $totalStaff = User::whereIn('role', [
                'finance',
                'support'
            ])->count();

            $financeOfficers = User::where('role', 'finance')->count();
            $supportOfficers = User::where('role', 'support')->count();

            $activeStaffAccounts = User::whereIn('role', [
                'finance',
                'support'
            ])
            ->where('is_active', true)
            ->count();

            return response()->json([
                'message' => 'Admin dashboard stats',
                'role' => 'admin',
                'total_staff' => $totalStaff,
                'finance_officers' => $financeOfficers,
                'support_officers' => $supportOfficers,
                'active_accounts' => $activeStaffAccounts,
            ]);
        }

        $company = Company::where('email', $user->email)->first();

        if (!$company) {
            return response()->json([
                'message' => 'Company not found',
            ], 404);
        }

        $totalEmployees = Employees::where(
            'company_id',
            $company->id
        )->count();

        $activeAccounts = User::where('is_active', true)->count();

        return response()->json([
            'message' => 'Company dashboard stats',
            'role' => 'company',
            'company_id' => $company->id,
            'company_name' => $company->name,
            'total_employees' => $totalEmployees,
            'active_accounts' => $activeAccounts,
        ]);
    }

    public function depositStats(Request $request)
    {
        $user = $request->user();

        if (!$this->canManageUsers($user)) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $totalDeposited = Transaction::where('transaction_type', 'deposit')
            ->sum('amount');

        $pendingApprovals = Transaction::where('transaction_type', 'deposit')
            ->where('status', 'pending')
            ->count();

        $pendingVolume = Transaction::where('transaction_type', 'deposit')
            ->where('status', 'pending')
            ->sum('amount');

        $settledDeposits = Transaction::where('transaction_type', 'deposit')
            ->where('status', 'successful')
            ->count();

        $declinedDeposits = Transaction::where('transaction_type', 'deposit')
            ->where('status', 'declined')
            ->count();

        return response()->json([
            'message' => 'Deposit stats',

            'total_deposited' => $totalDeposited,
            'pending_approvals' => $pendingApprovals,
            'pending_volume' => $pendingVolume,
            'settled_deposits' => $settledDeposits,
            'declined_deposits' => $declinedDeposits,
            ]);

    }
}
