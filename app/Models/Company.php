<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Company extends Model
{
    use HasApiTokens, HasFactory;
    protected $fillable = [
        'name',
        'email',
        'logo',
        'about',
        'address',
        'password',
        'balance',
        'phone',
        'pin',
        'user_id',
        'tier',
        'bvn',
        'nin',
        'cac',
        'mermat',
        'status_report',
    ];

    protected $hidden = [
        'password',
    ];

    // public function employees()
    // {
    //     return $this->hasMany(Employee::class);
    // }
    // public function transactions()
    // {
    //     return $this->hasMany(Transaction::class);
    // }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employees()
    {
        return $this->hasMany(Employees::class, 'company_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'company_id');
    }

    public function tier()
    {
        return $this->belongsTo(Tier::class, 'tier');
    }

    public function tierDetails()
    {
        return $this->belongsTo(Tier::class, 'tier', 'id');
    }
}
