<?php

namespace App\Services\Billing;

use InvalidArgumentException;

class QuoteCalculator
{
    /**
     * @param  array<int, array{platform: string, tier: string}>  $selections
     * @param  array<string, array<string, mixed>>  $catalog
     * @return array<string, mixed>
     */
    public function quote(
        array $selections,
        string $interval,
        array $catalog,
        int $discountPercent = 15,
    ): array {
        if (! in_array($interval, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Choose monthly or yearly billing.');
        }

        if ($selections === []) {
            throw new InvalidArgumentException('Choose at least one platform.');
        }

        if (count($selections) > 3) {
            throw new InvalidArgumentException('Choose up to three platforms.');
        }

        $platforms = array_column($selections, 'platform');

        if (count($platforms) !== count(array_unique($platforms))) {
            throw new InvalidArgumentException('Each platform can only be added once.');
        }

        $resolved = [];

        foreach ($selections as $index => $selection) {
            $platformId = $selection['platform'];
            $tierId = $selection['tier'];
            $platform = $catalog[$platformId] ?? null;
            $tier = $platform['tiers'][$tierId] ?? null;

            if ($platform === null || $tier === null) {
                throw new InvalidArgumentException('Unknown platform or tier.');
            }

            $list = (int) $tier['list_cents'];
            $minimum = (int) $tier['minimum_cents'];

            if ($list < $minimum) {
                throw new InvalidArgumentException($platform['name'].' '.$tier['name'].' is priced below its minimum.');
            }

            $resolved[] = [
                'index' => $index,
                'platform' => $platformId,
                'platform_name' => $platform['name'],
                'summary' => $platform['summary'],
                'tier' => $tierId,
                'tier_name' => $tier['name'],
                'list_cents' => $list,
                'yearly_cents' => (int) ($tier['yearly_cents'] ?? $list * 10),
                'minimum_cents' => $minimum,
                'headroom_cents' => $list - $minimum,
                'accounts' => (int) $tier['accounts'],
                'allowance' => (int) $tier['allowance'],
                'allowance_unit' => $tier['allowance_unit'],
                'features' => $tier['features'],
            ];
        }

        $ranked = $resolved;
        usort($ranked, function (array $a, array $b): int {
            if ($a['list_cents'] !== $b['list_cents']) {
                return $b['list_cents'] <=> $a['list_cents'];
            }

            if ($a['headroom_cents'] !== $b['headroom_cents']) {
                return $a['headroom_cents'] <=> $b['headroom_cents'];
            }

            return $a['index'] <=> $b['index'];
        });

        $anchorIndex = $ranked[0]['index'];
        $lines = [];
        $subtotal = 0;
        $discount = 0;
        $total = 0;
        $yearlyFloorApplied = false;

        foreach ($resolved as $line) {
            $isAnchor = $line['index'] === $anchorIndex;
            $monthlyCharged = $line['list_cents'];
            $limited = false;

            if (! $isAnchor) {
                $reduction = (int) round($line['list_cents'] * $discountPercent / 100);
                $monthlyCharged = max($line['minimum_cents'], $line['list_cents'] - $reduction);
                $limited = $monthlyCharged > ($line['list_cents'] - $reduction);
            }

            $yearlyUnclamped = $isAnchor
                ? $line['yearly_cents']
                : $line['yearly_cents'] - (int) round($line['yearly_cents'] * $discountPercent / 100);
            $yearlyFloor = $line['minimum_cents'] * 12;
            $yearlyCharged = max($yearlyUnclamped, $yearlyFloor);
            $lineFloorApplied = $yearlyCharged > $yearlyUnclamped;

            if ($lineFloorApplied && $interval === 'yearly') {
                $yearlyFloorApplied = true;
            }

            // Yearly lines are measured against twelve months at the monthly shelf price,
            // so the saving shown includes the yearly discount.
            $periodList = $interval === 'yearly'
                ? $line['list_cents'] * 12
                : $line['list_cents'];
            $periodCharged = $interval === 'yearly' ? $yearlyCharged : $monthlyCharged;
            $periodDiscount = max(0, $periodList - $periodCharged);

            $subtotal += $periodList;
            $discount += $periodDiscount;
            $total += $periodCharged;

            $lines[] = [
                'platform' => $line['platform'],
                'platform_name' => $line['platform_name'],
                'summary' => $line['summary'],
                'tier' => $line['tier'],
                'tier_name' => $line['tier_name'],
                'list_cents' => $periodList,
                'monthly_list_cents' => $line['list_cents'],
                'yearly_list_cents' => $line['yearly_cents'],
                'charged_cents' => $periodCharged,
                'discount_cents' => $periodDiscount,
                'is_anchor' => $isAnchor,
                'discount_limited' => $limited,
                'yearly_floor_applied' => $interval === 'yearly' && $lineFloorApplied,
                'accounts_included' => $line['accounts'],
                'monthly_allowance' => $line['allowance'],
                'allowance_unit' => $line['allowance_unit'],
                'features' => $line['features'],
            ];
        }

        return [
            'interval' => $interval,
            'currency' => 'USD',
            'discount_percent' => $discountPercent,
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'total_cents' => $total,
            'yearly_floor_applied' => $interval === 'yearly' && $yearlyFloorApplied,
            'period' => $interval === 'yearly' ? '/yr' : '/mo',
        ];
    }
}
