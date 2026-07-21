<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDecision extends Model
{
    protected $fillable = [
        'user_id',
        'connected_account_id',
        'decision',
        'action_taken',
        'opportunity_score',
        'reason',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'opportunity_score' => 'decimal:2',
            'context' => 'array',
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
