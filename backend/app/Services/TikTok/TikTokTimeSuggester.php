<?php

namespace App\Services\TikTok;

use App\Support\TikTokCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Exception;

class TikTokTimeSuggester
{
    /**
     * Placeholder heuristic: 19:30 local, or the next day when that is under an hour away.
     * There is no audience timing in this phase. The instant is always UTC.
     *
     * @return array{at: CarbonImmutable, timezone: string, assumed: bool}
     */
    public function next(?string $timezone, CarbonInterface $now): array
    {
        [$zone, $assumed] = $this->zone($timezone);
        $instant = CarbonImmutable::parse($now->toIso8601String());
        $localNow = $instant->setTimezone($zone);
        [$hour, $minute] = array_map('intval', explode(':', TikTokCatalog::DEFAULT_SLOT_LOCAL));
        $slot = $localNow->setTime($hour, $minute, 0);

        if ($slot->lessThan($localNow->addMinutes(TikTokCatalog::MIN_LEAD_MINUTES))) {
            $slot = $slot->addDay();
        }

        $at = $slot->utc();
        if ($at->lessThanOrEqualTo($instant->utc())) {
            $at = $slot->addDay()->utc();
        }

        return [
            'at' => $at,
            'timezone' => $zone,
            'assumed' => $assumed,
        ];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function zone(?string $timezone): array
    {
        $timezone = is_string($timezone) ? trim($timezone) : '';
        if ($timezone === '') {
            return [TikTokCatalog::FALLBACK_TIMEZONE, true];
        }

        try {
            new DateTimeZone($timezone);
        } catch (Exception) {
            return [TikTokCatalog::FALLBACK_TIMEZONE, true];
        }

        return [$timezone, false];
    }
}
