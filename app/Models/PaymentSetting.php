<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSetting extends Model
{
    /** Staff paid this amount or more are also charged the federal government fee */
    public const GOVERNMENT_FEE_THRESHOLD = 10000;

    protected $fillable = [
        'vat',
        'federal_government_fee',
        'payfleet_fee',
        'updated_by',
    ];

    protected $casts = [
        'vat'                    => 'decimal:2',
        'federal_government_fee' => 'decimal:2',
        'payfleet_fee'           => 'decimal:2',
    ];

    /** The one and only settings row (null until it has been created). */
    public static function current(): ?self
    {
        return static::first();
    }

    /**
     * Processing fee for one staff member:
     * VAT + Payfleet fee always, plus the government fee when the salary is 10,000 or more.
     */
    public function processingFeeFor(float $salary): float
    {
        $fee = (float) $this->vat + (float) $this->payfleet_fee;

        if ($salary >= self::GOVERNMENT_FEE_THRESHOLD) {
            $fee += (float) $this->federal_government_fee;
        }

        return round($fee, 2);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}