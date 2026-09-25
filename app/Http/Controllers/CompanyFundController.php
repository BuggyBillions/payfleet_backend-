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

    public function resolveBankAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_number' => ['required', 'digits:10'],
            'bank_code' => ['required', 'string', 'max:20'],
        ]);

        $payload = $this->resolveAccountWithPaystack($data['account_number'], $data['bank_code']);

        return response()->json([
            'message' => data_get($payload, 'message', 'Account number resolved.'),
            'data' => [
                'account_name' => data_get($payload, 'data.account_name'),
                'account_number' => data_get($payload, 'data.account_number', $data['account_number']),
                'bank_name' => $this->resolveBankNameByCode($data['bank_code']),
                'bank_code' => $data['bank_code'],
            ],
        ]);
    }

    private function resolveAccountWithPaystack(string $accountNumber, string $bankCode): array
    {
        $secretKey = (string) config('services.paystack.secret_key');
        $baseUrl = rtrim((string) config('services.paystack.base_url'), '/');

        if ($secretKey === '') {
            throw ValidationException::withMessages([
                'bank_code' => 'Paystack secret key is not configured.',
            ]);
        }

        $response = Http::withToken($secretKey)
            ->acceptJson()
            ->get($baseUrl.'/bank/resolve', [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

        if ($response->failed()) {
            throw ValidationException::withMessages([
                'account_number' => $response->json('message') ?? 'Unable to validate account details.',
            ]);
        }

        $payload = $response->json();

        if (! is_array($payload) || data_get($payload, 'status') !== true) {
            throw ValidationException::withMessages([
                'account_number' => data_get($payload, 'message', 'Unable to validate account details.'),
            ]);
        }

        return $payload;
    }

    private function resolveBankNameByCode(string $bankCode): ?string
    {
        $secretKey = (string) config('services.paystack.secret_key');
        $baseUrl = rtrim((string) config('services.paystack.base_url'), '/');

        if ($secretKey === '') {
            return null;
        }

        $response = Http::withToken($secretKey)
            ->acceptJson()
            ->get($baseUrl.'/bank', [
                'country' => 'nigeria',
                'code' => $bankCode,
            ]);

        if ($response->failed()) {
            return null;
        }

        $banks = data_get($response->json(), 'data', []);

        if (! is_array($banks) || $banks === []) {
            return null;
        }

        $matchingBank = collect($banks)->first(static function ($bank) use ($bankCode) {
            return strtolower((string) data_get($bank, 'code', '')) === strtolower($bankCode);
        });

        return is_array($matchingBank) ? data_get($matchingBank, 'name') : null;
    }

    public function listBanks(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $secretKey = config('services.paystack.secret_key');
        $baseUrl = rtrim(config('services.paystack.base_url'), '/');

        if (!$secretKey) {
            return response()->json([
                'status' => false,
                'message' => 'Paystack secret key is not configured.'
            ], 500);
        }

        $response = Http::withToken($secretKey)
            ->acceptJson()
            ->get($baseUrl . '/bank', [
                'country' => 'nigeria',
                'perPage' => 100,
            ]);

        if ($response->failed()) {
            return response()->json([
                'status' => false,
                'message' => $response->json('message') ?? 'Unable to fetch banks.'
            ], $response->status());
        }

        $banks = collect($response->json('data', []));

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

        $deposits = Deposit::with([
                'transaction',
                'company'
            ])
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {

                    $q->where('amount', 'LIKE', '%' . $search . '%')
                        ->orWhere('company_id', 'LIKE', '%' . $search . '%')
                        ->orWhereHas('company', function ($companyQuery) use ($search) {
                            $companyQuery->where('name', 'LIKE', '%' . $search . '%')
                                ->orWhere('email', 'LIKE', '%' . $search . '%')
                                ->orWhere('phone', 'LIKE', '%' . $search . '%');
                        })
                        ->orWhereHas('transaction', function ($transactionQuery) use ($search) {
                            $transactionQuery->where('status', 'LIKE', '%' . $search . '%')
                            ->orWhere('reference', 'LIKE', '%' . $search . '%');
                        });
                });
            })
            ->latest()
            ->paginate(20);

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
            ->paginate(20);

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
