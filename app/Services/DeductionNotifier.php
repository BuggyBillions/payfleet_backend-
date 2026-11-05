<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Deduction;
use App\Models\Employees;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeductionNotifier
{
    /**
     * Email the employee if this successful payment had a deduction applied.
     * Returns true only if an email was actually sent.
     */
    public function send(int $paymentId): bool
    {
        $payment = Payment::find($paymentId);

        if (!$payment || $payment->status !== 'successful') {
            return false;
        }

        $employee = Employees::find($payment->employee_id);

        if (!$employee) {
            return false;
        }

        // Same rule as trigerPayroll: latest deduction, active for no_of_month months,
        // judged as at the time the payroll was run (not "now")
        $asOf = Carbon::parse($payment->created_at ?? now());

        $deduction = Deduction::where('employee_id', $employee->id)
            ->where('created_at', '<=', $asOf)
            ->latest('created_at')
            ->first();

        if (!$deduction) {
            return false;
        }

        $months = (int) $deduction->no_of_month;

        if ($months <= 0 || !$asOf->lt(Carbon::parse($deduction->created_at)->addMonths($months))) {
            return false;
        }

        $deductionAmount = (float) $deduction->amount;

        if ($deductionAmount <= 0) {
            return false;
        }

        $net   = (float) $payment->amount;
        $gross = round($net + $deductionAmount, 2);

        $email = $employee->email;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Deduction email skipped: no valid email', [
                'payment_id'  => $payment->id,
                'employee_id' => $employee->id,
            ]);
            return false;
        }

        $apiKey   = config('services.termii.api_key');
        $configId = config('services.termii.email_configuration_id');
        $template = config('services.termii.deduction_template');
        $base     = rtrim((string) config('services.termii.base_url'), '/');

        if (!$apiKey || !$configId || !$template || !$base) {
            Log::warning('Deduction email not sent: Termii is not fully configured', [
                'payment_id' => $payment->id,
            ]);
            return false;
        }

        $companyName = (string) Company::where('id', $employee->company_id)->value('name');
        $payDate     = Carbon::parse($payment->payment_date);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($base . '/api/templates/send-email', [
                    'api_key'                => $apiKey,
                    'email'                  => $email,
                    'subject'                => 'Salary paid: deduction notice',
                    'email_configuration_id' => $configId,
                    'template_id'            => $template,
                    'variables'              => [
                        'name'             => $payment->employee_name ?: 'Employee',
                        'company_name'     => $companyName,
                        'month'            => $payDate->format('F Y'),
                        'gross_salary'     => number_format($gross, 2),
                        'deduction_amount' => number_format($deductionAmount, 2),
                        'net_salary'       => number_format($net, 2),
                        'reason'           => $deduction->reason ?: 'Salary deduction',
                        'reference'        => $payment->reference,
                        'date'             => $payDate->format('d M Y'),
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('Deduction email error', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            return false;
        }

        if (!$response->successful()) {
            Log::error('Deduction email failed', [
                'payment_id' => $payment->id,
                'status'     => $response->status(),
                'body'       => $response->body(),
            ]);
            return false;
        }

        Log::info('Deduction email sent', ['payment_id' => $payment->id, 'email' => $email]);

        return true;
    }
}