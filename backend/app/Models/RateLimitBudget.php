<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RateLimitBudget extends Model
{
    protected $fillable = [
        'user_id',
        'connected_account_id',
        'action_type',
        'count',
        'daily_limit',
        'budget_date',
    ];

    protected function casts(): array
    {
        return [
            'budget_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connectedAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectedAccount::class);
    }
}
