<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Company;
use App\Models\Deduction;
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
        $companyUser = $request->user();

        if (!$companyUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 401);
        }

        if (!$this->isFullCompany($companyUser)) {
            return response()->json([
                'status' => false,
                'message' => 'Only Company can Create Employee.'
            ], 403);
        }

        $company = Company::where('user_id', $companyUser->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company account not found.'
            ], 404);
        }

        $tier = (int) $company->tier;

        $employeeLimits = [
            1 => 5,
            2 => 15,
            3 => 50,
        ];

        if (!isset($employeeLimits[$tier])) {
            return response()->json([
                'status' => false,
                'message' => 'Your company tier is not properly configured. Please contact support.'
            ], 422);
        }

        $employeeLimit = $employeeLimits[$tier];

        $currentEmployees = Employees::where('company_id', $company->id)->count();

        if ($currentEmployees >= $employeeLimit) {
            return response()->json([
                'status' => false,
                'message' => 'You have reached the maximum number of employees for your current plan. Please upgrade your plan to add more employees.',
                'data' => [
                    'tier' => $tier,
                    'employee_limit' => $employeeLimit,
                    'current_employees' => $currentEmployees,
                    'remaining_slots' => 0,
                ]
            ], 403);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:employees,email',
            'phone' => 'required|string|max:15',
            'address' => 'required|string',
            'job_title' => 'required|string',
            'employment_type' => 'required|string',
            'bank_name' => 'required|string',
            'account_name' => 'required|string',
            'account_number' => 'required|string',
            'estimate_pay' => 'required|numeric|min:1',
        ]);

        DB::beginTransaction();

        try {
            $employee = Employees::create([
                'company_id' => $company->id,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'address' => $validated['address'],
                'job_title' => $validated['job_title'],
                'paying' => 1,
                'employment_type' => $validated['employment_type'],
                'bank_name' => $validated['bank_name'],
                'account_name' => $validated['account_name'],
                'account_number' => $validated['account_number'],
                'estimate_pay' => $validated['estimate_pay'],
            ]);

            ActivityLog::create([
                'user_id' => $companyUser->id,
                'action' => 'Company created Employee',
                'details' => json_encode([
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                    'employee_email' => $employee->email,
                    'tier' => $tier,
                ]),
                'type' => 'company',
            ]);

            Notification::create([
                'user_id' => $companyUser->id,
                'title' => 'Employee Created',
                'message' => 'Employee account was successfully created.',
                'type' => 'system',
            ]);

            DB::commit();

            $currentEmployees++;
            return response()->json([
                'status' => true,
                'message' => 'Employee Account created successfully.',
                'data' => [
                    'employee' => $employee,
                    'employee_stats' => [
                        'tier' => $tier,
                        'employee_limit' => $employeeLimit,
                        'current_employees' => $currentEmployees,
                        'remaining_slots' => $employeeLimit - $currentEmployees,
                    ]
                ],
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Employee Account creation failed.',
                'error' => $e->getMessage()
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
                $this->isStaff($user) ||
                $this->isFullCompany($user)
            )
        );
    }

    public function deductionPay(Request $request)
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        if (!$this->isFullCompany($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Only Company can deduct staff salary.'
            ], 403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
            'no_of_month' => ['required', 'integer', 'min:1'],
        ]);

        $company = Company::where('user_id', $admin->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company account not found.'
            ], 404);
        }

        $employee = Employees::where('id', $validated['employee_id'])
            ->where('company_id', $company->id)
            ->first();

        if (!$employee) {
            return response()->json([
                'status' => false,
                'message' => 'Employee not found or does not belong to your company.'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $deduction = Deduction::create([
                'employee_id' => $employee->id,
                'amount' => $validated['amount'],
                'reason' => $validated['reason'],
                'no_of_month' => $validated['no_of_month'],
            ]);

            $employee->update([
                'deduction_id' => $deduction->id,
                'deduction_amount' => $validated['amount'],
            ]);

            ActivityLog::create([
                'user_id' => $admin->id,
                'action' => 'Company Added Employee Deduction',
                'details' => json_encode([
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->first_name . ' ' . $employee->last_name,
                    'deduction_id' => $deduction->id,
                    'amount' => $deduction->amount,
                    'reason' => $deduction->reason,
                    'no_of_month' => $deduction->no_of_month,
                ]),
                'type' => 'company',
            ]);

            Notification::create([
                'user_id' => $admin->id,
                'title' => 'Employee Salary Deduction',
                'message' => 'A salary deduction was successfully added to ' .
                    $employee->first_name . ' ' . $employee->last_name . '.',
                'type' => 'system',
            ]);

            DB::commit();

            $employee->refresh();
            $deduction->refresh();

            return response()->json([
                'status' => true,
                'message' => 'Employee salary deduction created successfully.',
                'data' => [
                    'deduction' => $deduction,
                    'employee' => $employee,
                ]
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Employee salary deduction failed.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function AlldeductedEmployee(Request $request)
    {
       $admin = $request->user();

        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 403);
        } 

        $search = $request->input('search');

        $deposits = Deduction::with([
                'employee'
            ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {

                    $q->where('amount', 'LIKE', '%' . $search . '%')
                        ->orWhere('employee_id', 'LIKE', '%' . $search . '%')
                        ->orWhereHas('employee', function ($companyQuery) use ($search) {
                            $companyQuery->where('first_name', 'LIKE', '%' . $search . '%')
                                ->orWhere('email', 'LIKE', '%' . $search . '%')
                                ->orWhere('job_title', 'LIKE', '%' . $search . '%')
                                ->orWhere('address', 'LIKE', '%' . $search . '%')
                                ->orWhere('last_name', 'LIKE', '%' . $search . '%');
                        });
                });
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Deposits fetched successfully',
            'data' => $deposits
        ], 200);
    }
    
    public function companyDeduction(Request $request)
    {
        $admin = $request->user();

        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $search = $request->input('search');
        $companyId = $request->input('company_id');
        $employeeId = $request->input('employee_id');
        $status = $request->input('status');
        $minAmount = $request->input('min_amount');
        $maxAmount = $request->input('max_amount');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $deductions = Deduction::with([
            'employee.company'
        ])
        ->when($search, function ($query) use ($search) {
            $query->where(function ($q) use ($search) {

                $q->where('amount', 'LIKE', '%' . $search . '%')
                    ->orWhere('reason', 'LIKE', '%' . $search . '%')
                    ->orWhere('employee_id', 'LIKE', '%' . $search . '%')
                    ->orWhere('no_of_month', 'LIKE', '%' . $search . '%')

                    ->orWhereHas('employee', function ($employeeQuery) use ($search) {
                        $employeeQuery->where('first_name', 'LIKE', '%' . $search . '%')
                            ->orWhere('last_name', 'LIKE', '%' . $search . '%')
                            ->orWhere('email', 'LIKE', '%' . $search . '%')
                            ->orWhere('phone', 'LIKE', '%' . $search . '%')
                            ->orWhere('job_title', 'LIKE', '%' . $search . '%')
                            ->orWhere('address', 'LIKE', '%' . $search . '%')
                            ->orWhere('bank_name', 'LIKE', '%' . $search . '%')
                            ->orWhere('account_name', 'LIKE', '%' . $search . '%')
                            ->orWhere('account_number', 'LIKE', '%' . $search . '%')

                            ->orWhereHas('company', function ($companyQuery) use ($search) {
                                $companyQuery->where('name', 'LIKE', '%' . $search . '%')
                                    ->orWhere('email', 'LIKE', '%' . $search . '%')
                                    ->orWhere('phone', 'LIKE', '%' . $search . '%');
                            });
                    });
                    
            });
        })

        ->when($companyId, function ($query) use ($companyId) {
            $query->whereHas('employee', function ($employeeQuery) use ($companyId) {
                $employeeQuery->where('company_id', $companyId);
            });
        })

        ->when($employeeId, function ($query) use ($employeeId) {
            $query->where('employee_id', $employeeId);
        })

        ->when($minAmount, function ($query) use ($minAmount) {
            $query->where('amount', '>=', $minAmount);
        })

        ->when($maxAmount, function ($query) use ($maxAmount) {
            $query->where('amount', '<=', $maxAmount);
        })

        ->when($startDate, function ($query) use ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
        })

        ->when($endDate, function ($query) use ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
        })

        ->latest()
        ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Company deductions fetched successfully',
            'data' => $deductions
        ], 200);
    }

    public function deleteDeduction(Request $request, $id)
    {
        $admin = $request->user();

        if(!$this->isFullCompany($admin)){
            return response()->json([
                'status'     =>    false,
                'message'    =>    'Only company is authorized to delete employees salary deductions.'
            ]);
        }

        $deduction  =  Deduction::find($id);

        if(!$deduction){
            return response()->json([
                'status'    =>   false,
                'message'   =>   'deduction not found'
            ]);
        }

        DB::beginTransaction();
        try {
            $deductionData  =  $deduction->toArray();

            $deduction->delete();

            ActivityLog::create([
                'user_id'      =>  $admin->id,
                'action'       =>  'Company Deleted Deductions',
                'details'      =>  json_encode([
                    'id'   =>   $deductionData['id'],
                    'email' => $deductionData['email'] ?? null,
                    'role'  =>  $deductionData['role']   ?? null, 
                ]),
                'type'  =>  'admin',
            ]);

            DB::commit();
            return response()->json([
                'status'      => true,
                'messsage'    => 'deduction successfully deleted',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'     => false,
                'message'    => 'Deduction delete failed',
                'error'      => $e->getMessage()
            ], 500);
        }
    }
}
