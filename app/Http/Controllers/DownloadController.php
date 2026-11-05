<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DownloadController extends Controller
{
    /**
     * Table headers for the payment export.
     */
    public const PAYMENT_HEADERS = [
        'S/N',
        'Employee Name',
        'Email',
        'Phone Number',
        'Job Title',
        'Amount',
        'Payment Status',
        'Payment Reference',
        'Payment Date',
        'Bank Name',
        'Account Name',
        'Account Number',
    ];

    /**
     * Export company salary payments. Saves the file and returns its download URL.
     *
     * Query params: month (Y-m), date_from, date_to, status, format (xls|xlsx|excel|csv),
     * download=true (returns the file itself instead of JSON).
     */
    public function exportPayments(Request $request)
    {
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '300');

        try {

            // 1. Authenticate user
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'status'  => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            // 2. Get authenticated company
            $company = Company::where('user_id', $user->id)->first();

            if (!$company) {
                return response()->json([
                    'success' => false,
                    'status'  => false,
                    'message' => 'Company account not found.',
                ], 404);
            }

            // 3. Validate request
            $validator = Validator::make($request->all(), [
                'month'     => 'nullable|date_format:Y-m',
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to'   => 'nullable|date_format:Y-m-d',
                'status'    => 'nullable|in:all,pending,successful,failed,cancelled',
                'format'    => 'nullable|in:xlsx,excel,xls,csv',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'status'  => false,
                    'message' => 'Validation failed.',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $month    = $request->input('month');
            $dateFrom = $request->input('date_from');
            $dateTo   = $request->input('date_to');
            $status   = $request->input('status', 'all');
            $rawFormat = strtolower((string) $request->input('format', 'xls'));

            if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
                return response()->json([
                    'success' => false,
                    'status'  => false,
                    'message' => 'date_from cannot be greater than date_to.',
                ], 422);
            }

            // 4. Build payment query
            $query = Payment::query()
                ->with([
                    'employee:id,company_id,first_name,last_name,email,phone,job_title,bank_name,account_name,account_number',
                ])
                ->whereHas('employee', function ($q) use ($company) {
                    $q->where('company_id', $company->id);
                });

            if ($month) {
                $monthDate = Carbon::createFromFormat('Y-m', $month);

                $query->whereBetween('payment_date', [
                    $monthDate->copy()->startOfMonth()->toDateString(),
                    $monthDate->copy()->endOfMonth()->toDateString(),
                ]);
            }

            if ($dateFrom) {
                $query->whereDate('payment_date', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('payment_date', '<=', $dateTo);
            }

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            // 5. Fetch payments
            $payments = $query
                ->orderBy('payment_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            if ($payments->isEmpty()) {
                return response()->json([
                    'success'       => false,
                    'status'        => false,
                    'message'       => 'No salary payments found for the selected filters.',
                    'records_count' => 0,
                ], 404);
            }

            // 6. Format rows
            $rows = [];
            $sn   = 1;

            foreach ($payments as $payment) {
                $employee = $payment->employee;

                $rows[] = [
                    $sn++,
                    $payment->employee_name ?? (
                        $employee
                            ? trim($employee->first_name . ' ' . $employee->last_name)
                            : 'N/A'
                    ),
                    $employee->email ?? 'N/A',
                    $employee->phone ?? 'N/A',
                    $employee->job_title ?? 'N/A',
                    (float) $payment->amount,
                    ucfirst($payment->status),
                    $payment->reference ?? 'N/A',
                    $payment->payment_date
                        ? Carbon::parse($payment->payment_date)->format('Y-m-d')
                        : 'N/A',
                    $employee->bank_name ?? 'N/A',
                    $employee->account_name ?? 'N/A',
                    $employee->account_number ?? 'N/A',
                ];
            }

            // The total counts successful payments only
            $totalPaid = (float) $payments->where('status', 'successful')->sum('amount');

            // 7. File name, format and title
            $isCsv     = $rawFormat === 'csv';
            $extension = $isCsv ? 'csv' : 'xls';
            $format    = $isCsv ? 'csv' : 'excel';

            $period = $month
                ?: ($dateFrom && $dateTo ? $dateFrom . '_to_' . $dateTo : 'all_months');

            // Random part makes the public URL impossible to guess
            $fileName = 'company_payments_' . $company->id . '_' . $period . '_'
                . now()->format('Ymd_His') . '_' . Str::lower(Str::random(8)) . '.' . $extension;

            $title = 'Company Salary Payment Report';

            if ($month) {
                $title .= ' - ' . Carbon::createFromFormat('Y-m', $month)->format('F Y');
            } elseif (!$dateFrom && !$dateTo) {
                $title .= ' - All Months';
            } else {
                $title .= ' - ' . $period;
            }

            // 8. Storage directory (public disk), with old exports cleaned up
            $storageDir = storage_path('app/public/downloads');

            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }

            foreach (glob($storageDir . '/company_payments_*') ?: [] as $old) {
                if (is_file($old) && filemtime($old) < now()->subDay()->getTimestamp()) {
                    @unlink($old);
                }
            }

            $filePath = $storageDir . DIRECTORY_SEPARATOR . $fileName;

            // 9. Generate the file
            if ($isCsv) {
                $this->generatePaymentsCsv($rows, $filePath);
            } else {
                $this->generatePaymentsExcel($rows, $title, $totalPaid, $filePath);
            }

            // Fully qualified URL that downloads the file directly
            $downloadUrl = asset('storage/downloads/' . $fileName);

            $now = Carbon::now();

            // Optional: return the file itself (?download=true)
            if ($request->boolean('download') && file_exists($filePath)) {
                return response()->download($filePath, $fileName, [
                    'Content-Type' => $isCsv
                        ? 'text/csv; charset=UTF-8'
                        : 'application/vnd.ms-excel',
                ]);
            }

            // 10. Return the URL to download the file
            return response()->json([
                'success'       => true,
                'status'        => true,
                'message'       => 'Export is ready.',
                'download_url'  => $downloadUrl,
                'file_name'     => $fileName,
                'file_type'     => $format,
                'records_count' => count($rows),
                'total_paid'    => $totalPaid,
                'generated_at'  => $now->format('Y-m-d H:i:s'),
            ], 200);

        } catch (\Throwable $e) {

            Log::error('Company payment export error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'status'  => false,
                'message' => 'Unable to export payment records.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Generate a CSV file with UTF-8 BOM encoding.
     */
    protected function generatePaymentsCsv(array $rows, string $filePath): void
    {
        $file = fopen($filePath, 'w');

        // UTF-8 BOM for Microsoft Excel compatibility
        fwrite($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($file, self::PAYMENT_HEADERS);

        foreach ($rows as $row) {
            fputcsv($file, $row);
        }

        fclose($file);
    }

    /**
     * Generate an Excel-readable .xls file (a styled table). No library needed.
     */
    protected function generatePaymentsExcel(array $rows, string $title, float $totalPaid, string $filePath): void
    {
        $headers  = self::PAYMENT_HEADERS;
        $cols     = count($headers);
        $green    = '#245B35';
        $textCols = [3, 11]; // Phone Number and Account Number stay as text (keeps leading zeros)
        $widths   = [50, 160, 200, 110, 130, 100, 100, 220, 100, 130, 160, 120];

        $html  = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
        $html .= '<head><meta charset="UTF-8">';
        $html .= '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
        $html .= '<x:Name>Salary Payments</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>';
        $html .= '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
        $html .= '<style>';
        $html .= 'table{border-collapse:collapse;font-family:Calibri,Arial,sans-serif;font-size:11pt;}';
        $html .= 'td,th{border:1px solid #BBBBBB;padding:4px 6px;}';
        $html .= '.title{background:' . $green . ';color:#FFFFFF;font-size:16pt;font-weight:bold;text-align:center;height:34px;border:none;}';
        $html .= '.head{background:' . $green . ';color:#FFFFFF;font-weight:bold;text-align:center;height:26px;}';
        $html .= '.alt{background:#F3F7F4;}';
        $html .= '.num{mso-number-format:"\#\,\#\#0\.00";text-align:right;}';
        $html .= '.txt{mso-number-format:"\@";}';
        $html .= '.total{background:#E2F0E8;font-weight:bold;}';
        $html .= '</style></head><body><table>';

        foreach ($widths as $w) {
            $html .= '<col width="' . $w . '">';
        }

        // Title row
        $html .= '<tr><td class="title" colspan="' . $cols . '">' . e($title) . '</td></tr>';
        $html .= '<tr><td colspan="' . $cols . '" style="border:none;">&nbsp;</td></tr>';

        // Header row
        $html .= '<tr>';
        foreach ($headers as $header) {
            $html .= '<th class="head">' . e($header) . '</th>';
        }
        $html .= '</tr>';

        // Data rows
        foreach ($rows as $i => $row) {
            $class = $i % 2 === 1 ? ' class="alt"' : '';
            $html .= '<tr' . $class . '>';

            foreach ($row as $index => $value) {
                if ($index === 0) {
                    $html .= '<td style="text-align:center;">' . e((string) $value) . '</td>';
                } elseif ($index === 5) {
                    $html .= '<td class="num">' . number_format((float) $value, 2, '.', '') . '</td>';
                } elseif (in_array($index, $textCols, true)) {
                    $html .= '<td class="txt">' . e((string) $value) . '</td>';
                } else {
                    $html .= '<td>' . e((string) $value) . '</td>';
                }
            }

            $html .= '</tr>';
        }

        // Total row
        $html .= '<tr><td colspan="5" class="total" style="text-align:right;">TOTAL PAID (SUCCESSFUL)</td>';
        $html .= '<td class="total num">' . number_format($totalPaid, 2, '.', '') . '</td>';
        $html .= '<td class="total" colspan="' . ($cols - 6) . '">&nbsp;</td></tr>';

        $html .= '</table></body></html>';

        // UTF-8 BOM first, so Excel reads the file as UTF-8
        file_put_contents($filePath, "\xEF\xBB\xBF" . $html);
    }
}