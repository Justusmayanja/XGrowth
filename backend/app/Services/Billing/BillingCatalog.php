<?php

namespace App\Services\Billing;

use App\Models\PlatformPrice;
use App\Services\Admin\PlatformSettingsService;

class BillingCatalog
{
    public function __construct(
        private readonly QuoteCalculator $quotes,
        private readonly PlatformSettingsService $settings,
    ) {}

    /**
     * Shelf prices from config, with admin edits from platform_prices overlaid.
     * A tier without an admin yearly price defaults to ten months of its monthly price.
     *
     * @return array<string, array<string, mixed>>
     */
    public function matrix(): array
    {
        $overrides = [];

        if (PlatformPrice::query()->getConnection()->getSchemaBuilder()->hasTable('platform_prices')) {
            foreach (PlatformPrice::query()->get() as $row) {
                $overrides[$row->platform.':'.$row->tier] = $row;
            }
        }

        $matrix = [];

        foreach (config('billing.platforms') as $platformId => $platform) {
            $tiers = [];

            foreach ($platform['tiers'] as $tierId => $tier) {
                $row = $overrides[$platformId.':'.$tierId] ?? null;
                $list = $row?->list_price_cents ?? (int) $tier['list_cents'];

                $tiers[$tierId] = $tier;
                $tiers[$tierId]['list_cents'] = $list;
                $tiers[$tierId]['yearly_cents'] = $row?->yearly_price_cents
                    ?? $list * (int) config('billing.yearly_months_charged', 10);
            }

            $matrix[$platformId] = $platform;
            $matrix[$platformId]['tiers'] = $tiers;
        }

        return $matrix;
    }

    public function bundleDiscountPercent(): int
    {
        return $this->settings->bundleDiscountPercent();
    }

    /**
     * @param  array<int, array{platform: string, tier: string}>  $lines
     * @return array<string, mixed>
     */
    public function quote(array $lines, string $interval): array
    {
        return $this->quotes->quote($lines, $interval, $this->matrix(), $this->bundleDiscountPercent());
    }

    /**
     * @return array<string, mixed>
     */
    public function publicCatalog(): array
    {
        $platforms = [];

        foreach ($this->matrix() as $id => $platform) {
            $platforms[] = $this->publicPlatform($id, $platform);
        }

        return [
            'currency' => 'USD',
            'discount_percent' => $this->bundleDiscountPercent(),
            'platforms' => $platforms,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function adminPrices(): array
    {
        $rows = [];

        foreach ($this->matrix() as $platformId => $platform) {
            foreach ($platform['tiers'] as $tierId => $tier) {
                $rows[] = [
                    'platform' => $platformId,
                    'platform_name' => $platform['name'],
                    'tier' => $tierId,
                    'tier_name' => $tier['name'],
                    'list_price_cents' => (int) $tier['list_cents'],
                    'yearly_price_cents' => (int) $tier['yearly_cents'],
                    'minimum_cents' => (int) $tier['minimum_cents'],
                ];
            }
        }

        return $rows;
    }

    public function minimumCents(string $platform, string $tier): ?int
    {
        $value = config("billing.platforms.{$platform}.tiers.{$tier}.minimum_cents");

        return $value === null ? null : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $platform
     * @return array<string, mixed>
     */
    private function publicPlatform(string $id, array $platform): array
    {
        $tiers = [];

        foreach ($platform['tiers'] as $tierId => $tier) {
            $tiers[] = [
                'id' => $tierId,
                'name' => $tier['name'],
                'list_cents' => (int) $tier['list_cents'],
                'yearly_cents' => (int) $tier['yearly_cents'],
                'accounts' => (int) $tier['accounts'],
                'allowance' => (int) $tier['allowance'],
                'allowance_unit' => $tier['allowance_unit'],
                'features' => $tier['features'],
            ];
        }

        return [
            'id' => $id,
            'name' => $platform['name'],
            'summary' => $platform['summary'],
            'tiers' => $tiers,
        ];
    }
}
