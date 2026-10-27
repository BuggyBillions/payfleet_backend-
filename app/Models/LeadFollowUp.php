<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadFollowUp extends Model
{
    protected $fillable = [
        'lead_id', 'user_id', 'contact_method', 'response', 'status_after',
        'next_follow_up_date', 'next_follow_up_process',
    ];

    protected $casts = [
        'next_follow_up_date' => 'date:Y-m-d',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}