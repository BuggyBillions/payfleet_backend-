<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Company;
use App\Models\Employees;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AdminActionsController extends Controller
{
    public function getCompanies(Request $request)
    {
        $admin = $request->user();

        if(!$this->canManageUsers($admin)){
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $search = $request->input('search');

        $companies = Company::with('user')
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
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Companies fetched successfully',
            'data' => $companies
        ], 200);
    }

    public function eachCompanies(Request $request , $id) {
        $admin = $request->user();

        if(!$this->canManageUsers($admin)){
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $companies = Company::with('user')
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

    public function getStaff( Request $request): JsonResponse {
        $admin = $request->user();
        if (!$this->isFullAdmin($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }
        $search = $request->input('search');
        
        $query = User::query()
            ->whereIn('role', ['finance', 'support'])
             ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('email', 'LIKE', '%' . $search . '%')
                        ->orWhere('phone', 'LIKE', '%' . $search . '%')
                        ->orWhere('role', 'LIKE', '%' . $search . '%')
                        ->orWhere('is_active', 'LIKE', '%' . $search . '%');
                });
            })
            ->select(['id','name','email','phone','role','is_verified','is_active','is_verified','created_at']);

        $staff = $query
            ->latest()
            ->paginate($request->get('per_page',20 ));

        return response()->json([
            'status' => true,
            'message' =>'Staff fetched successfully.',
            'data' => $staff
        ]);
    }

    public function eachStaff(Request $request , $id) {
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

    public function deactivateUser(  Request $request,   $id): JsonResponse {
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

    public function activateUser(  Request $request,   $id): JsonResponse {
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

    public function deleteUser(  Request $request,  $id): JsonResponse {
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

    public function updateProfile(Request $request)
    {
        $company = $request->user();

        if (!$company) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'address'  => 'nullable|string',
            'pin'      => 'nullable|string|size:4',
            'password' => 'nullable|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $updateData = [];

        if ($request->has('address')) {
            $updateData['address'] = $request->address;
        }

        if ($request->has('pin')) {
            $updateData['pin'] = Hash::make($request->pin);
        }

        if ($request->has('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        if (empty($updateData)) {
            return response()->json([
                'success' => false,
                'message' => 'No valid fields provided for update.'
            ], 400);
        }

        $company->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Company profile updated successfully.',
            'company' => $company
        ]);
    }

    public function createAccount(Request $request){
        $admin  =  $request->user();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login again.'
            ], 401);
        }

        if (!$this->isFullAdmin($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Only an admin can create bank account.'
            ], 403);
        }

        $validated = $request->validate([
            'account_number' => [ 'required', 'max:15'],
            'bank_name' => [ 'required'],
            'account_name' => [ 'required', 'string'],
        ]);

        DB::beginTransaction();
        try {
            $user = Account::create([
                'account_number' =>$validated['account_number'],
                'bank_name' =>$validated['bank_name'],
                'account_name' =>$validated['account_name'],
            ]);

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' =>'Admin Created Bnak account',
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
                'message' =>'Bank Account created successfully.',
                'data' =>  $user
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Bank Account creation failed.',
                'error' =>  $e->getMessage()

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
}
