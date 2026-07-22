<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class AiRule extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'label',
        'description',
        'rules',
        'is_active',
        'sort_order',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return list<string> */
    public static function allowedKeys(): array
    {
        return ['global', 'content_generation', 'content_mix', 'reply_drafts', 'event_templates', 'growth_decisions'];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function toAdminArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'rules' => $this->rules,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updated_by' => $this->updatedByUser?->email,
        ];
    }

    /**
     * Build the system-prompt prefix admins control. Global rules always come first.
     */
    public static function systemPromptPrefix(string ...$keys): string
    {
        $orderedKeys = array_values(array_unique(array_merge(['global'], $keys)));
        $sections = [];

        foreach ($orderedKeys as $key) {
            $rules = self::activeRulesText($key);
            if ($rules === '') {
                continue;
            }

            $label = match ($key) {
                'global' => 'PLATFORM RULES (MUST FOLLOW FIRST)',
                'content_generation' => 'CONTENT STUDIO RULES',
                'content_mix' => 'CONTENT MIX RULES (TREND VS ORIGINAL)',
                'reply_drafts' => 'REPLY DRAFT RULES',
                'event_templates' => 'LIVE EVENT POST RULES',
                'growth_decisions' => 'GROWTH BRAIN DECISION RULES',
                default => strtoupper(str_replace('_', ' ', $key)).' RULES',
            };

            $sections[] = "{$label}:\n{$rules}";
        }

        if ($sections === []) {
            return '';
        }

        return "CRITICAL — Read and internalize these rules before responding. They override any conflicting default behavior.\n\n"
            .implode("\n\n", $sections);
    }

    public static function clearCache(): void
    {
        foreach (self::allowedKeys() as $key) {
            Cache::forget(self::cacheKey($key));
        }
        Cache::forget('ai_rules:parsed:trend_max_percent');
    }

    /**
     * Max share of trend-based content (0-100). Configured in admin Platform Settings.
     */
    public static function trendContentMaxPercent(): int
    {
        return app(\App\Services\Admin\PlatformSettingsService::class)->trendContentMaxPercent();
    }

    private static function activeRulesText(string $key): string
    {
        if (! in_array($key, self::allowedKeys(), true)) {
            return '';
        }

        return Cache::remember(self::cacheKey($key), 3600, function () use ($key) {
            $rule = self::query()
                ->where('key', $key)
                ->where('is_active', true)
                ->first();

            if (! $rule) {
                return '';
            }

            return trim($rule->rules);
        });
    }

    private static function cacheKey(string $key): string
    {
        return "ai_rules:{$key}";
    }
}
