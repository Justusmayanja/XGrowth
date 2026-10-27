<?php

namespace App\Services\Admin;

use App\Models\AiRule;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PlatformSettingsService
{
    private const CACHE_KEY = 'platform_settings:all';

    /** @var array<string, array{group: string, type: string, label: string, description: string, default: string}> */
    public const DEFINITIONS = [
        'trend_fetch_times' => [
            'group' => 'trends',
            'type' => 'string',
            'label' => 'Trend fetch times',
            'description' => 'Not used for automatic pulls. Each account\'s home timeline is read once and kept for 7 days.',
            'default' => '06:00,14:00,22:00',
        ],
        'trend_fetch_cooldown_hours' => [
            'group' => 'trends',
            'type' => 'integer',
            'label' => 'Trend fetch cooldown (hours)',
            'description' => 'Not used for automatic pulls. A home-timeline list stays in place for 7 days.',
            'default' => '4',
        ],
        'growth_evaluation_interval_minutes' => [
            'group' => 'scheduler',
            'type' => 'integer',
            'label' => 'Growth evaluation interval (minutes)',
            'description' => 'The engine looks every 5 minutes so a live moment is not missed. Posts are not sent on this timer.',
            'default' => '30',
        ],
        'growth_brain_enabled' => [
            'group' => 'growth_brain',
            'type' => 'boolean',
            'label' => 'Growth Brain enabled',
            'description' => 'Use AI for POST/WAIT/ENGAGE decisions. When off, rule-based fallback is used.',
            'default' => '1',
        ],
        'growth_brain_prefilter_limit' => [
            'group' => 'growth_brain',
            'type' => 'integer',
            'label' => 'Trend prefilter limit',
            'description' => 'Max trends sent to the Growth Brain per evaluation (keeps prompts small).',
            'default' => '5',
        ],
        'growth_eval_cooldown_minutes' => [
            'group' => 'growth_brain',
            'type' => 'integer',
            'label' => 'Per-user eval cooldown (minutes)',
            'description' => 'A quiet account waits this long before another look. A live moment is looked at about every 5 minutes.',
            'default' => '30',
        ],
        'growth_brain_max_output_tokens' => [
            'group' => 'growth_brain',
            'type' => 'integer',
            'label' => 'Growth Brain max output tokens',
            'description' => 'Caps token usage for each growth evaluation response.',
            'default' => '400',
        ],
        'web_search_enabled' => [
            'group' => 'ai',
            'type' => 'boolean',
            'label' => 'Web search enabled',
            'description' => 'Allow optional OpenAI web search in Content Studio and autopilot (uses extra tokens).',
            'default' => '1',
        ],
        'openai_model' => [
            'group' => 'ai',
            'type' => 'string',
            'label' => 'OpenAI model',
            'description' => 'Default model for content generation and Growth Brain. API key stays in server env.',
            'default' => 'gpt-5.4-mini',
        ],
        'content_mix_max_trend_percent' => [
            'group' => 'content_mix',
            'type' => 'integer',
            'label' => 'Max trend-based content (%)',
            'description' => 'Trend posts must stay at or below this share; the rest should be original evergreen content.',
            'default' => '70',
        ],
        'content_mix_sample_size' => [
            'group' => 'content_mix',
            'type' => 'integer',
            'label' => 'Content mix sample size',
            'description' => 'Number of recent posts used to calculate the trend vs original mix.',
            'default' => '10',
        ],
        'rules_post_threshold' => [
            'group' => 'fallback_rules',
            'type' => 'integer',
            'label' => 'Fallback POST threshold',
            'description' => 'When Growth Brain is off, opportunity score needed to suggest posting.',
            'default' => '82',
        ],
        'rules_engage_threshold' => [
            'group' => 'fallback_rules',
            'type' => 'integer',
            'label' => 'Fallback ENGAGE threshold',
            'description' => 'When Growth Brain is off, opportunity score needed to suggest engagement.',
            'default' => '68',
        ],
        'safety_limit_post' => [
            'group' => 'safety',
            'type' => 'integer',
            'label' => 'Daily post limit',
            'description' => 'Max posts per user per day (Safety Engine).',
            'default' => '25',
        ],
        'safety_limit_reply' => [
            'group' => 'safety',
            'type' => 'integer',
            'label' => 'Daily reply limit',
            'description' => 'Max replies per user per day.',
            'default' => '50',
        ],
        'safety_limit_follow' => [
            'group' => 'safety',
            'type' => 'integer',
            'label' => 'Daily follow limit',
            'description' => 'Max follows per user per day.',
            'default' => '10',
        ],
        'safety_limit_like' => [
            'group' => 'safety',
            'type' => 'integer',
            'label' => 'Daily like limit',
            'description' => 'Max likes per user per day.',
            'default' => '100',
        ],
        'trial_days' => [
            'group' => 'billing',
            'type' => 'integer',
            'label' => 'Free trial length (days)',
            'description' => 'Length of the free trial that starts when a new customer picks their platforms. Changes apply to new trials only; customers already on a trial keep the length they started with.',
            'default' => '7',
        ],
        // Edited on the pricing card; the "pricing" group has no label so the generic settings card skips it.
        'billing_bundle_discount_percent' => [
            'group' => 'pricing',
            'type' => 'integer',
            'label' => 'Multi-platform discount (%)',
            'description' => 'Taken off every platform except the most expensive one.',
            'default' => '15',
        ],
    ];

    /** @var array<string, string> */
    private const GROUP_LABELS = [
        'trends' => 'Trends & X API',
        'scheduler' => 'Scheduler',
        'growth_brain' => 'Growth Brain',
        'ai' => 'AI & OpenAI',
        'content_mix' => 'Content mix',
        'fallback_rules' => 'Rule-based fallback',
        'safety' => 'Safety limits',
        'billing' => 'Billing & free trial',
    ];

    public function get(string $key, ?string $default = null): string
    {
        $all = $this->allValues();

        if (array_key_exists($key, $all)) {
            return (string) $all[$key];
        }

        return $default ?? self::DEFINITIONS[$key]['default'] ?? '';
    }

    public function getInt(string $key, ?int $default = null): int
    {
        $value = $this->get($key, $default !== null ? (string) $default : null);

        return (int) $value;
    }

    public function getBool(string $key, bool $default = true): bool
    {
        $value = strtolower($this->get($key, $default ? '1' : '0'));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    public function getFetchTimes(): array
    {
        $raw = $this->get('trend_fetch_times', self::DEFINITIONS['trend_fetch_times']['default']);

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function getOpenAiModel(): string
    {
        $model = trim($this->get('openai_model', config('openai.model', 'gpt-5.4-mini')));

        return $model !== '' ? $model : 'gpt-5.4-mini';
    }

    public function bundleDiscountPercent(): int
    {
        return max(0, min(50, $this->getInt('billing_bundle_discount_percent', (int) config('billing.discount_percent', 15))));
    }

    public function trialDays(): int
    {
        return max(1, min(365, $this->getInt('trial_days', 7)));
    }

    public function trendContentMaxPercent(): int
    {
        return max(0, min(100, $this->getInt('content_mix_max_trend_percent', 70)));
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function groupedForAdmin(): array
    {
        $values = $this->allValues();
        $grouped = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $group = $definition['group'];
            $row = $this->tableExists()
                ? PlatformSetting::query()->with('updatedByUser:id,email')->find($key)
                : null;

            $grouped[$group][] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'type' => $definition['type'],
                'value' => $values[$key] ?? $definition['default'],
                'default' => $definition['default'],
                'updated_at' => $row?->updated_at?->toIso8601String(),
                'updated_by' => $row?->updatedByUser?->email,
            ];
        }

        $result = [];
        foreach (self::GROUP_LABELS as $groupKey => $label) {
            if (! empty($grouped[$groupKey])) {
                $result[] = [
                    'key' => $groupKey,
                    'label' => $label,
                    'settings' => $grouped[$groupKey],
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{key: string, value: string|int|bool}>  $items
     * @return list<array<string, mixed>>
     */
    public function bulkUpdate(array $items, int $updatedBy): array
    {
        $updated = [];

        foreach ($items as $item) {
            $key = $item['key'];
            if (! isset(self::DEFINITIONS[$key])) {
                continue;
            }

            $normalized = $this->normalizeValue($key, (string) $item['value']);

            PlatformSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $normalized, 'updated_by' => $updatedBy]
            );

            $updated[] = $key;
        }

        if ($updated !== []) {
            $this->clearCache();
            AiRule::clearCache();
        }

        return $updated;
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('ai_rules:parsed:trend_max_percent');
    }

    /** @return array<string, string> */
    private function allValues(): array
    {
        if (! $this->tableExists()) {
            return $this->defaultValues();
        }

        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $stored = PlatformSetting::query()->pluck('value', 'key')->all();
            $merged = [];

            foreach (self::DEFINITIONS as $key => $definition) {
                $merged[$key] = (string) ($stored[$key] ?? $definition['default']);
            }

            return $merged;
        });
    }

    /** @return array<string, string> */
    private function defaultValues(): array
    {
        $merged = [];

        foreach (self::DEFINITIONS as $key => $definition) {
            $merged[$key] = (string) $definition['default'];
        }

        return $merged;
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('platform_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeValue(string $key, string $value): string
    {
        $type = self::DEFINITIONS[$key]['type'];

        return match ($type) {
            'boolean' => in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
            'integer' => (string) (int) $value,
            default => trim($value),
        };
    }
}
