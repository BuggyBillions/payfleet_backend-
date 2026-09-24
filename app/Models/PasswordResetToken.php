<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'reset_otp',
        'is_verified'
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'expires_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}