<?php

namespace App\Models;

use App\Support\SetupCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountProfile extends Model
{
    protected $fillable = [
        'user_id',
        'connected_account_id',
        'purpose',
        'platforms',
        'discovery',
        'goal',
        'niche',
        'custom_niche',
        'description',
        'tone',
        'audience',
        'timezone',
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
            'discovery' => 'array',
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
    public function platformList(): array
    {
        $chosen = $this->parseMulti($this->platforms);

        return array_values(array_intersect(SetupCatalog::PLATFORMS, $chosen));
    }

    public function platformSetups(): HasMany
    {
        return $this->hasMany(PlatformSetup::class, 'user_id', 'user_id');
    }

    /** @return list<string> */
    public function goalsFor(string $platform): array
    {
        $setup = $this->platformSetups()->where('platform', $platform)->first();

        if ($setup && $setup->goalList() !== []) {
            return $setup->goalList();
        }

        return $platform === 'x' ? $this->goals() : [];
    }

    public function goalSentenceFor(string $platform): string
    {
        $sentence = SetupCatalog::goalSentence($platform, $this->goalsFor($platform));

        return $sentence !== '' ? $sentence : SetupCatalog::goalSentence('x', [$this->primaryGoal()]);
    }

    public function purposeSentence(): ?string
    {
        return SetupCatalog::PURPOSES[$this->purpose ?? ''] ?? null;
    }

    public function rememberXSettings(): void
    {
        $setup = PlatformSetup::query()
            ->where('user_id', $this->user_id)
            ->where('platform', 'x')
            ->first();

        if (! $setup) {
            return;
        }

        $settings = is_array($setup->settings) ? $setup->settings : [];
        $settings['automation_level'] = (int) $this->automation_level;
        $settings['mode'] = $this->mode ?? 'reactive';
        $settings['auto_post_enabled'] = (bool) $this->auto_post_enabled;
        $settings['auto_reply_enabled'] = (bool) $this->auto_reply_enabled;
        $settings['auto_engage_enabled'] = (bool) $this->auto_engage_enabled;
        $settings['engine_paused'] = (bool) $this->engine_paused;
        $settings['max_silence_minutes'] = (int) ($this->max_silence_minutes ?? 60);
        $setup->settings = $settings;
        $setup->save();
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
