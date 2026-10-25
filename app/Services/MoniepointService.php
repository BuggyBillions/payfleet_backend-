<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MoniepointService
{
    private function baseUrl(): string
    {
        return rtrim(config('services.moniepoint.base_url'), '/');
    }

    /**
     * ASSUMPTION: verify the exact /v1/auth request and response shape in the docs.
     */
    private function token(): string
    {
        return Cache::remember('moniepoint:token', now()->addMinutes(25), function () {
            $res = Http::timeout(20)->acceptJson()->post($this->baseUrl() . '/v1/auth', [
                'clientId'     => config('services.moniepoint.client_id'),
                'clientSecret' => config('services.moniepoint.client_secret'),
            ]);

            if (!$res->successful()) {
                Log::error('Moniepoint auth failed', ['status' => $res->status(), 'body' => $res->body()]);
                throw new RuntimeException('Moniepoint authentication failed.');
            }

            $token = $res->json('accessToken')
                ?? $res->json('access_token')
                ?? $res->json('token')
                ?? $res->json('data.accessToken')
                ?? $res->json('data.token');

            if (!$token) {
                throw new RuntimeException('Moniepoint auth response had no token.');
            }

            return $token;
        });
    }

    /**
     * Push a payment request to the terminal.
     * $amountNaira is the TOTAL the customer pays (deposit + fee).
     */
    public function pushToTerminal(string $terminalSerial, float $amountNaira, string $merchantReference): array
    {
        $amount = config('services.moniepoint.amount_in_kobo')
            ? (int) round($amountNaira * 100)
            : round($amountNaira, 2);

        $send = fn () => Http::withToken($this->token())
            ->timeout(30)
            ->acceptJson()
            ->post($this->baseUrl() . '/v1/transactions', [
                'terminalSerial'    => $terminalSerial,
                'amount'            => $amount,
                'merchantReference' => $merchantReference,
                'transactionType'   => 'PURCHASE',
                'paymentMethod'     => 'CARD_PURCHASE', // check docs for the transfer option
            ]);

        $res = $send();

        if ($res->status() === 401) {          // token expired: refresh once
            Cache::forget('moniepoint:token');
            $res = $send();
        }

        if (!$res->successful()) {
            Log::error('Moniepoint push failed', [
                'reference' => $merchantReference,
                'status'    => $res->status(),
                'body'      => $res->body(),
            ]);
            throw new RuntimeException('Could not push payment to terminal.');
        }

        return $res->json() ?? [];
    }
}