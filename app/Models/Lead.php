<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'company_name', 'address', 'phone', 'contact_person', 'email',
        'response', 'follow_up_date', 'follow_up_process', 'status',
        'notes', 'created_by',
    ];

    protected $casts = [
        'follow_up_date' => 'date:Y-m-d',
    ];

    public const STATUSES  = ['new', 'contacted', 'interested', 'follow_up', 'converted', 'lost'];
    public const PROCESSES = ['call', 'visit', 'email', 'whatsapp', 'demo', 'meeting'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class)->latest();
    }
}