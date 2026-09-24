<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'reference',
        'amount',
        'previous_balance',
        'current_balance',
        'type',
        'transaction_type',
        'status',
        'description'
    ];
}
