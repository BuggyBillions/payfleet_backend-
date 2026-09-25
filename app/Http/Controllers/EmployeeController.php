<?php

namespace App\Http\Controllers;

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

class EmployeeController extends Controller
{
    private function isFullCompany(User $user): bool
    {
        return $user->role === 'company';
    }    
    
    public function createEmployee(Request $request)
    {
        $admin = $request->user();

        if(!$admin){
            return response()->json([
                'status'   => false,
                'message'  => 'Unauthorized.'
            ], 401);
        }

        if(!$this->isFullCompany($admin)){
            return response()->json([
                'status'   => false,
                'message'  => 'Only Company can Create Employee.'
            ], 403);
        }

        $validated = $request->validate([
            'company_id'  =>   'required',
            'first_name'  =>   'required|string|max:255',
            'last_name'  =>    'required|string|max:255',
            'email'      =>    'required|unique:employees,email',
            'phone'      =>    'required|string|max:15',
            'address'    =>    'required|string',
            'job_title'  =>    'required|string',
            'employment_type' => 'required|string',
            'bank_name' => 'required|string',
            'account_name' => 'required|string',
            'account_number' => 'required|string',
            'estimate_pay' => 'required|numeric|min:1',
        ]);

        DB::beginTransaction();
        try{
            $employee  = Employees::create([
                'company_id'    =>   $validated['company_id'],
                'first_name'    =>   $validated['first_name'],
                'last_name'    =>   $validated['last_name'],
                'email'    =>   $validated['email'],
                'phone'    =>   $validated['phone'],
                'address'    =>   $validated['address'],
                'job_title'    =>   $validated['job_title'],
                'paying'    =>   1,
                'employment_type'    =>   $validated['employment_type'],
                'bank_name'    =>   $validated['bank_name'],
                'account_name'    =>   $validated['account_name'],
                'account_number'    =>   $validated['account_number'],
                'estimate_pay'    =>   $validated['estimate_pay'],
            ]);

            ActivityLog::create([
                'user_id'  => $admin->id,
                'action'     => 'Company created Employee',
                'details' => json_encode([
                    'created_support_id' => $admin->id,
                    'email' => $admin->email,
                    'role' => $admin->role,
                ]),
                'type' => 'admin',
            ]);

            Notification::create([
                'user_id' => $admin->id,
                'title' => 'Company Created Employee',
                'message' => 'Company Successfully created an employee',
                'type' => 'system',
            ]);


            DB::commit();
            return response()->json([
                'status'   =>  true,
                'message'  => 'Employee Account created sucessfully.',
                'data'     => $employee,
            ], 201);

        }catch(\Exception $e){
            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Employee Account creation failed.',
                'error'   =>  $e->getMessage()
            ], 500);
        }
    }

    public function myEmployees(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company account not found.'
            ], 404);
        }
        $search = $request->input('search');

        $employees = Employees::where('company_id', $company->id)
            ->with('company')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('last_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('employment_type', 'LIKE', '%' . $search . '%')
                        ->orWhere('paying', 'LIKE', '%' . $search . '%')
                        ->orWhere('bank_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('phone', 'LIKE', '%' . $search . '%')
                        ->orWhere('address', 'LIKE', '%' . $search . '%');
                });
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Employees fetched successfully.',
            'data' => $employees
        ], 200);
    }

    public function myEmployee(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company account not found.'
            ], 404);
        }

        $employee = Employees::where('id', $id)
            ->where('company_id', $company->id)
            ->with('company')
            ->first();

        if (!$employee) {
            return response()->json([
                'status' => false,
                'message' => 'Employee not found.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Employee fetched successfully.',
            'data' => $employee
        ], 200);
    }

    public function updateEmployee(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) 
            return response()->json(['message' => 'Unauthorized'], 401);

        $company = Company::where('email', $user->email)->first();
        if (!$company) return response()->json(['message' => 'Company not found'], 404);

        $employee = Employees::where('company_id', $company->id)->find($id);
        if (!$employee) return response()->json(['message' => 'Employee not found or unauthorized'], 404);

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:employees,email,' . $employee->id,
            'phone_number' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'job_title' => 'sometimes|string',
            'employment_type' => 'sometimes|string',
            'bank_name' => 'sometimes|string',
            'account_number' => 'sometimes|string',
            'estimate_pay' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $employee->update($request->only([
            'first_name','last_name','email','phone_number','address',
            'job_title','employment_type','bank_name','account_number','estimate_pay'
        ]));

        return response()->json([
            'message' => 'Employee updated successfully',
            'employee' => $employee
        ]);
    }
    
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        $company = Company::where('email', $user->email)->first();
        if (!$company) return response()->json(['message' => 'Company not found'], 404);

        $employee = Employees::where('company_id', $company->id)->find($id);
        if (!$employee) return response()->json(['message' => 'Employee not found or unauthorized'], 404);

        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully'
        ]);
    }

    public function togglePaying(Request $request, $id)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $company = Company::where('email', $user->email)->first();
        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $employee = Employees::where('company_id', $company->id)->find($id);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found or unauthorized'], 404);
        }

        $employee->paying = $employee->paying ? 0 : 1;
        $employee->save();

        return response()->json([
            'message' => 'Employee paying status updated successfully',
            'employee' => $employee
        ]);
    }

    public function updateMultiplePaying(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $company = Company::where('email', $user->email)->first();
        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'paying' => 'required|in:0,1', 
        ]);

        $updated = Employees::where('company_id', $company->id)
            ->whereIn('id', $request->ids)
            ->update(['paying' => $request->paying]);

        if ($updated === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No employees found for the given IDs or unauthorized',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => "Paying status updated for {$updated} employee(s)",
        ]);
    }
    
    private function isStaff(User $user) : bool 
    {
      return $user->role === 'finance';   
    }

    private function isFullAdmin(User $user) : bool{
        return $user->role === 'admin';
    }

    private function canManageUsers(User $user): bool
    {
        return ($user->is_active == 1 &&
            (
                $this->isFullAdmin($user) ||
                $this->isStaff($user) 
            )
        );
    }

    public function deductionPay(Request $request)
    {
        $admin  = $request->user();

        if (!$admin) {
            return response()->json([

            ]);
        }
    }
}
