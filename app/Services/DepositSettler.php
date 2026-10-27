<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\Notification;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositSettler
{
    public function settle(
        string $merchantReference,
        string $moniepointReference,
        int $receivedAmount,
        string $source = 'webhook'
    ): array {
        return DB::transaction(function () use ($merchantReference, $moniepointReference, $receivedAmount, $source) {

            $transaction = Transaction::where('reference', $merchantReference)
                ->where('transaction_type', 'deposit')
                ->where('type', 'CREDIT')
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                Log::error('Deposit not found', ['reference' => $merchantReference, 'source' => $source]);
                return ['status' => false, 'message' => 'Deposit not found.', 'http' => 200];
            }

            // Never credit twice
            if ($transaction->status === 'successful') {
                return [
                    'status'  => $transaction->moniepoint_reference === $moniepointReference,
                    'message' => 'Deposit already processed.',
                    'http'    => 200,
                ];
            }

            if (!in_array($transaction->status, ['pending', 'failed'], true)) {
                return ['status' => false, 'message' => 'Deposit is not pending.', 'http' => 200];
            }

            // Same Moniepoint payment must not fund another deposit
            $used = Transaction::where('moniepoint_reference', $moniepointReference)
                ->where('id', '!=', $transaction->id)
                ->exists();

            if ($used) {
                Log::critical('Moniepoint reference already used', [
                    'moniepoint_reference' => $moniepointReference,
                    'merchant_reference'   => $merchantReference,
                ]);
                return ['status' => false, 'message' => 'Moniepoint reference already used.', 'http' => 200];
            }

            // ---- Amount check (integer kobo) ----
            $depositKobo  = (int) round((float) $transaction->amount * 100);                          // credited
            $feeKobo      = (int) round((float) config('services.moniepoint.deposit_fee', 100) * 100); // not credited
            $expectedKobo = $depositKobo + $feeKobo;

            $matches = false;

            if ($receivedAmount === $expectedKobo) {
                $matches = true;                        // amount in kobo
            } elseif ($receivedAmount * 100 === $expectedKobo) {
                $matches = true;                        // amount came in naira
                Log::warning('Moniepoint amount received in naira, normalised', [
                    'merchant_reference' => $merchantReference,
                    'received'           => $receivedAmount,
                ]);
            }

            if (!$matches) {
                Log::error('Moniepoint amount mismatch', [
                    'merchant_reference' => $merchantReference,
                    'expected_kobo'      => $expectedKobo,
                    'received'           => $receivedAmount,
                    'source'             => $source,
                ]);
                return ['status' => false, 'message' => 'Payment amount mismatch. Manual reconciliation required.', 'http' => 200];
            }

            $company = Company::where('id', $transaction->company_id)->lockForUpdate()->first();

            if (!$company) {
                Log::error('Company not found for deposit', ['reference' => $merchantReference]);
                return ['status' => false, 'message' => 'Company not found.', 'http' => 200];
            }

            // Credit ONLY the deposit amount (fee excluded)
            $previousKobo    = (int) round((float) ($company->balance ?? 0) * 100);
            $newKobo         = $previousKobo + $depositKobo;
            $previousBalance = $previousKobo / 100;
            $creditAmount    = $depositKobo / 100;
            $newBalance      = $newKobo / 100;

            $company->balance = $newBalance;
            $company->save();

            $transaction->moniepoint_reference = $moniepointReference;
            $transaction->status               = 'successful';
            $transaction->previous_balance     = $previousBalance;
            $transaction->current_balance      = $newBalance;
            $transaction->save();

            Deposit::where('transaction_id', $transaction->id)->update(['status' => 'successful']);

            ActivityLog::create([
                'user_id' => $company->user_id,
                'action'  => 'Deposit Confirmed',
                'details' => 'Moniepoint deposit of ₦' . number_format($creditAmount, 2)
                    . ' confirmed via ' . $source . '. Reference: ' . $transaction->reference
                    . '. Moniepoint reference: ' . $moniepointReference
                    . '. New balance: ₦' . number_format($newBalance, 2),
                'type'    => 'transaction',
            ]);

            Notification::create([
                'user_id' => $company->user_id,
                'title'   => 'Deposit Successful',
                'message' => 'Your deposit of ₦' . number_format($creditAmount, 2)
                    . ' has been confirmed. Your new balance is ₦' . number_format($newBalance, 2) . '.',
                'type'    => 'transaction',
                'is_read' => false,
            ]);

            Log::info('Moniepoint deposit credited', [
                'source'               => $source,
                'transaction_id'       => $transaction->id,
                'moniepoint_reference' => $moniepointReference,
                'amount_credited'      => $creditAmount,
                'new_balance'          => $newBalance,
            ]);

            return [
                'status'               => true,
                'message'              => 'Deposit confirmed and company balance updated.',
                'http'                 => 200,
                'reference'            => $transaction->reference,
                'moniepoint_reference' => $moniepointReference,
                'amount_credited'      => $creditAmount,
                'previous_balance'     => $previousBalance,
                'current_balance'      => $newBalance,
            ];
        }, 3);
    }
}