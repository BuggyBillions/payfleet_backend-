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
use App\Models\PaymentSetting;
use App\Services\PayrollTransferSettler;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private function isFullCompany(User $user) : bool
    {
        return $user->role === 'company';
    } 

    private function isFullAdmin(User $user) : bool
    {
        return $user->role === 'admin';
    } 

    private function isFinance(User $user) : bool 
    {
        return $user->role === 'finance';
    }

    private function canManageUsers(User $user) : bool{
        return($user->is_active == 1 &&(
            $this->isFullAdmin($user) ||
            $this->isFinance($user)
        ));
    }

    private function canManagePayment(User $user) : bool {
        return($user->is_active  == 1 &&(
           $this->isFullAdmin($user) ||
           $this->isFinance($user) ||
           $this->isFullCompany($user)
        ));
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
                'message' => 'Unauthorized to access this endpoint.',
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

        $settings = PaymentSetting::current();

        if (!$settings) {
            return response()->json([
                'status'  => false,
                'message' => 'Payment settings have not been configured. Please contact an admin.',
            ], 422);
        }

        $senderName = trim((string) $company->name);

        try {
            $employees = Employees::where('company_id', $company->id)->get();

            if ($employees->isEmpty()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No employees found for this company.',
                ], 404);
            }

            $settler = app(PayrollTransferSettler::class);

            $results = [];

            $totalEmployees      = $employees->count();
            $totalPaid           = 0;
            $totalPending        = 0;
            $totalSkipped        = 0;
            $totalFailed         = 0;
            $totalDeductions     = 0;
            $totalAmountPaid     = 0;
            $totalProcessingFees = 0;

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
                    ->whereIn('status', ['successful', 'pending'])
                    ->whereYear('payment_date', now()->year)
                    ->whereMonth('payment_date', now()->month)
                    ->exists();

                if ($alreadyPaid) {
                    $totalSkipped++;
                    $results[] = [
                        'employee_id' => $employee->id,
                        'status'      => 'skipped',
                        'reason'      => 'Employee has already been paid (or is being paid) for this month.',
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
                    $noOfMonths  = (int) $deduction->no_of_month;
                    if ($noOfMonths > 0) {
                        $deductionEndDate = $createdDate->copy()->addMonths($noOfMonths);

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

                if (empty($employee->account_number) || empty($employee->bank_code)) {
                    $totalSkipped++;
                    $results[] = [
                        'employee_id' => $employee->id,
                        'status'      => 'skipped',
                        'reason'      => 'Employee bank details are incomplete.',
                    ];
                    continue;
                }

                $employeeName = $employee->first_name;
                if (!$employeeName && $employee->user_id) {
                    $employeeUser = User::find($employee->user_id);
                    if ($employeeUser) {
                        $employeeName = $employeeUser->name;
                    }
                }

                $employeeName = $employeeName ?: 'Employee';

                $reference = 'PAYROLL-' .
                    $company->id . '-' .
                    $employee->id . '-' .
                    Str::upper(Str::random(10));

                $processingFee = $settings->processingFeeFor($netSalary);
                $totalDebit    = round($netSalary + $processingFee, 2);

                $reservation = DB::transaction(function () use (
                    $company, $employee, $employeeName, $netSalary, $processingFee, $totalDebit, $reference
                ) {
                    $lockedCompany   = Company::where('id', $company->id)->lockForUpdate()->first();
                    $previousBalance = (float) $lockedCompany->balance;

                    if ($previousBalance < $totalDebit) {
                        $payment = Payment::create([
                            'employee_id'    => $employee->id,
                            'employee_name'  => $employeeName,
                            'amount'         => $netSalary,
                            'processing_fee' => $processingFee,
                            'payment_date'   => now()->toDateString(),
                            'reference'      => $reference,
                            'status'         => 'failed',
                        ]);

                        return ['reserved' => false, 'payment' => $payment, 'available' => $previousBalance];
                    }

                    $currentBalance = round($previousBalance - $totalDebit, 2);

                    $lockedCompany->balance = $currentBalance;
                    $lockedCompany->save();

                    $payment = Payment::create([
                        'employee_id'    => $employee->id,
                        'employee_name'  => $employeeName,
                        'amount'         => $netSalary,
                        'processing_fee' => $processingFee,
                        'payment_date'   => now()->toDateString(),
                        'reference'      => $reference,
                        'status'         => 'pending',
                    ]);

                    Transaction::create([
                        'company_id'       => $company->id,
                        'reference'        => $reference,
                        'amount'           => $totalDebit,
                        'previous_balance' => $previousBalance,
                        'current_balance'  => $currentBalance,
                        'type'             => 'debit',
                        'transaction_type' => 'payroll',
                        'status'           => 'pending',
                        'description'      => 'Payroll payment to ' . $employeeName
                            . ' (Salary: ₦' . number_format($netSalary, 2)
                            . ', Processing fee: ₦' . number_format($processingFee, 2) . ')',
                    ]);

                    return ['reserved' => true, 'payment' => $payment];
                });

                $payment = $reservation['payment'];

                if (!$reservation['reserved']) {
                    $totalFailed++;

                    $results[] = [
                        'employee_id'       => $employee->id,
                        'status'            => 'failed',
                        'reason'            => 'Insufficient company balance.',
                        'salary'            => $netSalary,
                        'processing_fee'    => $processingFee,
                        'total_required'    => $totalDebit,
                        'available_balance' => $reservation['available'],
                        'reference'         => $reference,
                    ];
                    continue;
                }

                $totalProcessingFees += $processingFee;

                $transferPayload = [
                    'account_bank'     => $employee->bank_code,
                    'account_number'   => $employee->account_number,
                    'amount'           => $netSalary,
                    'currency'         => 'NGN',
                    'beneficiary_name' => $employeeName,
                    'reference'        => $reference,
                    'debit_currency'   => 'NGN',
                    'narration'        => Str::limit('Salary - ' . ($senderName !== '' ? $senderName : 'Payfleet'), 30, ''),
                ];

                if (config('services.flutterwave.send_sender_meta') && $senderName !== '') {
                    $transferPayload['meta'] = [[
                        'sender'         => $senderName,
                        'sender_country' => 'NG',
                    ]];
                }

                try {
                    $response = Http::withToken($flutterwaveSecretKey)
                        ->acceptJson()
                        ->timeout(30)
                        ->post('https://api.flutterwave.com/v3/transfers', $transferPayload);
                } catch (\Throwable $e) {
                    Log::error('Payroll transfer request error', [
                        'reference' => $reference,
                        'error'     => $e->getMessage(),
                    ]);

                    $totalPending++;
                    $results[] = [
                        'employee_id'   => $employee->id,
                        'employee_name' => $employeeName,
                        'status'        => 'pending',
                        'reason'        => 'Could not confirm the transfer with Flutterwave. It will be confirmed automatically.',
                        'reference'     => $reference,
                        'payment_id'    => $payment->id,
                    ];
                    continue;
                }

                if ($response->status() >= 500) {
                    Log::error('Payroll transfer 5xx', ['reference' => $reference, 'body' => $response->body()]);

                    $totalPending++;
                    $results[] = [
                        'employee_id'   => $employee->id,
                        'employee_name' => $employeeName,
                        'status'        => 'pending',
                        'reason'        => 'Flutterwave is unavailable. The payment will be confirmed automatically.',
                        'reference'     => $reference,
                        'payment_id'    => $payment->id,
                    ];
                    continue;
                }

                if (!$response->successful()) {
                    $settler->finalize($reference, 'FAILED', 'Flutterwave rejected the request');
                    $totalProcessingFees -= $processingFee;
                    $totalFailed++;
                    $results[] = [
                        'employee_id'   => $employee->id,
                        'employee_name' => $employeeName,
                        'status'        => 'failed',
                        'reason'        => 'Flutterwave payment request failed.',
                        'reference'     => $reference,
                        'payment_id'    => $payment->id,
                        'response'      => $response->json(),
                    ];
                    continue;
                }

                $flutterwaveData = $response->json();

                if (($flutterwaveData['status'] ?? null) !== 'success') {
                    $settler->finalize($reference, 'FAILED', $flutterwaveData['message'] ?? null);
                    $totalProcessingFees -= $processingFee;
                    $totalFailed++;
                    $results[] = [
                        'employee_id'   => $employee->id,
                        'employee_name' => $employeeName,
                        'status'        => 'failed',
                        'reason'        => $flutterwaveData['message'] ?? 'Flutterwave payment failed.',
                        'reference'     => $reference,
                        'payment_id'    => $payment->id,
                        'response'      => $flutterwaveData,
                    ];
                    continue;
                }

                if (!empty($flutterwaveData['data']['id'])) {
                    $payment->update(['flutterwave_transfer_id' => (string) $flutterwaveData['data']['id']]);
                }

                $flutterwaveStatus = strtoupper($flutterwaveData['data']['status'] ?? '');

                if (in_array($flutterwaveStatus, ['NEW', 'PENDING'], true)) {
                    $totalPending++;
                    $results[] = [
                        'employee_id'   => $employee->id,
                        'employee_name' => $employeeName,
                        'status'        => 'pending',
                        'reason'        => 'Payment is being processed by Flutterwave.',
                        'reference'     => $reference,
                        'payment_id'    => $payment->id,
                    ];
                    continue;
                }

                if ($flutterwaveStatus === 'FAILED') {
                    $reason = $flutterwaveData['data']['complete_message']
                        ?? $flutterwaveData['message']
                        ?? 'Flutterwave payment failed.';

                    $settler->finalize($reference, 'FAILED', $reason);
                    $totalProcessingFees -= $processingFee;
                    $totalFailed++;
                    $results[] = [
                        'employee_id'   => $employee->id,
                        'employee_name' => $employeeName,
                        'status'        => 'failed',
                        'reason'        => $reason,
                        'reference'     => $reference,
                        'payment_id'    => $payment->id,
                    ];
                    continue;
                }

                if ($flutterwaveStatus === 'SUCCESSFUL') {
                    $settler->finalize($reference, 'SUCCESSFUL');

                    $totalPaid++;
                    $totalAmountPaid += $netSalary;

                    $results[] = [
                        'employee_id'    => $employee->id,
                        'employee_name'  => $employeeName,
                        'status'         => 'paid',
                        'estimate_pay'   => $estimatePay,
                        'deduction'      => $deductionAmount,
                        'net_salary'     => $netSalary,
                        'processing_fee' => $processingFee,
                        'reference'      => $reference,
                        'payment_id'     => $payment->id,
                    ];
                    continue;
                }

                // Unknown status: keep pending rather than guessing
                Log::warning('Unknown Flutterwave transfer status', [
                    'reference' => $reference,
                    'status'    => $flutterwaveStatus,
                ]);

                $totalPending++;
                $results[] = [
                    'employee_id'        => $employee->id,
                    'employee_name'      => $employeeName,
                    'status'             => 'pending',
                    'reason'             => 'Unknown Flutterwave payment status. It will be confirmed automatically.',
                    'flutterwave_status' => $flutterwaveStatus,
                    'reference'          => $reference,
                    'payment_id'         => $payment->id,
                ];
            }

            $company->refresh();

            return response()->json([
                'status'  => true,
                'message' => 'Payroll processing completed.',

                'summary' => [
                    'total_employees'       => $totalEmployees,
                    'total_paid'            => $totalPaid,
                    'total_pending'         => $totalPending,
                    'total_skipped'         => $totalSkipped,
                    'total_failed'          => $totalFailed,
                    'total_deductions'      => $totalDeductions,
                    'total_amount_paid'     => $totalAmountPaid,
                    'remaining_balance'     => $company->balance,
                    'payroll_month'         => $payrollMonth,
                    'total_processing_fees' => $totalProcessingFees,
                ],

                'data' => $results,
            ]);

        } catch (\Throwable $e) {

            Log::error('Company payroll failed', [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
                'line'       => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Company payroll failed. Some payments may already have been started, so check the payments list before retrying.',
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
            ], 200);
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

    public function EachCompanyPayment(Request $request , $id)
    {
        $user  = $request->user();

        if(!$user){
            return response()->json([
                'status'   =>  false,
                'message'  =>  'Unauthenticated to access this endpoint.'
            ]);
        }

        if(!$this->canManagePayment($user)){
            return response()->json([
                'status'   =>  false,
                'message'  =>  'Unathorized to access this endpoint.'
            ]);   
        }

        $company = Company::where('user_id', $user->id)->first();
        
        if(!$company){
            return response()->json([
                'status'  =>  false,
                'message' =>  'Company account not found.'
            ]);
        }

        $company = Payment::with('employee')
            ->where('id', $id)
            ->first();
        
        if(!$company){
            return response()->json([
                'status'    =>   false,
                'message'   =>   'Payment record not found'
            ], 404);
        }    

        return response()->json([
            'status'    =>  'true',
            'message'   =>  'Company payment history fetched succesfully.',
            'data'      =>   $company
        ]);
    }
  
    public function adminPaymentHistory(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        if (!$this->canManageUsers($user)) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $perPage = (int) $request->query('per_page', 10);

        if ($perPage < 1) {
            $perPage = 10;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        $status   = $request->query('status');
        $name     = trim($request->query('name', ''));
        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');
        $amount   = $request->query('amount');

        $allowedStatuses = [
            'pending',
            'successful',
            'failed',
            'cancelled',
            'processing',
        ];

        if ($status && $status !== 'all' && !in_array($status, $allowedStatuses)
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid status.',
                'allowed' => $allowedStatuses,
            ], 422);
        }

        $dateFromCarbon = null;

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
        }

        $dateToCarbon = null;

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
        }

        if ($dateFromCarbon && $dateToCarbon && $dateFromCarbon->gt($dateToCarbon)
        ) {
            return response()->json([
                'status'  => false,
                'message' => 'date_from cannot be greater than date_to.'
            ], 422);
        }

        $query = \App\Models\Payment::query()
            ->with([
                'employee:id,company_id,first_name,last_name,email,phone,address,job_title,paying,employment_type,bank_name,account_name,account_number,estimate_pay,deduction_amount,bank_code',

                'employee.company:id,name,user_id,email,about,logo,address,balance,tier',
            ]);

        if ($name !== '') {
            $query->whereHas('employee.company', function ($companyQuery) use ($name) {
                $companyQuery->where('name', 'LIKE', '%' . $name . '%');
            });
        }

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($amount !== null && $amount !== '') {
            if (!is_numeric($amount)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Amount must be a valid number.'
                ], 422);
            }
            $query->where('amount', $amount);
        }

        if ($dateFromCarbon) {
            $query->where(
                'payment_date',
                '>=',
                $dateFromCarbon->toDateTimeString()
            );
        }

        if ($dateToCarbon) {
            $query->where(
                'payment_date',
                '<=',
                $dateToCarbon->toDateTimeString()
            );
        }

        $payments = $query
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $data = $payments->getCollection()->map(function ($payment) {
            $employee = $payment->employee;
            $company  = $employee?->company;

            return [
                'id' => $payment->id,
                'employee_id' => $payment->employee_id,
                'employee_name' => $payment->employee_name,
                'amount' => (float) $payment->amount,
                'payment_date' => $payment->payment_date
                    ? \Carbon\Carbon::parse($payment->payment_date)
                        ->format('Y-m-d')
                    : null,
                'reference' => $payment->reference,
                'status' => $payment->status,
                'created_at' => $payment->created_at
                    ? $payment->created_at->format('Y-m-d H:i:s')
                    : null,
                'updated_at' => $payment->updated_at
                    ? $payment->updated_at->format('Y-m-d H:i:s')
                    : null,
                'company' => $company ? [
                    'id' => $company->id,
                    'name' => $company->name,
                    'user_id' => $company->user_id,
                    'email' => $company->email,
                    'about' => $company->about,
                    'address' => $company->address,
                    'balance' => $company->balace,
                    'tier'   => $company->tier
                ] : null,
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
                    'employment_type' => $employee->employment_type,
                    'bank_name' => $employee->bank_name,
                    'account_name' => $employee->account_name,
                    'account_number' => $employee->account_number,
                    'estimate_pay' => (float) $employee->estimate_pay,
                    'deduction_amount' => (float) $employee->deduction_amount,
                    'bank_code' => $employee->bank_code,
                ] : null,
            ];
        });

        return response()->json([
            'status'  => true,
            'message' => 'Payment history fetched successfully.',
            'filters' => [
                'status'    => $status ?: 'all',
                'name'      => $name ?: null,
                'date_from' => $dateFrom ?: null,
                'date_to'   => $dateTo ?: null,
                'amount'    => $amount !== null && $amount !== ''
                    ? (float) $amount
                    : null,
            ],

            'data' => $data,
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'per_page'     => $payments->perPage(),
                'total'        => $payments->total(),
                'last_page'    => $payments->lastPage(),
                'from'         => $payments->firstItem(),
                'to'           => $payments->lastItem(),
                'has_more'     => $payments->hasMorePages(),
            ],
        ], 200);
    }

    public function eachAdminPaymentHistory(Request $request, $id)
    {
       $user  = $request->user();
       
       if(!$user){
            return response()->json([
                'status'      => false,
                'message'     => 'Unathenticated to access this.'
            ], 401);
       }

        if (!$this->canManageUsers($user)) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized to access this.'
            ], 403);
        }
        
        $payments = Payment::with(['employee'])
            ->where('id', $id)
            ->with([
                'employee:id,company_id,first_name,last_name,email,phone,address,job_title,paying,employment_type,bank_name,account_name,account_number,estimate_pay,deduction_amount,bank_code',

                'employee.company:id,name,user_id,email,about,logo,address,balance,tier',
            ])
            ->first();

        if(!$payments){
            return response()->json([
                'status'  => false,
                'message' => 'Payment History not found.'
            ], 404);
        }
        return response()->json([
            'status'     =>     false,
            'message'    => 'Payment History fetched successfully.',
            'data'       => $payments
        ]);  
    }
    
    public function retryPayrollPayment(Request $request, $paymentId): JsonResponse
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

        $flutterwaveSecretKey = config('services.flutterwave.secret_key');

        if (!$flutterwaveSecretKey) {
            return response()->json([
                'status'  => false,
                'message' => 'Flutterwave secret key is not configured.',
            ], 500);
        }

        // NEW: fees come from the payment_settings table
        $settings = PaymentSetting::current();

        if (!$settings) {
            return response()->json([
                'status'  => false,
                'message' => 'Payment settings have not been configured. Please contact an admin.',
            ], 422);
        }

        $payment = Payment::with('employee')->find($paymentId);

        if (!$payment) {
            return response()->json([
                'status'  => false,
                'message' => 'Payment not found.',
            ], 404);
        }

        $employee = $payment->employee;

        if (!$employee) {
            return response()->json([
                'status'  => false,
                'message' => 'Employee attached to this payment was not found.',
            ], 404);
        }

        if ($payment->status === 'successful') {
            return response()->json([
                'status'  => false,
                'message' => 'This payment has already been successful. It cannot be retried.',
            ], 422);
        }

        $company = Company::where('id', $employee->company_id)
            ->where('user_id', $admin->id)
            ->first();

        if (!$company) {
            return response()->json([
                'status'  => false,
                'message' => 'You are not authorized to retry this payment.',
            ], 403);
        }

        if (empty($employee->account_number) || empty($employee->bank_code)) {
            return response()->json([
                'status'  => false,
                'message' => 'Employee bank details are incomplete.',
            ], 422);
        }

        // NEW: a pending payment with no transfer id can't be confirmed, and the money may already have left
        if ($payment->status === 'pending' && !$payment->flutterwave_transfer_id) {
            return response()->json([
                'status'  => false,
                'message' => 'This payment could not be confirmed with Flutterwave and may already have been paid. Check the Flutterwave dashboard before retrying.',
            ], 409);
        }

        // NEW: don't pay the same employee twice for the same month
        $paymentMonth = \Carbon\Carbon::parse($payment->payment_date);

        $duplicate = Payment::where('employee_id', $employee->id)
            ->where('id', '!=', $payment->id)
            ->whereIn('status', ['successful', 'pending'])
            ->whereYear('payment_date', $paymentMonth->year)
            ->whereMonth('payment_date', $paymentMonth->month)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'status'  => false,
                'message' => 'This employee already has a successful or pending payment for that month.',
            ], 409);
        }

        $settler = app(PayrollTransferSettler::class);

        // Check the previous transfer first, so we never pay twice
        if ($payment->flutterwave_transfer_id) {
            try {
                $statusResponse = Http::withToken($flutterwaveSecretKey)
                    ->acceptJson()
                    ->timeout(30)
                    ->get('https://api.flutterwave.com/v3/transfers/' . $payment->flutterwave_transfer_id);
            } catch (\Throwable $e) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Could not verify the previous Flutterwave transfer. Payment was not retried to prevent duplicate payment.',
                    'error'   => $e->getMessage(),
                ], 503);
            }

            // CHANGED: if we can't verify the old transfer, stop (the old code carried on and could pay twice)
            if (!$statusResponse->successful()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Could not verify the previous Flutterwave transfer. Payment was not retried to prevent duplicate payment.',
                ], 503);
            }

            $transferData   = $statusResponse->json('data') ?? [];
            $transferStatus = strtoupper($transferData['status'] ?? '');

            if ($transferStatus === 'SUCCESSFUL') {
                if ($payment->status === 'pending') {
                    try {
                        // The money was already reserved, so this just confirms it
                        $settler->finalize($payment->reference, 'SUCCESSFUL');
                    } catch (\Throwable $e) {
                        return response()->json([
                            'status'  => false,
                            'message' => 'Payment was successful on Flutterwave, but local update failed.',
                            'error'   => $e->getMessage(),
                        ], 500);
                    }

                    return response()->json([
                        'status'  => true,
                        'message' => 'Flutterwave confirms this payment was already successful. No new payment was created.',
                        'payment' => $payment->fresh(),
                    ], 200);
                }

                // Marked failed here, but Flutterwave says it went through
                return response()->json([
                    'status'  => false,
                    'message' => 'Flutterwave reports this transfer as successful although it is marked failed here. Do not retry. Please contact support.',
                    'transfer_id' => $payment->flutterwave_transfer_id,
                ], 409);
            }

            if (in_array($transferStatus, ['NEW', 'PENDING'], true)) {
                return response()->json([
                    'status'          => false,
                    'message'         => 'This payment is still being processed by Flutterwave. Do not retry it yet.',
                    'transfer_status' => $transferStatus,
                    'transfer_id'     => $payment->flutterwave_transfer_id,
                ], 409);
            }

            if ($transferStatus === 'FAILED') {
                if ($payment->status === 'pending') {
                    // Refunds the reserved money and marks the payment failed
                    $settler->finalize(
                        $payment->reference,
                        'FAILED',
                        $transferData['complete_message'] ?? null
                    );
                }

                $payment->refresh();
            } else {
                return response()->json([
                    'status'          => false,
                    'message'         => 'Unknown status from Flutterwave for the previous transfer. Payment was not retried.',
                    'transfer_status' => $transferStatus,
                ], 409);
            }
        }

        $amount = (float) $payment->amount;

        // NEW: fee from payment_settings
        $processingFee = $settings->processingFeeFor($amount);
        $totalDebit    = round($amount + $processingFee, 2);

        $retryReference = 'PAYROLL-RETRY-' .
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

        // CHANGED: reserve salary + fee first (company row locked), same as trigerPayroll
        $reservation = DB::transaction(function () use (
            $company, $payment, $employeeName, $processingFee, $totalDebit, $amount, $retryReference
        ) {
            $lockedCompany   = Company::where('id', $company->id)->lockForUpdate()->first();
            $previousBalance = (float) $lockedCompany->balance;

            if ($previousBalance < $totalDebit) {
                return ['reserved' => false, 'available' => $previousBalance];
            }

            $currentBalance = round($previousBalance - $totalDebit, 2);

            $lockedCompany->balance = $currentBalance;
            $lockedCompany->save();

            $payment->update([
                'status'                  => 'pending',
                'reference'               => $retryReference,
                'processing_fee'          => $processingFee,
                'flutterwave_transfer_id' => null,
            ]);

            Transaction::create([
                'company_id'       => $company->id,
                'reference'        => $retryReference,
                'amount'           => $totalDebit,
                'previous_balance' => $previousBalance,
                'current_balance'  => $currentBalance,
                'type'             => 'debit',
                'transaction_type' => 'payroll',
                'status'           => 'pending',
                'description'      => 'Payroll retry payment to ' . $employeeName
                    . ' (Salary: ₦' . number_format($amount, 2)
                    . ', Processing fee: ₦' . number_format($processingFee, 2) . ')',
            ]);

            return ['reserved' => true];
        });

        if (!$reservation['reserved']) {
            return response()->json([
                'status'   => false,
                'message'  => 'Insufficient company balance.',
                'required' => $totalDebit,
                'balance'  => $reservation['available'],
            ], 422);
        }

        try {
            $response = Http::withToken($flutterwaveSecretKey)
                ->acceptJson()
                ->timeout(30)
                ->post(
                    'https://api.flutterwave.com/v3/transfers',
                    [
                        'account_bank'     => $employee->bank_code,
                        'account_number'   => $employee->account_number,
                        'amount'           => $amount,
                        'currency'         => 'NGN',
                        'beneficiary_name' => $employeeName,
                        'reference'        => $retryReference,
                        'debit_currency'   => 'NGN',
                        'narration'        => 'Salary payment retry',
                    ]
                );
        } catch (\Throwable $e) {
            // A timeout doesn't prove the transfer failed. Stay pending, the money stays reserved.
            Log::error('Payroll retry request error', [
                'reference' => $retryReference,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'status'     => false,
                'message'    => 'Flutterwave could not be reached. Payment remains pending and was not marked as failed.',
                'payment_id' => $payment->id,
                'reference'  => $retryReference,
            ], 503);
        }

        // Server error from Flutterwave: outcome unknown, stay pending
        if ($response->status() >= 500) {
            Log::error('Payroll retry 5xx', ['reference' => $retryReference, 'body' => $response->body()]);

            return response()->json([
                'status'     => false,
                'message'    => 'Flutterwave is unavailable. Payment remains pending and will be confirmed automatically.',
                'payment_id' => $payment->id,
                'reference'  => $retryReference,
            ], 503);
        }

        if (!$response->successful()) {
            // Refunds the reserved money and marks the payment failed
            $settler->finalize($retryReference, 'FAILED', 'Flutterwave rejected the request');

            return response()->json([
                'status'     => false,
                'message'    => 'Retry payment request failed.',
                'payment_id' => $payment->id,
                'reference'  => $retryReference,
                'response'   => $response->json(),
            ], 422);
        }

        $flutterwaveData = $response->json();

        if (($flutterwaveData['status'] ?? null) !== 'success') {
            $settler->finalize($retryReference, 'FAILED', $flutterwaveData['message'] ?? null);

            return response()->json([
                'status'     => false,
                'message'    => $flutterwaveData['message'] ?? 'Payment retry failed.',
                'payment_id' => $payment->id,
                'reference'  => $retryReference,
                'response'   => $flutterwaveData,
            ], 422);
        }

        $transferId     = $flutterwaveData['data']['id'] ?? null;
        $transferStatus = strtoupper($flutterwaveData['data']['status'] ?? '');

        if ($transferId) {
            $payment->update(['flutterwave_transfer_id' => (string) $transferId]);
        }

        if ($transferStatus === 'SUCCESSFUL') {
            try {
                $settler->finalize($retryReference, 'SUCCESSFUL');
            } catch (\Throwable $e) {
                return response()->json([
                    'status'      => false,
                    'message'     => 'Flutterwave payment succeeded, but local payment update failed.',
                    'error'       => $e->getMessage(),
                    'transfer_id' => $transferId,
                ], 500);
            }

            return response()->json([
                'status'         => true,
                'message'        => 'Payment retry was successful.',
                'payment_id'     => $payment->id,
                'reference'      => $retryReference,
                'transfer_id'    => $transferId,
                'processing_fee' => $processingFee,
                'payment_status' => 'successful',
            ], 200);
        }

        if (in_array($transferStatus, ['NEW', 'PENDING'], true)) {
            // The final result arrives by webhook or the reconcile command
            return response()->json([
                'status'         => true,
                'message'        => 'Payment retry was accepted by Flutterwave and is currently processing.',
                'payment_id'     => $payment->id,
                'reference'      => $retryReference,
                'transfer_id'    => $transferId,
                'processing_fee' => $processingFee,
                'payment_status' => 'pending',
            ], 202);
        }

        if ($transferStatus === 'FAILED') {
            $reason = $flutterwaveData['data']['complete_message']
                ?? $flutterwaveData['message']
                ?? 'Payment retry failed.';

            $settler->finalize($retryReference, 'FAILED', $reason);

            return response()->json([
                'status'         => false,
                'message'        => $reason,
                'payment_id'     => $payment->id,
                'reference'      => $retryReference,
                'transfer_id'    => $transferId,
                'payment_status' => 'failed',
            ], 422);
        }

        // Unknown status: keep pending rather than guessing
        Log::warning('Unknown Flutterwave status on retry', [
            'reference' => $retryReference,
            'status'    => $transferStatus,
        ]);

        return response()->json([
            'status'         => true,
            'message'        => 'Payment retry was accepted, but Flutterwave returned an unknown status. It will be confirmed automatically.',
            'payment_id'     => $payment->id,
            'reference'      => $retryReference,
            'transfer_id'    => $transferId,
            'payment_status' => 'pending',
        ], 202);
    }
}
 