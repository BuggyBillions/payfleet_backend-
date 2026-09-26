<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount',
        'reason',
        'no_of_month',
        'employee_id'
    ];

    public function Employee()
    {
        return $this->belongsTo(Employees::class);
    }
}
