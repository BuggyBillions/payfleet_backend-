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

                $employeeName = $employee->first_name;

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
    
    public function companyPayment(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status'  => false,
                'message' => 'Company account not found.'
            ], 404);
        }

        $perPage = (int) $request->query('per_page', 10);

        if ($perPage < 1) {
            $perPage = 10;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        $search     = trim($request->query('search', ''));
        $status     = $request->query('status');
        $month      = $request->query('month');
        $dateFrom   = $request->query('date_from');
        $dateTo     = $request->query('date_to');

        $allowedStatuses = [
            'pending',
            'successful',
            'failed',
            'cancelled',
        ];

        if ($status && $status !== 'all' && !in_array($status, $allowedStatuses)) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid status.',
                'allowed' => $allowedStatuses,
            ], 422);
        }

        $monthDate = null;
        if ($month) {
            try {
                $monthDate = \Carbon\Carbon::createFromFormat('Y-m',$month);

                if ($monthDate->format('Y-m') !== $month) {
                    throw new \Exception();
                }

            } catch (\Exception $e) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid month format. Use YYYY-MM, e.g. 2026-09.'
                ], 422);
            }
        }
        if ($dateFrom) {
            try {
                $dateFromCarbon = \Carbon\Carbon::parse($dateFrom)
                    ->startOfDay();

            } catch (\Exception $e) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid date_from. Use YYYY-MM-DD.'
                ], 422);
            }

        } else {
            $dateFromCarbon = null;
        }

        if ($dateTo) {
            try {
                $dateToCarbon = \Carbon\Carbon::parse($dateTo)
                    ->endOfDay();

            } catch (\Exception $e) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid date_to. Use YYYY-MM-DD.'
                ], 422);
            }

        } else {
            $dateToCarbon = null;
        }

        if ($dateFromCarbon && $dateToCarbon &&  $dateFromCarbon->gt($dateToCarbon)
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'date_from cannot be greater than date_to.'
            ], 422);
        }

        $query = \App\Models\Payment::query()
            ->with([
                'employee:id,company_id,first_name,last_name,email,phone,address,job_title,paying,employment_type,bank_name,account_name,account_number,estimate_pay,deduction_amount,bank_code'
            ])
            ->whereHas('employee', function ($employeeQuery) use ($company) {
                $employeeQuery->where(
                    'company_id',
                    $company->id
                );
            });

        if ($search !== '') {

            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', '%' . $search . '%')
                    ->orWhere('employee_name', 'LIKE', '%' . $search . '%')
                    ->orWhere('amount', 'LIKE', '%' . $search . '%');

                $q->orWhereHas('employee', function ($employeeQuery) use ($search) {
                    $employeeQuery
                        ->where('first_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('last_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('email', 'LIKE', '%' . $search . '%')
                        ->orWhere('phone', 'LIKE', '%' . $search . '%')
                        ->orWhere('account_number', 'LIKE', '%' . $search . '%')
                        ->orWhere('account_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('bank_name', 'LIKE', '%' . $search . '%')
                        ->orWhere('job_title', 'LIKE', '%' . $search . '%');
                });
            });
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($monthDate) {
            $query->whereYear(
                'payment_date',
                $monthDate->year
            )->whereMonth(
                'payment_date',
                $monthDate->month
            );
        }

        if ($dateFromCarbon) {
            $query->where(
                'payment_date',
                '>=',
                $dateFromCarbon->toDateString()
            );
        }

        if ($dateToCarbon) {
            $query->where(
                'payment_date',
                '<=',
                $dateToCarbon->toDateString()
            );
        }

        $payments = $query
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($payments->isEmpty()) {

            return response()->json([
                'status'  => false,
                'message' => 'No salary payment found.',
                'data'    => [],
            ], 402);
        }

        $grouped = $payments
            ->groupBy(function ($payment) {
                return \Carbon\Carbon::parse(
                    $payment->payment_date
                )->format('Y-m');
            })
            ->sortKeysDesc()
            ->map(function ($group, $monthKey) {

                $monthDate = \Carbon\Carbon::createFromFormat(
                    'Y-m',
                    $monthKey
                );

                return [
                    'month' => $monthDate->format('F Y'),
                    'month_key' => $monthKey,
                    'count' => $group->count(),
                    'total_amount' => (float) $group->sum('amount'),
                    'successful_amount' => (float) $group
                        ->where('status', 'successful')
                        ->sum('amount'),

                    'pending_amount' => (float) $group
                        ->where('status', 'pending')
                        ->sum('amount'),

                    'failed_amount' => (float) $group
                        ->where('status', 'failed')
                        ->sum('amount'),

                    'payments' => $group
                        ->map(function ($payment) {

                            $employee = $payment->employee;
                            return [
                                'id' => $payment->id,
                                'employee_id' => $payment->employee_id,
                                'employee_name' => $payment->employee_name,
                                'amount' => (float) $payment->amount,
                                'payment_date' => $payment->payment_date
                                    ? \Carbon\Carbon::parse(
                                        $payment->payment_date
                                    )->format('Y-m-d')
                                    : null,

                                'reference' => $payment->reference,
                                'status' => $payment->status,
                                'created_at' => $payment->created_at
                                    ? $payment->created_at->format(
                                        'Y-m-d H:i:s'
                                    )
                                    : null,

                                'updated_at' => $payment->updated_at
                                    ? $payment->updated_at->format(
                                        'Y-m-d H:i:s'
                                    )
                                    : null,

                                'employee' => $employee ? [
                                    'id' => $employee->id,
                                    'company_id' => $employee->company_id,
                                    'first_name' => $employee->first_name,
                                    'last_name' => $employee->last_name,
                                    'full_name' => trim(
                                        $employee->first_name . ' ' .
                                        $employee->last_name
                                    ),
                                    'email' => $employee->email,
                                    'phone' => $employee->phone,
                                    'address' => $employee->address,
                                    'job_title' => $employee->job_title,
                                    'paying' => $employee->paying,
                                    'employment_type' =>
                                        $employee->employment_type,
                                    'bank_name' => $employee->bank_name,
                                    'account_name' =>
                                        $employee->account_name,
                                    'account_number' =>
                                        $employee->account_number,
                                    'estimate_pay' =>
                                        (float) $employee->estimate_pay,
                                    'deduction_amount' =>
                                        (float) $employee->deduction_amount,
                                    'bank_code' => $employee->bank_code,
                                ] : null,
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();

        $currentPage = max(
            1,
            (int) $request->query('page', 1)
        );

        $totalGroups = $grouped->count();
        $lastPage = (int) ceil(
            $totalGroups / $perPage
        );
        if ($currentPage > $lastPage && $lastPage > 0) {
            $currentPage = $lastPage;
        }
        $paginatedGroups = $grouped
            ->slice(
                ($currentPage - 1) * $perPage,
                $perPage
            )
            ->values();

        return response()->json([
            'status'  => true,
            'message' => 'Company payments fetched successfully.',
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
            ],

            'filters' => [
                'search'    => $search ?: null,
                'status'    => $status ?: 'all',
                'month'     => $month ?: null,
                'date_from' => $dateFrom ?: null,
                'date_to'   => $dateTo ?: null,
            ],

            'data' => $paginatedGroups,

            'pagination' => [
                'current_page' => $currentPage,
                'per_page'     => $perPage,
                'total_groups' => $totalGroups,
                'last_page'    => $lastPage,
                'has_more'     => $currentPage < $lastPage,
            ],
        ], 200);
    }
}
 