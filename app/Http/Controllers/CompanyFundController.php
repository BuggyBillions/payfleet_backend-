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
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class CompanyFundController extends Controller
{
    private function isFullCompany(User $user): bool
    {
        return $user->role === 'company';
    }  
    
    private function isStaff(User $user) : bool 
    {
      return $user->role === 'finance';   
    }

    private function isFullAdmin(User $user) : bool{
        return $user->role === 'admin';
    }

    private function canManageUsers(User $user): bool
    {
        return ($user->is_active == 1 &&
            (
                $this->isFullAdmin($user) ||
                $this->isStaff($user) 
            )
        );
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
                'data'       => $transaction,
                'checkout_amount' => (float) $validated['amount'] + 100
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

    public function listFlutterwaveBanks(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $secretKey = config('services.flutterwave.secret_key');

        if (!$secretKey) {
            return response()->json([
                'status' => false,
                'message' => 'Flutterwave secret key is not configured.'
            ], 500);
        }

        $response = Http::withToken($secretKey)
            ->acceptJson()
            ->get('https://api.flutterwave.com/v3/banks/NG');

        if ($response->failed()) {
            return response()->json([
                'status' => false,
                'message' => $response->json('message') ?? 'Unable to fetch banks.',
                'error' => $response->json()
            ], $response->status());
        }

        $payload = $response->json();

        if (($payload['status'] ?? null) !== 'success') {
            return response()->json([
                'status' => false,
                'message' => $payload['message'] ?? 'Unable to fetch banks.'
            ], 400);
        }

        $banks = collect($payload['data'] ?? []);

        if ($request->filled('search')) {
            $search = strtolower(trim($request->search));

            $banks = $banks->filter(function ($bank) use ($search) {
                return str_contains(
                    strtolower($bank['name'] ?? ''),
                    $search
                );
            });
        }

        $banks = $banks
            ->map(function ($bank) {
                return [
                    'id' => $bank['id'] ?? null,
                    'name' => $bank['name'] ?? null,
                    'code' => $bank['code'] ?? null,
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Banks fetched successfully.',
            'data' => $banks
        ], 200);
    }

    public function resolveFlutterwaveBankAccount(Request $request): JsonResponse
    {
        $request->merge([
            'account_number' => trim((string) $request->input('account_number')),
            'bank_code' => trim((string) $request->input('bank_code')),
        ]);

        $request->validate([
            'account_number' => ['required','string','regex:/^\d{10}$/',],
            'bank_code' => ['required','string','max:20',],
        ]);

        try {
            $secretKey = config('services.flutterwave.secret_key');

            if (!$secretKey) {
                return response()->json([
                    'status' => false,
                    'message' => 'Flutterwave secret key is not configured.'
                ], 500);
            }

            $accountNumber = $request->account_number;
            $bankCode = $request->bank_code;

            $response = Http::withToken($secretKey)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(60)
                ->post(
                    'https://api.flutterwave.com/v3/accounts/resolve',
                    [
                        'account_number' => $accountNumber,
                        'account_bank' => $bankCode,
                    ]
                );

            $body = $response->json();

            Log::info('Flutterwave Bank Account Resolution', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'response' => $body,
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'status' => false,
                    'message' => data_get(
                        $body,
                        'message',
                        'Unable to resolve bank account.'
                    ),
                    'error' => $body,
                ], $response->status());
            }

            if (($body['status'] ?? null) !== 'success') {
                return response()->json([
                    'status' => false,
                    'message' => data_get(
                        $body,
                        'message',
                        'Unable to resolve bank account.'
                    ),
                    'error' => $body,
                ], 422);
            }

            $accountData = data_get($body, 'data', []);

            $accountName = data_get($accountData, 'account_name');
            $resolvedAccountNumber = data_get(
                $accountData,
                'account_number',
                $accountNumber
            );

            if (!$accountName) {
                Log::warning('Flutterwave Missing Account Name', [
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'response' => $body,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Flutterwave could not return the account name.',
                    'error' => $body,
                ], 422);
            }

            $bankName = $this->getFlutterwaveBankName($bankCode,$secretKey);

            return response()->json([
                'status' => true,
                'message' => 'Account resolved successfully.',
                'data' => [
                    'account_name' => $accountName,
                    'account_number' => $resolvedAccountNumber,
                    'bank_code' => $bankCode,
                    'bank_name' => $bankName,
                ],
            ], 200);

        } catch (\Throwable $e) {

            Log::error('Flutterwave Bank Account Resolution Exception', [
                'account_number' => $request->account_number ?? null,
                'bank_code' => $request->bank_code ?? null,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function getFlutterwaveBankName(string $bankCode,string $secretKey
    ): ?string {
        try {
            $response = Http::withToken($secretKey)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(60)
                ->get(
                    'https://api.flutterwave.com/v3/banks/NG'
                );

            $body = $response->json();

            Log::info('Flutterwave Bank List', [
                'bank_code' => $bankCode,
                'status' => $response->status(),
                'response' => $body,
            ]);

            if (!$response->successful()) {
                return null;
            }

            if (($body['status'] ?? null) !== 'success') {
                return null;
            }

            $banks = data_get($body, 'data', []);

            if (!is_array($banks)) {
                return null;
            }

            foreach ($banks as $bank) {
                if (!is_array($bank)) {
                    continue;
                }

                $code = data_get($bank, 'code');
                $name = data_get($bank, 'name');

                if ((string) $code === (string) $bankCode) {
                    return $name;
                }
            }

            return null;

        } catch (\Throwable $e) {
            Log::error('Flutterwave Get Bank Name Exception', [
                'bank_code' => $bankCode,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getDeposit(Request $request)
    {
        $admin = $request->user();

        if (!$this->canManageUsers($admin)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $search = $request->input('search');
        $status = $request->input('status');

        $deposits = Deposit::with([
            'company',
            'transaction'
        ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('amount', 'LIKE', '%' . $search . '%')
                        ->orWhere('company_id', 'LIKE', '%' . $search . '%')
                        ->orWhereHas('company', function ($companyQuery) use ($search) {
                            $companyQuery->where('name', 'LIKE', '%' . $search . '%')
                                ->orWhere('email', 'LIKE', '%' . $search . '%')
                                ->orWhere('phone', 'LIKE', '%' . $search . '%');
                        });
                });
            })

            ->when($status && $status !== 'all', function ($query) use ($status) {
                $query->whereHas('transaction', function ($transactionQuery) use ($status) {
                    $transactionQuery->where('status', $status);
                });
            })

            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'status' => true,
            'message' => 'Deposits fetched successfully',
            'data' => $deposits
        ], 200);
    }

    public function eachDeposit(Request $request , $id)
    {
        $admin  = $request->user();

        if(!$this->canManageUsers($admin)){
            return response ()->json([
                'status'   => false,
                'message'  => 'Unathorized for this endpoint.'
            ], 403);
        }

        $deposits = Deposit::with([
                'transaction',
                'company'
            ])
            ->first();

        if(!$deposits){
            return response()->json([
                'status'    =>  false, 
                'message'   =>  'Deposit not found'
            ], 404);
        }  
        
        return response()->json([
            'status'    =>  true,
            'message'   => 'Deposit fet5ach successfully.',
            'data'      => $deposits
        ]);
    }

    public function getCompanyDeposit(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company account not found.'
            ], 404);
        }

        $search = $request->input('search');

        $deposits = Deposit::with([
                'transaction',
                'company'
            ])
            ->where('company_id', $company->id)
            ->when($search, function ($query) use ($search) {

                $query->where(function ($q) use ($search) {

                    $q->where('amount', 'LIKE', '%' . $search . '%')
                        ->orWhereHas('transaction', function ($transactionQuery) use ($search) {
                            $transactionQuery
                                ->where('reference', 'LIKE', '%' . $search . '%')
                                ->orWhere('status', 'LIKE', '%' . $search . '%')
                                ->orWhere('transaction_type', 'LIKE', '%' . $search . '%')
                                ->orWhere('description', 'LIKE', '%' . $search . '%');
                        });
                });
            })
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'status' => true,
            'message' => 'Company deposits fetched successfully.',
            'data' => $deposits
        ], 200);
    }

    public function getCompanyDepositDetails(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $company = Company::where('user_id', $user->id)->first();

        if (!$company) {
            return response()->json([
                'status' => false,
                'message' => 'Company account not found.'
            ], 404);
        }

        $deposit = Deposit::with([
                'transaction',
                'company'
            ])
            ->where('id', $id)
            ->where('company_id', $company->id)
            ->first();

        if (!$deposit) {
            return response()->json([
                'status' => false,
                'message' => 'Deposit not found.'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Deposit details fetched successfully.',
            'data' => $deposit
        ], 200);
    }
}
