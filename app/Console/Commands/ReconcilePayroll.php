<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PayrollTransferSettler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReconcilePayroll extends Command
{
    protected $signature = 'payroll:reconcile';
    protected $description = 'Confirm pending payroll transfers with Flutterwave';

    public function handle(PayrollTransferSettler $settler)
    {
        $key = config('services.flutterwave.secret_key');

        Payment::where('status', 'pending')
            ->whereNotNull('flutterwave_transfer_id')
            ->where('created_at', '<=', now()->subMinute())
            ->where('created_at', '>=', now()->subDays(3))
            ->each(function ($payment) use ($settler, $key) {
                try {
                    $res = Http::withToken($key)->acceptJson()->timeout(20)
                        ->get('https://api.flutterwave.com/v3/transfers/' . $payment->flutterwave_transfer_id);

                    if (!$res->successful()) {
                        return;
                    }

                    $status = strtoupper((string) $res->json('data.status'));

                    if (in_array($status, ['SUCCESSFUL', 'FAILED'], true)) {
                        $settler->finalize($payment->reference, $status, $res->json('data.complete_message'));
                    }
                } catch (\Throwable $e) {
                    Log::error('Payroll reconcile failed', ['reference' => $payment->reference, 'error' => $e->getMessage()]);
                }
            });

        return self::SUCCESS;
    }
}