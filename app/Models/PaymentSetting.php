<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSetting extends Model
{
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}