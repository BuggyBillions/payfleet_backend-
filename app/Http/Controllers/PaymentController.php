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

        if (!Hash::check($validated['pin'], $company->pin)) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid PIN.',
            ], 422);
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

            $totalEmployees = $employees->count();
            $totalPaid = 0;
            $totalSkipped = 0;
            $totalFailed = 0;
            $totalDeductions = 0;

            $flutterwaveSecretKey = config('services.flutterwave.secret_key');

            if (!$flutterwaveSecretKey) {
                DB::rollBack();

                return response()->json([
                    'status'  => false,
                    'message' => 'Flutterwave secret key is not configured.',
                ], 500);
            }

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
                        'employee_id'       => $employee->id,
                        'status'            => 'skipped',
                        'reason'            => 'Net salary is zero after deduction.',
                        'estimate_pay'      => $estimatePay,
                        'deduction'         => $deductionAmount,
                        'net_salary'        => $netSalary,
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

                
                $response = Http::withToken($flutterwaveSecretKey)
                    ->acceptJson()
                    ->post('https://api.flutterwave.com/v3/transfers', [
                        'account_bank'    => $employee->bank_code,
                        'account_number'  => $employee->account_number,
                        'amount'          => $netSalary,
                        'currency'        => 'NGN',
                        'beneficiary_name'=> $employee->name ?? 'Employee',
                        'reference'       => $reference,
                        'debit_currency'  => 'NGN',
                        'narration'       => 'Salary payment',
                    ]);

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

                $totalPaid++;
                Payment::create([
                    'employee_id' => $employee->user_id ?? $admin->id,
                    'employee_name' => $employee->user_id ?? $admin->id,
                ]);
                Transaction::create([
                    'user_id'          => $employee->user_id ?? $admin->id,
                    'amount'           => $netSalary,
                    'transaction_type' => 'payroll',
                    'reference'        => $reference,
                    'status'            => 'successful',
                    'meta'             => json_encode([
                        'company_id'       => $company->id,
                        'employee_id'      => $employee->id,
                        'estimate_pay'     => $estimatePay,
                        'deduction'        => $deductionAmount,
                        'net_salary'       => $netSalary,
                        'flutterwave'      => $flutterwaveData,
                    ]),
                ]);

                $results[] = [
                    'employee_id'       => $employee->id,
                    'status'            => 'paid',
                    'estimate_pay'      => $estimatePay,
                    'deduction'         => $deductionAmount,
                    'net_salary'        => $netSalary,
                    'reference'         => $reference,
                ];
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Payroll processing completed.',
                'summary' => [
                    'total_employees'  => $totalEmployees,
                    'total_paid'       => $totalPaid,
                    'total_skipped'    => $totalSkipped,
                    'total_failed'     => $totalFailed,
                    'total_deductions' => $totalDeductions,
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
