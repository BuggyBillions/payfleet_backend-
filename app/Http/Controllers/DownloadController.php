<?php

namespace App\Http\Controllers;

use App\Models\User;
use PhpParser\Node\Stmt\Return_;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Company;
use App\Models\Employees;
use App\Models\Deduction;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Support\Facades\Hash;
use App\Models\PaymentSetting;
use App\Services\PayrollTransferSettler;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class DownloadController extends Controller
{
    public function exportPayments(Request $request)
    {
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '300');

        try {

            // 1. Authenticate user
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            // 2. Get authenticated company
            $company = Company::where('user_id', $user->id)->first();

            if (!$company) {
                return response()->json([
                    'status' => false,
                    'message' => 'Company account not found.'
                ], 404);
            }

            // 3. Validate request
            $validator = Validator::make($request->all(), [
                'month' => 'nullable|date_format:Y-m',
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d',
                'status' => 'nullable|in:all,pending,successful,failed,cancelled',
                'format' => 'nullable|in:xlsx,excel,csv',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $month = $request->input('month');
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
            $status = $request->input('status', 'all');
            $format = $request->input('format', 'xlsx');

            // Validate date range
            if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
                return response()->json([
                    'status' => false,
                    'message' => 'date_from cannot be greater than date_to.'
                ], 422);
            }

            // 4. Build payment query
            $query = Payment::query()
                ->with([
                    'employee:id,company_id,first_name,last_name,email,phone,job_title,bank_name,account_name,account_number'
                ])
                ->whereHas('employee', function ($q) use ($company) {
                    $q->where('company_id', $company->id);
                });

            // Filter by selected month
            if ($month) {
                $monthDate = Carbon::createFromFormat('Y-m', $month);

                $query->whereBetween('payment_date', [
                    $monthDate->copy()->startOfMonth()->toDateString(),
                    $monthDate->copy()->endOfMonth()->toDateString()
                ]);
            }

            // Filter by date range
            if ($dateFrom) {
                $query->whereDate('payment_date', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('payment_date', '<=', $dateTo);
            }

            // Filter by payment status
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
                    'status' => false,
                    'message' => 'No salary payments found for the selected filters.',
                    'records_count' => 0
                ], 404);
            }

            // 6. Prepare export headers
            $headers = [
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

            // 7. Prepare payment records
            $rows = [];
            $sn = 1;

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

            // 8. Generate filename
            $period = $month
                ? $month
                : ($dateFrom && $dateTo
                    ? $dateFrom . '_to_' . $dateTo
                    : 'all_months');

            $filename = 'company_payments_' .
                $company->id . '_' .
                $period . '_' .
                now()->format('Ymd_His');

            // 9. Create export directory
            $storageDir = storage_path('app/exports');

            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }

            /*
            |--------------------------------------------------------------------------
            | 10. Generate CSV
            |--------------------------------------------------------------------------
            */

            if ($format === 'csv') {

                $filePath = $storageDir . '/' . $filename . '.csv';

                $file = fopen($filePath, 'w');

                // UTF-8 BOM for Excel compatibility
                fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

                fputcsv($file, $headers);

                foreach ($rows as $row) {
                    fputcsv($file, $row);
                }

                fclose($file);

            } else {

                /*
                |--------------------------------------------------------------------------
                | 11. Generate Excel
                |--------------------------------------------------------------------------
                */

                $spreadsheet = new Spreadsheet();

                $sheet = $spreadsheet->getActiveSheet();

                $sheet->setTitle('Salary Payments');

                // Report title
                $title = 'Company Salary Payment Report';

                if ($month) {
                    $title .= ' - ' . Carbon::createFromFormat('Y-m', $month)
                        ->format('F Y');
                } elseif (!$dateFrom && !$dateTo) {
                    $title .= ' - All Months';
                } else {
                    $title .= ' - ' . $period;
                }

                $sheet->setCellValue('A1', $title);

                $sheet->mergeCells('A1:L1');

                // Title styling
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 16,
                        'color' => ['rgb' => 'FFFFFF'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '245B35'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                $sheet->getRowDimension(1)->setRowHeight(30);

                // Column headers
                $column = 'A';

                foreach ($headers as $header) {
                    $sheet->setCellValue($column . '3', $header);
                    $column++;
                }

                // Header styling
                $sheet->getStyle('A3:L3')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'FFFFFF'],
                        'size' => 10,
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '245B35'],
                    ],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                $sheet->getRowDimension(3)->setRowHeight(25);

                // Insert payment records
                $rowNumber = 4;

                foreach ($rows as $row) {

                    $column = 'A';

                    foreach ($row as $value) {

                        $sheet->setCellValue(
                            $column . $rowNumber,
                            $value
                        );

                        $column++;
                    }

                    // Alternate row background
                    if ($rowNumber % 2 === 0) {
                        $sheet->getStyle(
                            "A{$rowNumber}:L{$rowNumber}"
                        )->applyFromArray([
                            'fill' => [
                                'fillType' => Fill::FILL_SOLID,
                                'startColor' => ['rgb' => 'F3F7F4'],
                            ],
                        ]);
                    }

                    $rowNumber++;
                }

                $lastRow = $rowNumber - 1;

                // Amount formatting
                $sheet->getStyle("F4:F{$lastRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');

                // Total amount
                $totalRow = $rowNumber + 1;

                $sheet->setCellValue('E' . $totalRow, 'TOTAL AMOUNT');

                $sheet->setCellValue(
                    'F' . $totalRow,
                    (float) $payments->sum('amount')
                );

                $sheet->getStyle("E{$totalRow}:F{$totalRow}")
                    ->applyFromArray([
                        'font' => [
                            'bold' => true,
                        ],
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'E2F0E8'],
                        ],
                    ]);

                $sheet->getStyle("F{$totalRow}")
                    ->getNumberFormat()
                    ->setFormatCode('#,##0.00');

                // Borders
                $sheet->getStyle("A3:L{$lastRow}")
                    ->getBorders()
                    ->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                // Auto-size columns
                foreach (range('A', 'L') as $column) {
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }

                // Alignment
                $sheet->getStyle("A3:A{$lastRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $sheet->getStyle("F4:F{$lastRow}")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                // Save Excel file
                $filePath = $storageDir . '/' . $filename . '.xlsx';

                $writer = new Xlsx($spreadsheet);

                $writer->save($filePath);

                $spreadsheet->disconnectWorksheets();

                unset($spreadsheet);
            }

            /*
            |--------------------------------------------------------------------------
            | 12. Download file
            |--------------------------------------------------------------------------
            */

            return response()->download(
                $filePath,
                basename($filePath),
                [
                    'Content-Type' => $format === 'csv'
                        ? 'text/csv; charset=UTF-8'
                        : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ]
            )->deleteFileAfterSend(true);

        } catch (\Throwable $e) {

            Log::error('Company payment export error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to export payment records.',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : null
            ], 500);
        }
    }


}
