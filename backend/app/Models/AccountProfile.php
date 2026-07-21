<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountProfile extends Model
{
    protected $fillable = [
        'user_id',
        'connected_account_id',
        'goal',
        'niche',
        'custom_niche',
        'description',
        'tone',
        'audience',
        'mode',
        'automation_level',
        'max_silence_minutes',
        'onboarding_completed',
    ];

    protected function casts(): array
    {
        return [
            'onboarding_completed' => 'boolean',
            'automation_level' => 'integer',
            'max_silence_minutes' => 'integer',
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

    public function resolvedNiche(): string
    {
        return $this->custom_niche ?: ($this->niche ?? 'general');
    }
}
