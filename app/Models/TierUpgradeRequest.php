<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TierUpgradeRequest extends Model
{
    protected $fillable = [
        'company_id',
        'current_tier',
        'requested_tier',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_note',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function currentTier()
    {
        return $this->belongsTo(Tier::class, 'current_tier');
    }

    public function requestedTier()
    {
        return $this->belongsTo(Tier::class, 'requested_tier');
    }
}