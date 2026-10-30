<?php

namespace App\Http\Controllers;

use App\Services\PayrollTransferSettler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FlutterwaveWebhookController extends Controller
{
    public function handle(Request $request, PayrollTransferSettler $settler): JsonResponse
    {
        $secretHash = config('services.flutterwave.webhook_hash');
        $signature  = $request->header('verif-hash');

        if (!$secretHash || !$signature || !hash_equals($secretHash, $signature)) {
            Log::warning('Flutterwave webhook rejected: bad signature');
            return response()->json(['status' => false], 401);
        }

        $payload = $request->all();

        Log::info('Flutterwave webhook received', ['payload' => $payload]);

        if (($payload['event'] ?? '') !== 'transfer.completed') {
            return response()->json(['status' => true, 'message' => 'Event ignored.']);
        }

        $data      = $payload['data'] ?? [];
        $reference = $data['reference'] ?? null;
        $status    = strtoupper((string) ($data['status'] ?? ''));

        if (!$reference || !str_starts_with($reference, 'PAYROLL-')) {
            return response()->json(['status' => true, 'message' => 'Not a payroll transfer.']);
        }

        try {
            $settler->finalize($reference, $status, $data['complete_message'] ?? null);
        } catch (\Throwable $e) {
            Log::error('Payroll webhook failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            // 500 lets Flutterwave retry
            return response()->json(['status' => false], 500);
        }

        return response()->json(['status' => true]);
    }
}