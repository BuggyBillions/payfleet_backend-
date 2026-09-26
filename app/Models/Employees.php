<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employees extends Model
{
    use HasFactory;
    protected $fillable = [
        'company_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'job_title',
        'paying',
        'employment_type',
        'bank_name',
        'account_number',
        'account_name',
        'estimate_pay',
        'deduction_amount',
        'deduction_id'
    ];

    public function company()
    {
        return $this->belongsTo(\App\Models\Company::class);
    }

    public function Deduction()
    {
        return $this->hasOne(Deduction::class);
    }
}
