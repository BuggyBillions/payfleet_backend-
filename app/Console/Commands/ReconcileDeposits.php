<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\DepositSettler;
use App\Services\MoniepointService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileDeposits extends Command
{
    protected $signature = 'deposits:reconcile';
    protected $description = 'Check pending Moniepoint deposits and credit the paid ones';

    public function handle(MoniepointService $moniepoint, DepositSettler $settler)
    {
        // Only look at recent deposits. Pushed requests expire in about 5 minutes,
        // so 30 minutes leaves plenty of room and stops us checking old ones forever.
        Transaction::where('transaction_type', 'deposit')
            ->whereIn('status', ['pending', 'failed'])
            ->where('created_at', '>=', now()->subMinutes(30))
            ->orderBy('id')
            ->each(function ($t) use ($moniepoint, $settler) {
                try {
                    $r = $moniepoint->getTransactionByMerchantReference($t->reference);

                    if (empty($r['found'])) {
                        return;
                    }

                    // Paid: credit it (the settler is safe to call more than once)
                    if (!empty($r['successful'])
                        && !empty($r['moniepoint_reference'])
                        && !empty($r['amount_kobo'])) {
                        $settler->settle($t->reference, $r['moniepoint_reference'], (int) $r['amount_kobo'], 'reconcile');
                        return;
                    }

                    // Moniepoint says the request expired or failed: stop waiting for it
                    if (in_array($r['status'] ?? '', ['EXPIRED', 'FAILED', 'CANCELLED', 'CANCELED', 'DECLINED', 'REJECTED'], true)
                        && $t->status === 'pending') {
                        $t->update(['status' => 'failed']);
                        Log::info('Deposit marked failed (not paid)', [
                            'reference'         => $t->reference,
                            'moniepoint_status' => $r['status'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('Reconcile failed', ['reference' => $t->reference, 'error' => $e->getMessage()]);
                }
            });

        return self::SUCCESS;
    }
}