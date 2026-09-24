<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\Employees;
use App\Models\Notification;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompanyFundController extends Controller
{
    private function isFullCompany(User $user): bool
    {
        return $user->role === 'company';
    }    
        
    public function Deposit(Request $request)  {
        $admin = $request->user();
        $user = $request->user();

        if(!$admin){
            return response()->json([
                'status'    => false,
                'message'   => 'Unauthenticated.'
            ], 401);
        }

        if(!$this->isFullCompany($admin)){
            return response()->json([
                'status'    =>  false,
                'message'   =>  "Only Company can fund account"
            ], 403);
        }

        $company  = Company::where('user_id', $user->id)->first();

        $validated  = $request->validate([
            'amount'       => ['required'],
            'company_id'   => ['required'],
        ]);

        DB::beginTransaction();
        try{
            $currentBalance = $company->balance;

            $transaction   = Transaction::create([
                'company_id'       => $validated['company_id'],
                'reference'        => 'PAY-DEP-' . strtoupper(Str::random(15)),
                'amount'           => $validated['amount'],
                'previous_balance' => $currentBalance,
                'current_balance'  => $currentBalance,
                'type'             => 'CREDIT',
                'transaction_type' => 'deposit',
                'status'           => 'pending',
                'description'      => 'Company deposit'
            ]);
            
            $deposit  =  Deposit::create([
                'amount'         =>  $validated['amount'],
                'company_id'     =>  $validated['company_id'],
                'transaction_id' =>  $transaction->id,
            ]); 

            ActivityLog::create([
                'user_id' => $user->id,
                'action' => 'Company funding',
                'details' => 'Company sucsessfully deposited money',
                'type' => 'Deposit'
            ]);

            Notification::create([
                'user_id' => $user->id,
                'title' => 'Company Deposit',
                'message' => 'Company Account deposited, awaiting approval.',
                'type' => 'Deposit'
            ]);

            DB::commit();

            return response()->json([
                "status"     => true,
                "message"    => "Company Account successfully funded", 
                'data'       => $transaction
            ]);
        }catch(\Exception $e){
            DB::rollBack();
            return response ()->json([
                'status'     =>  false,
                'messgae'    => 'Company deposit failed.',
                'error'      => $e->getMessage()
            ], 500);
        }
    }

    public function testNomba()
    {
        try {
            $baseUrl = rtrim(config(  'services.nomba.base_url',  'https://api.nomba.com'),
                '/'
            );
            $accountId = config('services.nomba.account_id');
            $clientId = config(  'services.nomba.client_id');
            $clientSecret = config(  'services.nomba.client_secret');

            if (!$accountId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nomba account ID is missing.'
                ], 500);
            }

            if (!$clientId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nomba client ID is missing.'
                ], 500);
            }

            if (!$clientSecret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nomba client secret is missing.'
                ], 500);
            }

            $authUrl = $baseUrl . '/v1/auth/token/issue';

            $authResponse = Http::withOptions([ 'force_ip_resolve' => 'v4',])
            ->withHeaders([
                'accountId' =>  $accountId,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(60)
            ->post(
                $authUrl,
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]
            );

            $authBody =   $authResponse->json();
            Log::info(
                'Nomba Test Authentication Response',
                [
                    'url' =>  $authUrl,
                    'status' =>  $authResponse->status(),
                    'successful' =>  $authResponse->successful(),
                    'body' => $authResponse->body(),
                    'json' => $authBody,
                ]
            );

            if (!$authResponse->successful()) {
                return response()->json([
                    'success' =>  false,
                    'message' => 'Nomba authentication request failed.',
                    'status' => $authResponse->status(),
                    'error' =>
                        $authBody
                        ??
                        $authResponse->body(),
                ], $authResponse->status());
            }

            $token =data_get($authBody, 'data.access_token');

            if (!$token) {
                return response()->json([
                    'success' =>  false,
                    'message' => 'Nomba authentication succeeded but no access token was returned.',
                    'response' => $authBody,
                ], 502);
            }

            $bankUrl = $baseUrl . '/v1/transfers/banks';

            $bankResponse = Http::withOptions([ 'force_ip_resolve' => 'v4',])
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'accountId' => $accountId,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(60)
            ->get($bankUrl);
            $body = $bankResponse->body();
            $json = $bankResponse->json();
            $contentType = $bankResponse->header(  'Content-Type');

            Log::info(
                'Nomba Test Bank Response',
                [
                    'url' => $bankUrl,
                    'account_id' => $accountId,
                    'status' => $bankResponse->status(),
                    'successful' => $bankResponse->successful(),
                    'content_type' => $contentType,
                    'body' => $body,
                    'json' => $json,
                ]
            );

            if ($bankResponse->failed()) {
                return response()->json([
                    'success' =>   false,
                    'message' => 'Nomba bank list request failed.',
                    'status' => $bankResponse->status(),
                    'content_type' => $contentType,
                    'error' => $json ?? $body,
                ], $bankResponse->status());
            }

            if ($json === null) {
                return response()->json([
                    'success' =>  false,
                    'message' =>  'Nomba returned an empty or invalid JSON response.',
                    'status' =>  $bankResponse->status(),
                    'content_type' => $contentType,
                    'raw_body' => $body,
                ], 502);
            }

            if ( isset($json['code']) && $json['code'] !== '00') {
                return response()->json([
                    'success' => false,
                    'message' =>
                        $json['description']
                        ??
                        $json['message']
                        ??
                        'Nomba bank list request was not successful.',
                    'status' => $bankResponse->status(),
                    'nomba_response' =>  $json,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Nomba connection and bank list test successful.',
                'authentication' => [
                    'success' =>true,
                    'status' => $authResponse->status(),
                ],
                'bank_list' => [
                    'success' => true,
                    'status' => $bankResponse->status(),
                    'content_type' => $contentType,
                    'data' => $json,
                ],
            ]);


        } catch (\Throwable $e) {
            Log::error(
                'Nomba Test Exception',
                [
                    'message' =>   $e->getMessage(),
                    'file' =>  $e->getFile(),
                    'line' =>  $e->getLine(),
                    'trace' =>  $e->getTraceAsString(),
                ]
            );

            return response()->json([
                'success' =>   false,
                'message' =>  $e->getMessage(),
            ], 500);
        }
    }

    public function resolveBankAccount(Request $request)
    {
        $request->merge([
            'account_number' => trim( (string) $request->input('account_number') ),
            'bank_code' => trim((string) $request->input('bank_code')),
        ]);

        $request->validate([
            'account_number' => ['required', 'string',  'regex:/^\d{10}$/',],
            'bank_code' => [ 'required', 'string',],
        ]);
    
        try {
            $token = $this->getNombaAccessToken();
            if (!$token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to authenticate with Nomba.',
                ], 500);
            }
    
            $baseUrl = rtrim(
                config(
                    'services.nomba.base_url',
                    'https://api.nomba.com'
                ),
                '/'
            );
            $accountId = config( 'services.nomba.account_id');
            $url = $baseUrl . '/v1/transfers/bank/lookup';
            $response = Http::withOptions([ 'force_ip_resolve' => 'v4',])
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'accountId' =>  $accountId,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(60)
            ->post(
                $url,
                [
                    'accountNumber' => $request->account_number,
                    'bankCode' => $request->bank_code,
                ]
            );
    
            $body = $response->json();
    
            Log::info(
                'Nomba Bank Account Resolution',
                [
                    'account_number' =>  $request->account_number,
                    'bank_code' =>  $request->bank_code,
                    'url' =>  $url,
                    'http_status' =>  $response->status(),
                    'successful' =>  $response->successful(),
                    'body' => $response->body(),
                    'json' =>  $body,
                ]
            );
    
            if ($response->status() === 403) {
                return response()->json([
                    'success' => false,
                    'message' =>  'Nomba rejected the request with HTTP 403. Please confirm that your server IP is allowed by Nomba and that your API credentials are correct.',
                    'error' =>  $body,

                ], 403);
            }
    
            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        data_get(
                            $body,
                            'description'
                        )
                        ??
                        data_get(
                            $body,
                            'message'
                        )
                        ??
                        'Unable to resolve bank account with Nomba.',
    
                    'error' =>
                        $body,
    
                ], 422);
            }
    
            if (
                !isset($body['code'])
                ||
                $body['code'] !== '00'
            ) {
    
                return response()->json([
                    'success' => false,
                    'message' =>
                        data_get(
                            $body,
                            'description'
                        )
                        ??
                        data_get(
                            $body,
                            'message'
                        )
                        ??
                        'Unable to resolve bank account.',
    
                    'error' =>
                        $body,
    
                ], 422);
            }
    
            $accountData =
                $body['data']
                ??
                [];

    
            $accountName =
                data_get(
                    $accountData,
                    'accountName'
                )
                ??
                data_get(
                    $accountData,
                    'account_name'
                )
                ??
                data_get(
                    $accountData,
                    'name'
                );
            $accountNumber =
                data_get(
                    $accountData,
                    'accountNumber'
                )
                ??
                data_get(
                    $accountData,
                    'account_number'
                )
                ??
                $request->account_number;
    
            $bankCode =
                data_get(
                    $accountData,
                    'bankCode'
                )
                ??
                data_get(
                    $accountData,
                    'bank_code'
                )
                ??
                $request->bank_code;

            $bankName =
                data_get(
                    $accountData,
                    'bankName'
                )
                ??
                data_get(
                    $accountData,
                    'bank_name'
                );
    
            if (!$bankName) {
    
                $bankName =
                    $this->getNombaBankName(
                        $bankCode,
                        $token
                    );
            }
  
            if (!$accountName) {
    
                Log::warning(
                    'Nomba Bank Account Resolution Missing Account Name',
                    [
                        'account_number' =>
                            $request->account_number,
    
                        'bank_code' =>
                            $request->bank_code,
    
                        'response' =>
                            $body,
                    ]
                );
    
                return response()->json([
                    'success' => false,
    
                    'message' =>
                        'Nomba could not return the account name.',
    
                    'error' =>
                        $body,
    
                ], 422);
            }
    
            if (!$bankName) {
    
                Log::warning(
                    'Nomba Bank Name Not Found',
                    [
                        'bank_code' =>
                            $bankCode,
    
                        'account_number' =>
                            $accountNumber,
    
                        'account_response' =>
                            $body,
                    ]
                );
    
                return response()->json([
                    'success' => false,
    
                    'message' =>
                        'Account was resolved, but the bank name could not be found for bank code ' . $bankCode,
    
                    'data' => [
                        'account_name' =>
                            $accountName,
    
                        'account_number' =>
                            $accountNumber,
    
                        'bank_code' =>
                            $bankCode,
    
                        'bank_name' =>
                            null,
                    ],
    
                ], 422);
            }
    
            return response()->json([
                'success' =>
                    true,
    
                'message' =>
                    'Account resolved successfully',
    
                'data' => [
    
                    'account_name' =>
                        $accountName,
    
                    'account_number' =>
                        $accountNumber,
    
                    'bank_code' =>
                        $bankCode,
    
                    'bank_name' =>
                        $bankName,
                ],
            ]);
    
    
        } catch (\Throwable $e) {
    
            Log::error(
                'Nomba Bank Account Resolution Exception',
                [
                    'account_number' =>
                        $request->account_number,
    
                    'bank_code' =>
                        $request->bank_code,
    
                    'message' =>
                        $e->getMessage(),
    
                    'file' =>
                        $e->getFile(),
    
                    'line' =>
                        $e->getLine(),
    
                    'trace' =>
                        $e->getTraceAsString(),
                ]
            );
    
    
            return response()->json([
                'success' =>
                    false,
    
                'message' =>
                    $e->getMessage(),
    
            ], 500);
        }
    }
}
