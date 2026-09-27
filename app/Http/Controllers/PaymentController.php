<?php

namespace App\Http\Controllers;

use App\Models\User;
use PhpParser\Node\Stmt\Return_;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Company;
use App\Models\Employees;
use App\Models\Deduction;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Support\Facades\Hash;

class PaymentController extends Controller
{
    private function isFullCompany(User $user) : bool
    {
        return $user->role === 'company';
    } 

    public function trigerPayroll(Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!$admin) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$this->isFullCompany($admin)) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized to access the endpoint.',
            ], 403);
        }

        $validated = $request->validate([
            'pin'        => ['required', 'string'],
            'company_id' => ['required', 'exists:companies,id'],
        ]);

        $company = Company::where('id', $validated['company_id'])
            ->where('user_id', $admin->id)
            ->first();

        if (!$company) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to process payroll for this company.',
            ], 403);
        }

        if (!$company->pin || !Hash::check($validated['pin'], $company->pin)) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid PIN.',
            ], 422);
        }

        $flutterwaveSecretKey = config('services.flutterwave.secret_key');

        if (!$flutterwaveSecretKey) {
            return response()->json([
                'status'  => false,
                'message' => 'Flutterwave secret key is not configured.',
            ], 500);
        }

        DB::beginTransaction();

        try {
            $employees = Employees::where('company_id', $company->id)
                ->get();

            if ($employees->isEmpty()) {

                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'No employees found for this company.',
                ], 404);
            }

            $results = [];

            $totalEmployees  = $employees->count();
            $totalPaid       = 0;
            $totalSkipped    = 0;
            $totalFailed     = 0;
            $totalDeductions = 0;
            $totalAmountPaid = 0;

            $payrollMonth = now()->format('Y-m');

            foreach ($employees as $employee) {
                if ((int) $employee->paying !== 1) {

                    $totalSkipped++;
                    $results[] = [
                        'employee_id' => $employee->id,
                        'status'      => 'skipped',
                        'reason'      => 'Employee is not marked for payment.',
                    ];
                    continue;
                }

                $alreadyPaid = Payment::where('employee_id', $employee->id)
                    ->where('status', 'successful')
                    ->whereYear('payment_date', now()->year)
                    ->whereMonth('payment_date', now()->month)
                    ->exists();

                if ($alreadyPaid) {

                    $totalSkipped++;
                    $results[] = [
                        'employee_id' => $employee->id,
                        'status'      => 'skipped',
                        'reason'      => 'Employee has already been paid for this month.',
                    ];

                    continue;
                }

                $estimatePay = (float) $employee->estimate_pay;

                if ($estimatePay <= 0) {
                    $totalSkipped++;

                    $results[] = [
                        'employee_id' => $employee->id,
                        'status'      => 'skipped',
                        'reason'      => 'Employee has no valid salary.',
                    ];

                    continue;
                }

                $deduction = Deduction::where('employee_id', $employee->id)
                    ->latest('created_at')
                    ->first();

                $deductionAmount = 0;

                if ($deduction) {

                    $createdDate = Carbon::parse($deduction->created_at);
                    $noOfMonths = (int) $deduction->no_of_month;
                    if ($noOfMonths > 0) {
                        $deductionEndDate = $createdDate
                            ->copy()
                            ->addMonths($noOfMonths);

                        if (now()->lt($deductionEndDate)) {
                            $deductionAmount = (float) $deduction->amount;
                            $totalDeductions += $deductionAmount;
                        }
                    }
                }

                $netSalary = $estimatePay - $deductionAmount;

                if ($netSalary < 0) {
                    $netSalary = 0;
                }

                if ($netSalary <= 0) {
                    $totalSkipped++;
                    $results[] = [
                        'employee_id'  => $employee->id,
                        'status'       => 'skipped',
                        'reason'       => 'Net salary is zero after deduction.',
                        'estimate_pay' => $estimatePay,
                        'deduction'    => $deductionAmount,
                        'net_salary'   => $netSalary,
                    ];

                    continue;
                }

                if (empty($employee->account_number) || empty($employee->bank_code)
                ) {
                    $totalSkipped++;
                    $results[] = [
                        'employee_id' => $employee->id,
                        'status'      => 'skipped',
                        'reason'      => 'Employee bank details are incomplete.',
                    ];
                    continue;
                }

                $reference = 'PAYROLL-' .
                    $company->id . '-' .
                    $employee->id . '-' .
                    Str::upper(Str::random(10));

                $employeeName = $employee->name;

                if (!$employeeName && $employee->user_id) {
                    $employeeUser = User::find($employee->user_id);
                    if ($employeeUser) {
                        $employeeName = $employeeUser->name;
                    }
                }

                $employeeName = $employeeName ?: 'Employee';

                $previousBalance = (float) $company->balance;

                if ($previousBalance < $netSalary) {

                    $totalFailed++;
                    $results[] = [
                        'employee_id' => $employee->id,
                        'status'      => 'failed',
                        'reason'      => 'Insufficient company balance.',
                        'salary'      => $netSalary,
                    ];

                    continue;
                }

                $response = Http::withToken($flutterwaveSecretKey)
                    ->acceptJson()
                    ->post(
                        'https://api.flutterwave.com/v3/transfers',
                        [
                            'account_bank'     => $employee->bank_code,
                            'account_number'   => $employee->account_number,
                            'amount'           => $netSalary,
                            'currency'         => 'NGN',
                            'beneficiary_name' => $employeeName,
                            'reference'        => $reference,
                            'debit_currency'   => 'NGN',
                            'narration'        => 'Salary payment',
                        ]
                    );

                if (!$response->successful()) {
                    $totalFailed++;
                    $results[] = [
                        'employee_id' => $employee->id,
                        'status'      => 'failed',
                        'reason'      => 'Flutterwave payment request failed.',
                        'response'    => $response->json(),
                    ];
                    continue;
                }

                $flutterwaveData = $response->json();

                if (($flutterwaveData['status'] ?? null) !== 'success') {
                    $totalFailed++;
                    $results[] = [
                        'employee_id' => $employee->id,
                        'status'      => 'failed',
                        'reason'      => $flutterwaveData['message']
                            ?? 'Flutterwave payment failed.',
                    ];
                    continue;
                }

                $currentBalance = $previousBalance - $netSalary;
                $company->balance = $currentBalance;
                $company->save();
                Payment::create([
                    'employee_id'   => $employee->id,
                    'employee_name' => $employeeName,
                    'amount'        => $netSalary,
                    'payment_date'  => now()->toDateString(),
                    'reference'     => $reference,
                    'status'        => 'successful',
                ]);

                Transaction::create([
                    'company_id'       => $company->id,
                    'reference'        => $reference,
                    'amount'           => $netSalary,
                    'previous_balance' => $previousBalance,
                    'current_balance'  => $currentBalance,
                    'type'             => 'debit',
                    'transaction_type' => 'payroll',
                    'status'           => 'successful',
                    'description'      => 'Payroll payment to ' . $employeeName,
                ]);

                if ($employee->user_id) {
                    Notification::create([
                        'user_id'  => $employee->user_id,
                        'title'    => 'Salary Payment',
                        'message'  => 'Your salary payment of ₦'
                            . number_format($netSalary, 2)
                            . ' has been processed successfully.',
                        'type'     => 'payroll',
                        'is_read'  => false,
                    ]);
                }

                $totalPaid++;
                $totalAmountPaid += $netSalary;

                $results[] = [
                    'employee_id'  => $employee->id,
                    'employee_name'=> $employeeName,
                    'status'       => 'paid',
                    'estimate_pay' => $estimatePay,
                    'deduction'    => $deductionAmount,
                    'net_salary'   => $netSalary,
                    'reference'    => $reference,
                ];
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Payroll processing completed.',

                'summary' => [
                    'total_employees'  => $totalEmployees,
                    'total_paid'       => $totalPaid,
                    'total_skipped'    => $totalSkipped,
                    'total_failed'     => $totalFailed,
                    'total_deductions' => $totalDeductions,
                    'total_amount_paid'=> $totalAmountPaid,
                    'remaining_balance'=> $company->balance,
                    'payroll_month'    => $payrollMonth,
                ],

                'data' => $results,
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();
            return response()->json([
                'status'  => false,
                'message' => 'Company payroll failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
