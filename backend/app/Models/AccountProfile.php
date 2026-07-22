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
        'engine_paused',
        'auto_post_enabled',
        'auto_reply_enabled',
        'auto_engage_enabled',
        'notify_post_results',
        'notify_draft_ready',
        'notify_weekly_recap',
        'notify_safety_alerts',
        'onboarding_completed',
    ];

    protected function casts(): array
    {
        return [
            'onboarding_completed' => 'boolean',
            'engine_paused' => 'boolean',
            'auto_post_enabled' => 'boolean',
            'auto_reply_enabled' => 'boolean',
            'auto_engage_enabled' => 'boolean',
            'notify_post_results' => 'boolean',
            'notify_draft_ready' => 'boolean',
            'notify_weekly_recap' => 'boolean',
            'notify_safety_alerts' => 'boolean',
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
        if ($this->custom_niche) {
            return $this->custom_niche;
        }

        return $this->niches()[0] ?? 'general';
    }

    /** @return list<string> */
    public function goals(): array
    {
        return $this->parseMulti($this->goal);
    }

    /** @return list<string> */
    public function niches(): array
    {
        return $this->parseMulti($this->niche);
    }

    /** @return list<string> */
    public function tones(): array
    {
        return $this->parseMulti($this->tone);
    }

    public function primaryGoal(): string
    {
        return $this->goals()[0] ?? 'grow_followers';
    }

    public function primaryTone(): string
    {
        return $this->tones()[0] ?? 'professional';
    }

    /** @return list<string> */
    private function parseMulti(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
