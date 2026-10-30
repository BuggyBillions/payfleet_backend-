<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employees;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PayrollTransferSettler
{
    /**
     * Finalize a payroll transfer as SUCCESSFUL or FAILED.
     * Safe to call more than once for the same reference.
     */
    public function finalize(string $reference, string $status, ?string $message = null): array
    {
        $status = strtoupper($status);

        if (!in_array($status, ['SUCCESSFUL', 'FAILED'], true)) {
            return ['ok' => false, 'message' => 'Status is not final.'];
        }

        return DB::transaction(function () use ($reference, $status, $message) {

            $payment = Payment::where('reference', $reference)->lockForUpdate()->first();

            if (!$payment) {
                return ['ok' => false, 'message' => 'Payment not found.'];
            }

            if ($payment->status !== 'pending') {
                return ['ok' => true, 'already' => true, 'message' => 'Payment already processed.'];
            }

            $employee = Employees::find($payment->employee_id);

            $txn = Transaction::where('reference', $reference)
                ->where('transaction_type', 'payroll')
                ->lockForUpdate()
                ->first();

            // ---------- FAILED ----------
            if ($status === 'FAILED') {
                if ($txn) {
                    // The company was debited when the transfer was started, so give it back
                    $company = Company::where('id', $txn->company_id)->lockForUpdate()->first();

                    $previous = (float) $company->balance;
                    $refund   = (float) $txn->amount;
                    $current  = round($previous + $refund, 2);

                    $company->balance = $current;
                    $company->save();

                    $txn->update(['status' => 'failed']);

                    Transaction::create([
                        'company_id'       => $company->id,
                        'reference'        => $reference . '-REFUND',
                        'amount'           => $refund,
                        'previous_balance' => $previous,
                        'current_balance'  => $current,
                        'type'             => 'credit',
                        'transaction_type' => 'payroll_refund',
                        'status'           => 'successful',
                        'description'      => 'Refund for failed payroll payment to ' . $payment->employee_name
                            . ($message ? ' (' . $message . ')' : ''),
                    ]);
                }
                // No transaction = older payment that never debited the company, nothing to refund

                $payment->update(['status' => 'failed']);

                return ['ok' => true, 'message' => 'Payment marked failed and company refunded.'];
            }

            // ---------- SUCCESSFUL ----------
            if ($txn) {
                $txn->update(['status' => 'successful']);
            } else {
                // Older pending payment (created before this change): the company was never debited
                if (!$employee) {
                    return ['ok' => false, 'message' => 'Employee not found for legacy payment.'];
                }

                $company = Company::where('id', $employee->company_id)->lockForUpdate()->first();

                $fee      = PaymentSetting::current()?->processingFeeFor((float) $payment->amount) ?? 0;
                $debit    = round((float) $payment->amount + $fee, 2);
                $previous = (float) $company->balance;
                $current  = round($previous - $debit, 2);

                if ($current < 0) {
                    Log::warning('Legacy payroll debit made balance negative', [
                        'reference' => $reference,
                        'company'   => $company->id,
                    ]);
                }

                $company->balance = $current;
                $company->save();

                Transaction::create([
                    'company_id'       => $company->id,
                    'reference'        => $reference,
                    'amount'           => $debit,
                    'previous_balance' => $previous,
                    'current_balance'  => $current,
                    'type'             => 'debit',
                    'transaction_type' => 'payroll',
                    'status'           => 'successful',
                    'description'      => 'Payroll payment to ' . $payment->employee_name
                        . ' (Salary: ₦' . number_format((float) $payment->amount, 2)
                        . ', Processing fee: ₦' . number_format($fee, 2) . ')',
                ]);
            }

            $payment->update(['status' => 'successful']);

            if ($employee && $employee->user_id) {
                Notification::create([
                    'user_id' => $employee->user_id,
                    'title'   => 'Salary Payment',
                    'message' => 'Your salary payment of ₦' . number_format((float) $payment->amount, 2)
                        . ' has been processed successfully.',
                    'type'    => 'payroll',
                    'is_read' => false,
                ]);
            }

            return ['ok' => true, 'message' => 'Payment marked successful.'];
        });
    }
}