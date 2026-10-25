<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Company;
use App\Models\Employees;
use App\Models\Notification;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class AdminActionsController extends Controller
{   
    public function getCompanies(Request $request)
    {
        $admin = $request->user();

        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $search = $request->input('search');

        $companies = Company::with([
                'user',
                'tier',
                'employees'
            ])
            ->withCount(['employees as no_of_employee'])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('email', 'LIKE', '%' . $search . '%')
                        ->orWhere('phone', 'LIKE', '%' . $search . '%')
                        ->orWhere('address', 'LIKE', '%' . $search . '%');
                });
            })
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'status'  => true,
            'message' => 'Companies fetched successfully',
            'data'    => $companies
        ], 200);
    }

    public function eachCompanies(Request $request , $id) 
    {
        $admin = $request->user();

        if(!$this->canManageUsers($admin)){
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $companies = Company::with(['user', 'tier'])
            ->where('id', $id)
            ->withCount(['employees as no_of_employee'])
            ->first();

        if (!$companies) {
            return response()->json([
                'status' => false,
                'message' => 'Payfleet Staff not found.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'User fetched successfully.',
            'data' => $companies
        ]);
    }

    private function isFullAdmin(User $user): bool
    {
        return $user->role === 'admin';
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

    private function canManageUsers(User $user): bool
    {
        return ($user->is_active == 1 &&
            (
                $this->isFullAdmin($user) ||
                $this->isStaff($user) || 
                $this->isSupport($user)
            )
        );
    }

    public function createUser(Request $request): JsonResponse
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
                'message' => 'Only an admin can create Financial Officer.'
            ], 403);
        }

        $validated = $request->validate([
            'name' => [ 'required', 'string', 'max:255','unique:users,name'],
            'email' => [ 'required','email','max:255','unique:users,email'],
            'phone' => [ 'required', 'string', 'max:50', 'unique:users,phone'],
            'password' => [ 'required','string','min:8'],
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' =>$validated['name'],
                'email' =>$validated['email'],
                'phone' =>$validated['phone'],
                'password' =>Hash::make($validated['password']),
                'role' =>'finance',
                'is_verified' =>1,
                'is_active' =>1,
                'balance' => 0,
            ]);

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' =>'Admin Created Financial officer',
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
                'message' =>'Financial officer created successfully.',
                'data' =>  $user
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Financial officer creation failed.',
                'error' =>  $e->getMessage()

            ], 500);
        }
    }

    public function createSupportUser(Request $request): JsonResponse
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
                'message' => 'Only an admin can create Support Officer.'
            ], 403);
        }

        $validated = $request->validate([
            'name' => [ 'required', 'string', 'max:255','unique:users,name'],
            'email' => [ 'required','email','max:255','unique:users,email'],
            'phone' => [ 'required', 'string', 'max:50', 'unique:users,phone'],
            'password' => [ 'required','string','min:8'],
        ]);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' =>$validated['name'],
                'email' =>$validated['email'],
                'phone' =>$validated['phone'],
                'password' =>Hash::make($validated['password']),
                'role' =>'support',
                'is_verified' =>1,
                'is_active' =>1,
                'balance' => 0,
            ]);

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' =>'Admin Created Support officer',
                'details' => json_encode([
                    'created_support_id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                ]),
                'type' => 'admin',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' =>'Support officer created successfully.',
                'data' =>  $user
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Support officer creation failed.',
                'error' =>  $e->getMessage()

            ], 500);
        }
    }

    public function getStaff(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!$this->isFullAdmin($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $search = $request->input('search');
        $role = $request->input('role', 'all');

        $query = User::query()
            ->whereIn('role', ['finance', 'support'])

            ->when($role !== 'all', function ($query) use ($role) {
                $query->where('role', $role);
            })

            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('email', 'LIKE', '%' . $search . '%')
                        ->orWhere('phone', 'LIKE', '%' . $search . '%')
                        ->orWhere('role', 'LIKE', '%' . $search . '%')
                        ->orWhere('is_active', 'LIKE', '%' . $search . '%');
                });
            })

            ->select([
                'id',
                'name',
                'email',
                'phone',
                'role',
                'is_verified',
                'is_active',
                'created_at'
            ]);

        $staff = $query
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'status' => true,
            'message' => 'Staff fetched successfully.',
            'data' => $staff
        ]);
    }

    public function eachStaff(Request $request , $id) 
    {
        $admin = $request->user();

        if(!$this->isFullAdmin($admin)){
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $user = User::query()
            ->where('id', $id)
            ->where('role', '!=', 'admin')
            ->select(['id','name','email','phone','role','is_verified','is_active','created_at','updated_at',
            ])
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Payfleet Staff not found.'
            ], 404);
        }

        $data = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'is_verified' => $user->is_verified,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];

        return response()->json([
            'status' => true,
            'message' => 'User fetched successfully.',
            'data' => $data
        ]);
    }

    public function deactivateUser(  Request $request,   $id): JsonResponse 
    {
        $admin = $request->user();

        if (!$this->isFullAdmin($admin)) {
            return response()->json([
                'status' => false,
                'message' =>'Unauthorized.'
            ], 403);
        }

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' =>  'User not found.'
            ], 404);
        }

        if ($user->id === $admin->id) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot deactivate your own account.'
            ], 403);
        }

        $user->update([
            'is_active' => 0
        ]);

        $user->tokens()->delete();
        ActivityLog::create([
            'user_id' =>   $admin->id,
            'action' =>  'Admin Deactivated User',
            'details' =>"Deactivated user ID {$user->id}",
            'type' =>'admin',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'User deactivated successfully.'
        ]);
    }

    public function activateUser(  Request $request,   $id): JsonResponse 
    {
        $admin = $request->user();
        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status' => false,
                'message' =>  'Unauthorized.'
            ], 403);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.'
            ], 404);
        }

        $user->update([
            'is_active' => true
        ]);

        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'Admin Activated User',
            'details' => "Activated user ID {$user->id}",
            'type' => 'admin',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'User activated successfully.'
        ]);
    } 

    public function deleteUser(  Request $request,  $id): JsonResponse 
    {
        $admin = $request->user();
        if (!$this->isFullAdmin($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Only an admin can permanently delete users.'
            ], 403);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' =>   'User not found.'
            ], 404);
        }

        if ($user->id === $admin->id) {
            return response()->json([
                'status' => false,
                'message' =>  'You cannot delete your own account.'
            ], 403);
        }

        if ($user->role === 'admin') {
            return response()->json([
                'status' => false,
                'message' =>  'Admin accounts cannot be deleted.'
            ], 403);
        }

        DB::beginTransaction();
        try {
            $userData = $user->toArray();

            $user->tokens()->delete();
            $user->delete();

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' => 'Admin Deleted User',
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
                'message' => 'User deleted successfully.'
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

    public function updateCompanyProfile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 401);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company account not found.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'          => 'nullable|string|max:255',
            'email'         => 'nullable|email|max:255|unique:companies,email,' . $company->id,
            'phone'         => 'nullable|string|max:50|unique:companies,phone,' . $company->id,
            'about'         => 'nullable|string',
            'address'       => 'nullable|string',
            'pin'           => 'nullable|string|size:4',
            'password'      => 'nullable|string|min:8',
            'logo'          => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'bvn'           => 'nullable|integer',
            'nin'           => 'nullable|integer',
            'cac'           => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'mermat'        => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'status_report' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {

            $companyUpdateData = [];
            $userUpdateData = [];

            if ($request->has('name')) {
                $companyUpdateData['name'] = $request->name;
            }

            if ($request->has('email')) {
                $companyUpdateData['email'] = $request->email;
                $userUpdateData['email'] = $request->email;
            }

            if ($request->has('phone')) {
                $companyUpdateData['phone'] = $request->phone;
            }

            if ($request->has('about')) {
                $companyUpdateData['about'] = $request->about;
            }

            if ($request->has('address')) {
                $companyUpdateData['address'] = $request->address;
            }

            if ($request->has('bvn')) {
                $companyUpdateData['bvn'] = $request->bvn;
            }

            if ($request->has('nin')) {
                $companyUpdateData['nin'] = $request->nin;
            }

            if ($request->has('pin')) {
                $companyUpdateData['pin'] = Hash::make($request->pin);
            }

            if ($request->has('password')) {
                $userUpdateData['password'] = Hash::make($request->password);
            }

            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')
                    ->store('company_logos', 'public');

                $companyUpdateData['logo'] = $logoPath;
            }

            if ($request->hasFile('cac')) {
                $cacPath = $request->file('cac')
                    ->store('company_cacs', 'public');

                $companyUpdateData['cac'] = $cacPath;
            }

            if ($request->hasFile('mermat')) {
                $mermatPath = $request->file('mermat')
                    ->store('company_mermats', 'public');

                $companyUpdateData['mermat'] = $mermatPath;
            }

            if ($request->hasFile('status_report')) {
                $statusPath = $request->file('status_report')
                    ->store('company_status_reports', 'public');

                $companyUpdateData['status_report'] = $statusPath;
            }

            if (!empty($companyUpdateData)) {
                $company->update($companyUpdateData);
            }

            if (!empty($userUpdateData)) {
                $user->update($userUpdateData);
            }

            DB::commit();

            $company->refresh();
            $user->refresh();

            return response()->json([
                'status' => true,
                'message' => 'Company profile updated successfully.',
                'data' => [
                    'company' => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'email' => $company->email,
                        'phone' => $company->phone,
                        'about' => $company->about,
                        'address' => $company->address,

                        'logo' => $company->logo
                            ? asset('storage/' . $company->logo)
                            : null,

                        'cac' => $company->cac
                            ? asset('storage/' . $company->cac)
                            : null,

                        'mermat' => $company->mermat
                            ? asset('storage/' . $company->mermat)
                            : null,

                        'status_report' => $company->status_report
                            ? asset('storage/' . $company->status_report)
                            : null,

                        'bvn' => $company->bvn,
                        'nin' => $company->nin,
                        'balance' => $company->balance,
                    ],

                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Company profile update failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateCompanyPassword(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 401);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company account not found.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'current_password'          => 'required|string',
            'new_password'              => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Current password is incorrect.'
            ], 422);
        }

        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'New password must be different from your current password.'
            ], 422);
        }

        try {

            $user->password = Hash::make($request->new_password);
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'Password updated successfully.'
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'Password update failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateCompanyPin(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 401);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company account not found.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'current_pin'      => 'required|string|size:4',
            'new_pin'          => 'required|string|size:4',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if (empty($company->pin) || !Hash::check($request->current_pin, $company->pin)) {
            return response()->json([
                'status' => false,
                'message' => 'Current PIN is incorrect.'
            ], 422);
        }

        if (Hash::check($request->new_pin, $company->pin)) {
            return response()->json([
                'status' => false,
                'message' => 'New PIN must be different from your current PIN.'
            ], 422);
        }

        try {

            $company->pin = Hash::make($request->new_pin);
            $company->save();

            return response()->json([
                'status' => true,
                'message' => 'PIN updated successfully.'
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => 'PIN update failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function createAccount(Request $request)
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
                'message' => 'Only an admin can create or update bank account.'
            ], 403);
        }

        $validated = $request->validate([
            'account_number' => ['required', 'string', 'max:15'],
            'bank_name' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
        ]);

        DB::beginTransaction();

        try {
            $account = Account::first();

            if ($account) {

                $account->update([
                    'account_number' => $validated['account_number'],
                    'bank_name' => $validated['bank_name'],
                    'account_name' => $validated['account_name'],
                ]);

                $action = 'Admin Updated Bank Account';
                $message = 'Bank Account updated successfully.';
                $statusCode = 200;

            } else {
                $account = Account::create([
                    'account_number' => $validated['account_number'],
                    'bank_name' => $validated['bank_name'],
                    'account_name' => $validated['account_name'],
                ]);

                $action = 'Admin Created Bank Account';
                $message = 'Bank Account created successfully.';
                $statusCode = 201;
            }

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' => $action,
                'details' => json_encode([
                    'account_id' => $account->id,
                    'account_number' => $account->account_number,
                    'bank_name' => $account->bank_name,
                    'account_name' => $account->account_name,
                ]),
                'type' => 'admin',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => $message,
                'data' => $account
            ], $statusCode);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Bank Account operation failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAccount( Request $request): JsonResponse 
    {
        $query = Account::query()
            ->select(['id','account_name','account_number','bank_name','created_at']);

        $account = $query
            ->latest()
            ->paginate($request->get('per_page',20 ));

        return response()->json([
            'status' => true,
            'message' =>'Account fetched successfully.',
            'data' => $account
        ]);
    }

    public function confirmDeposit(Request $request, $id)
    {
        $admin = $request->user();

        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        DB::beginTransaction();

        try {
            $transaction = Transaction::find($id);
            if (!$transaction) {
                DB::rollBack();

                return response()->json([
                    'status'  => false,
                    'message' => 'Transaction not found.'
                ], 404);
            }

            if ($transaction->status === 'successful') {
                DB::rollBack();

                return response()->json([
                    'status'  => false,
                    'message' => 'This deposit has already been confirmed.'
                ], 400);
            }

            $company = Company::find($transaction->company_id);

            if (!$company) {
                DB::rollBack();

                return response()->json([
                    'status'  => false,

                    'message' => 'Company not found.'
                ], 404);
            }

            $previousBalance = $company->balance ?? 0;
            $amount = $transaction->amount;
            $newBalance = $previousBalance + $amount;
            $company->update([
                'balance' => $newBalance
            ]);

            $userId = $company->user_id;

            ActivityLog::create([
                'user_id' => $userId,
                'action'  => 'Deposit Confirmed',
                'details' => "Deposit of ₦{$amount} confirmed successfully. Reference: {$transaction->reference}. New balance: ₦{$newBalance}",
                'type'    => 'transaction',
            ]);

            Notification::create([
                'user_id' => $userId,
                'title'   => 'Deposit Successful',
                'message' => "Your deposit of ₦{$amount} has been confirmed successfully. Your new balance is ₦{$newBalance}.",
                'type'    => 'transaction',
                'is_read' => false,
            ]);

            $transaction->update([
                'status'          => 'successful',
                'previous_balance' => $previousBalance,
                'current_balance'  => $newBalance,
            ]);
        
            
            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Deposit confirmed successfully.',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'amount' => $amount,
                    'previous_balance' => $previousBalance,
                    'current_balance' => $newBalance,
                    'transaction_status' => 'successful',
                    'company' => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'balance' => $company->balance,
                    ],
                ]
            ], 200);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Failed to confirm deposit.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    
    public function handle(Request $request)
    {
        $rawBody = $request->getContent();

        /*
        |--------------------------------------------------------------------------
        | 1. Verify Moniepoint webhook authentication
        |--------------------------------------------------------------------------
        */

        $webhookId = $request->header('moniepoint-webhook-id');
        $timestamp = $request->header('moniepoint-webhook-timestamp');
        $signature = $request->header('moniepoint-webhook-signature');

        $secret = config('services.moniepoint.webhook_secret');

        if (!$secret || !$webhookId || !$timestamp || !$signature) {
            Log::warning('Moniepoint webhook: authentication headers missing.');

            return response()->json([
                'status' => false,
                'message' => 'Missing webhook authentication headers.',
            ], 401);
        }

        if (!ctype_digit((string) $timestamp)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid webhook timestamp.',
            ], 401);
        }

        $timestampMs = (int) $timestamp;
        $nowMs = (int) floor(microtime(true) * 1000);

        if (abs($nowMs - $timestampMs) > 300000) {
            return response()->json([
                'status' => false,
                'message' => 'Webhook timestamp expired.',
            ], 401);
        }

        $signedData = $webhookId . '__' . $timestamp . '__' . $rawBody;

        $expectedSignature = base64_encode(
            hash_hmac('sha256', $signedData, $secret, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Moniepoint webhook: invalid signature.', [
                'webhook_id' => $webhookId,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Decode the authenticated event
        |--------------------------------------------------------------------------
        */

        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid JSON payload.',
            ], 400);
        }

        $eventId = $payload['eventId'] ?? null;
        $eventType = $payload['eventType'] ?? null;
        $data = $payload['data'] ?? [];

        Log::info('Moniepoint authenticated webhook received.', [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'transaction_reference' =>
                $data['transactionReference'] ?? null,
            'merchant_reference' =>
                $data['merchantReference'] ?? null,
            'amount_kobo' => $data['amount'] ?? null,
            'transaction_status' =>
                $data['transactionStatus'] ?? null,
        ]);

        if (!in_array($eventType, [
            'V1_POS_TRANSFER_TRANSACTION',
            'V1_TRANSFER_TRANSACTION',
        ], true)) {
            return response()->json([
                'status' => true,
                'message' => 'Event ignored.',
            ], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Verify payment status
        |--------------------------------------------------------------------------
        */

        $paymentStatus = strtoupper(
            (string) ($data['transactionStatus'] ?? '')
        );

        $responseCode = (string) ($data['responseCode'] ?? '');

        if (
            !in_array($paymentStatus, [
                'COMPLETED',
                'APPROVED',
                'SUCCESSFUL',
            ], true) ||
            !in_array($responseCode, ['20000', '00'], true)
        ) {
            Log::warning('Moniepoint payment was not successful.', [
                'event_id' => $eventId,
                'status' => $paymentStatus,
                'response_code' => $responseCode,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Payment is not successful.',
            ], 200);
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Validate the payment amount and external reference
        |--------------------------------------------------------------------------
        */

        $amountKobo = $data['amount'] ?? null;

        if (
            !is_numeric($amountKobo) ||
            (float) $amountKobo <= 0 ||
            (float) $amountKobo != (int) $amountKobo
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid payment amount.',
            ], 422);
        }

        $amountKobo = (int) $amountKobo;

        $moniepointReference =
            $data['transactionReference'] ?? null;

        $merchantReference =
            $data['merchantReference'] ?? null;

        if (!$moniepointReference) {
            Log::warning('Moniepoint event has no transaction reference.', [
                'event_id' => $eventId,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Missing Moniepoint transaction reference.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Match and credit the deposit atomically
        |--------------------------------------------------------------------------
        */

        try {
            $result = DB::transaction(function () use (
                $amountKobo,
                $moniepointReference,
                $merchantReference,
                $eventId
            ) {
                /*
                * A transaction reference may only be credited once.
                */
                $alreadyProcessed = Transaction::where(
                    'moniepoint_reference',
                    $moniepointReference
                )->lockForUpdate()->first();

                if ($alreadyProcessed) {
                    return [
                        'http' => 200,
                        'status' => true,
                        'message' => 'Moniepoint transaction already processed.',
                    ];
                }

                /*
                * If Moniepoint supplied a Payfleet reference, use it.
                * Otherwise, find pending deposits whose expected payment
                * exactly matches the received amount.
                */
                if ($merchantReference) {
                    $transaction = Transaction::where(
                        'reference',
                        $merchantReference
                    )
                        ->where('transaction_type', 'deposit')
                        ->where('type', 'CREDIT')
                        ->lockForUpdate()
                        ->first();

                    if (!$transaction) {
                        Log::warning(
                            'Payfleet deposit reference not found.',
                            [
                                'event_id' => $eventId,
                                'merchant_reference' => $merchantReference,
                            ]
                        );

                        return [
                            'http' => 200,
                            'status' => false,
                            'message' => 'Deposit reference not found; no wallet credited.',
                        ];
                    }
                } else {
                    /*
                    * The configured fee is the extra amount the company
                    * is expected to pay Payfleet, not Moniepoint's fee.
                    *
                    * Example:
                    * Deposit amount = ₦100
                    * Payfleet deposit fee = ₦100
                    * Expected incoming payment = ₦200
                    */
                    $depositFee = (float) config(
                        'services.moniepoint.deposit_fee',
                        100
                    );

                    $pendingTransactions = Transaction::where(
                        'transaction_type',
                        'deposit'
                    )
                        ->where('type', 'CREDIT')
                        ->where('status', 'pending')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    $matches = $pendingTransactions->filter(
                        function ($item) use ($amountKobo, $depositFee) {
                            $expectedKobo = (int) round(
                                (
                                    (float) $item->amount
                                    + $depositFee
                                ) * 100
                            );

                            return $expectedKobo === $amountKobo;
                        }
                    );

                    if ($matches->count() !== 1) {
                        Log::warning(
                            'Moniepoint deposit could not be matched uniquely.',
                            [
                                'event_id' => $eventId,
                                'moniepoint_reference' =>
                                    $moniepointReference,
                                'amount_kobo' => $amountKobo,
                                'matching_deposits' => $matches->count(),
                            ]
                        );

                        return [
                            'http' => 200,
                            'status' => false,
                            'message' =>
                                'Payment received but could not be matched uniquely. No wallet credited.',
                        ];
                    }

                    $transaction = $matches->first();
                }

                /*
                * Only pending Payfleet deposits can be credited.
                */
                if (
                    $transaction->status !== 'pending' ||
                    $transaction->transaction_type !== 'deposit' ||
                    strtoupper((string) $transaction->type) !== 'CREDIT'
                ) {
                    return [
                        'http' => 200,
                        'status' => false,
                        'message' => 'Transaction is not an eligible pending deposit.',
                    ];
                }

                /*
                * Check the amount even when merchantReference is present.
                */
                $depositAmountKobo = (int) round(
                    (float) $transaction->amount * 100
                );

                $feeKobo = (int) round(
                    (float) config(
                        'services.moniepoint.deposit_fee',
                        100
                    ) * 100
                );

                $expectedKobo = $depositAmountKobo + $feeKobo;

                if ($amountKobo !== $expectedKobo) {
                    Log::warning('Moniepoint deposit amount mismatch.', [
                        'event_id' => $eventId,
                        'payfleet_reference' => $transaction->reference,
                        'expected_kobo' => $expectedKobo,
                        'received_kobo' => $amountKobo,
                    ]);

                    return [
                        'http' => 200,
                        'status' => false,
                        'message' => 'Payment amount mismatch. No wallet credited.',
                    ];
                }

                /*
                * Lock the company before changing its balance.
                */
                $company = Company::where(
                    'id',
                    $transaction->company_id
                )->lockForUpdate()->first();

                if (!$company) {
                    return [
                        'http' => 200,
                        'status' => false,
                        'message' => 'Company not found. No wallet credited.',
                    ];
                }

                /*
                * Credit only the intended deposit amount.
                * Moniepoint's own transaction fee is separate.
                */
                $previousBalance = (float) ($company->balance ?? 0);
                $creditAmount = $depositAmountKobo / 100;

                $newBalance = round(
                    $previousBalance + $creditAmount,
                    2
                );

                $company->balance = $newBalance;
                $company->save();

                /*
                * Mark the deposit as successful and store the real
                * Moniepoint transaction reference for duplicate protection.
                */
                $transaction->moniepoint_reference =
                    $moniepointReference;
                $transaction->status = 'successful';
                $transaction->previous_balance = $previousBalance;
                $transaction->current_balance = $newBalance;
                $transaction->save();

                ActivityLog::create([
                    'user_id' => $company->user_id,
                    'action' => 'Deposit Confirmed',
                    'details' =>
                        'Moniepoint deposit of ₦' .
                        number_format($creditAmount, 2) .
                        ' confirmed. Payfleet reference: ' .
                        $transaction->reference .
                        '. Moniepoint reference: ' .
                        $moniepointReference .
                        '. New balance: ₦' .
                        number_format($newBalance, 2),
                    'type' => 'transaction',
                ]);

                Notification::create([
                    'user_id' => $company->user_id,
                    'title' => 'Deposit Successful',
                    'message' =>
                        'Your deposit of ₦' .
                        number_format($creditAmount, 2) .
                        ' has been confirmed. Your new balance is ₦' .
                        number_format($newBalance, 2) . '.',
                    'type' => 'transaction',
                    'is_read' => false,
                ]);

                return [
                    'http' => 200,
                    'status' => true,
                    'message' => 'Deposit confirmed and wallet credited.',
                    'reference' => $transaction->reference,
                    'moniepoint_reference' => $moniepointReference,
                    'amount_credited' => $creditAmount,
                    'previous_balance' => $previousBalance,
                    'current_balance' => $newBalance,
                ];
            }, 3);

            return response()->json([
                'status' => $result['status'],
                'message' => $result['message'],
                'data' => array_intersect_key(
                    $result,
                    array_flip([
                        'reference',
                        'moniepoint_reference',
                        'amount_credited',
                        'previous_balance',
                        'current_balance',
                    ])
                ),
            ], $result['http']);

        } catch (\Throwable $e) {
            Log::error('Moniepoint deposit processing failed.', [
                'moniepoint_reference' => $moniepointReference,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to process Moniepoint deposit.',
            ], 500);
        }
    }
    
    public function declineDeposit(Request $request, $id)
    {
        $admin = $request->user();

        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $validated = $request->validate([
            'description' => [ 'required'],
        ]);

        DB::beginTransaction();

        try {
            $transaction = Transaction::find($id);
            if (!$transaction) {
                DB::rollBack();

                return response()->json([
                    'status'  => false,
                    'message' => 'Transaction not found.'
                ], 404);
            }

            if ($transaction->status === 'successful') {
                DB::rollBack();

                return response()->json([
                    'status'  => false,
                    'message' => 'This deposit has already been confirmed.'
                ], 400);
            }

            $company = Company::find($transaction->company_id);

            if (!$company) {
                DB::rollBack();

                return response()->json([
                    'status'  => false,

                    'message' => 'Company not found.'
                ], 404);
            }

            $previousBalance = $company->balance ?? 0;
            $amount = $transaction->amount;
    
            $userId = $company->user_id;

            ActivityLog::create([
                'user_id' => $userId,
                'action'  => 'Deposit Declined',
                'details' => "Deposit of ₦{$amount} declined successfully. Reference: {$transaction->reference}",
                'type'    => 'transaction',
            ]);

            Notification::create([
                'user_id' => $userId,
                'title'   => 'Deposit Declined',
                'message' => "Your deposit of ₦{$amount} has been declined",
                'type'    => 'transaction',
                'is_read' => false,
            ]);

            $transaction->update([
                'status'          => 'declined',
                'description'     =>  $validated['description']
            ]);
        
            
            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Deposit declined.',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'amount' => $amount,
                    'transaction_status' => 'declined',
                    'company' => [
                        'id' => $company->id,
                        'name' => $company->name,
                        'balance' => $company->balance,
                    ],
                ]
            ], 200);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => false,
                'message' => 'Failed to confirm deposit.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function updateOfficer(Request $request, $id): JsonResponse
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
                'message' => 'Only an admin can update Finance or Support Officers.'
            ], 403);
        }

        $user = User::whereIn('role', ['finance', 'support'])
            ->where('id', $id)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Finance or Support Officer not found.'
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes','required','string','max:255','unique:users,name,' . $user->id],
            'email' => ['sometimes','required','email','max:255','unique:users,email,' . $user->id],
            'phone' => ['sometimes','required','string','max:50','unique:users,phone,' . $user->id],
            'password' => ['sometimes','nullable','string','min:8'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::beginTransaction();

        try {
            $oldData = [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'is_active' => $user->is_active,
            ];

            if (isset($validated['name'])) {
                $user->name = $validated['name'];
            }

            if (isset($validated['email'])) {
                $user->email = $validated['email'];
            }

            if (isset($validated['phone'])) {
                $user->phone = $validated['phone'];
            }

            if (isset($validated['is_active'])) {
                $user->is_active = $validated['is_active'];
            }

            if (!empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            $user->save();

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' => 'Admin Updated Officer',
                'details' => json_encode([
                    'updated_user_id' => $user->id,
                    'role' => $user->role,
                    'old_data' => $oldData,
                    'updated_data' => [
                        'name' => $user->name,
                        'email' => $user->email,
                        'phone' => $user->phone,
                        'is_active' => $user->is_active,
                        'password_changed' => !empty($validated['password']),
                    ],
                ]),
                'type' => 'admin',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => ucfirst($user->role) . ' officer updated successfully.',
                'data' => $user
            ], 200);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Officer update failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function adminActivityLogs(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login again.'
            ], 401);
        }

        $query = ActivityLog::query();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('action', 'like', "%{$request->search}%")
                ->orWhere('details', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('created_at', [
                $request->from,
                $request->to
            ]);
        }
        
        $logs = $query
            ->with('user:id,name,phone,email,role')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Activity logs fetched successfully',
            'data' => $logs
        ]);
    }

    public function adminNotifications(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login again.'
            ], 401);
        }

        $query = Notification::query();

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                ->orWhere('message', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('created_at', [
                $request->from,
                $request->to
            ]);
        }
        
        $logs = $query
            ->with('user:id,name,phone,email,role')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Notificatiions fetched successfully',
            'data' => $logs
        ]);
    }

    public function getSingleActivity(Request $request, $id): JsonResponse
    {
        $admin = $request->user();
        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login again.'
            ], 401);
        }

        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $activity = ActivityLog::query()
            ->where('id', $id)
            ->with([ 'user:id,name,email,phone,role']);

        $activity = $activity->first();

        if (!$activity) {
            return response()->json([
                'status' => false,
                'message' => 'Activity not found.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Activity fetched successfully.',
            'data' => $activity
        ], 200);
    }

    public function getSingleNotification(Request $request, $id): JsonResponse
    {
        $admin = $request->user();
        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login again.'
            ], 401);
        }

        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $notification = Notification::query()
            ->where('id', $id)
            ->with([ 'user:id,name,email,phone,role']);


        $notification = $notification->first();

        if (!$notification) {
            return response()->json([
                'status' => false,
                'message' => 'Activity not found.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Notification fetched successfully.',
            'data' => $notification
        ], 200);
    }

    public function updateAdminPassword(Request $request)
    {
        $user = $request->user();

        if(!$user) {
            return response()->json([
                'status'     =>    false,
                'message'    =>    'Unathenticated'
            ]);
        }

        $validator  = validator::make($request->all(), [
            'name'       =>    'nullable|string|max:225',
            'email'      =>    'nullable|string|max:50|unique:users,email',
            'phone'      =>    'nullable|string|max:50|unique:users,phone',
            'password'   =>    'nullable|string|min:8'
        ]);

        if ($validator->fails()){
            return response()->json([
                'status'    =>   false,
                'errors'   =>    $validator->errors() 
            ], 422);
        }

        DB::beginTransaction();

        try {
            $userUpdateData   = [];

            if ($request->has('name')) {
                $userUpdateData['name'] = $request->name;
            }

            if($request->has('email')){
                $userUpdateData['email'] = $request->email;
            }

            if($request->has('phone')){
                $userUpdateData['phone']  =  $request->phone;
            }

            if($request->has('password')){
                $userUpdateData['password']  = Hash::make($request->password);
            }

            if(!empty($userUpdateData)){
                $user->update($userUpdateData);
            }

            DB::commit();

            $user->refresh();

            return response()->json([
                'status'          =>    true,
                'message'         =>    'Personal details successfully updated.',
                'data'            =>     [
                    'user'  =>[
                        'id'    => $user->id,
                        'name'  => $user->name,
                        'phone' => $user->phone,
                        'email' => $user->email,
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status'     =>   false,
                'message'    =>   'Admin profile update failed.',
                'error'      =>    $e->getMessage()
            ], 500);
        }
    }
}
