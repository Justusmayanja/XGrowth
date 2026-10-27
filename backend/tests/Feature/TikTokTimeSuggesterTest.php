<?php

namespace Tests\Feature;

use App\Services\TikTok\TikTokTimeSuggester;
use App\Support\TikTokCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokTimeSuggesterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_kampala_uses_today_until_the_lead_window_then_the_next_day(): void
    {
        $suggester = app(TikTokTimeSuggester::class);

        $morning = $suggester->next('Africa/Kampala', CarbonImmutable::parse('2026-10-27 10:00:00', 'Africa/Kampala'));
        $this->assertSame('2026-10-27 16:30:00', $morning['at']->format('Y-m-d H:i:s'));
        $this->assertFalse($morning['assumed']);
        $this->assertSame('Africa/Kampala', $morning['timezone']);

        $exact = $suggester->next('Africa/Kampala', CarbonImmutable::parse('2026-10-27 18:30:00', 'Africa/Kampala'));
        $this->assertSame('2026-10-27 16:30:00', $exact['at']->format('Y-m-d H:i:s'));

        $inside = $suggester->next('Africa/Kampala', CarbonImmutable::parse('2026-10-27 19:00:00', 'Africa/Kampala'));
        $this->assertSame('2026-10-28 16:30:00', $inside['at']->format('Y-m-d H:i:s'));

        $after = $suggester->next('Africa/Kampala', CarbonImmutable::parse('2026-10-27 20:00:00', 'Africa/Kampala'));
        $this->assertSame('2026-10-28 16:30:00', $after['at']->format('Y-m-d H:i:s'));
    }

    public function test_new_york_keeps_the_right_utc_offset_across_dst(): void
    {
        $suggester = app(TikTokTimeSuggester::class);

        $before = $suggester->next('America/New_York', CarbonImmutable::parse('2026-03-07 12:00:00', 'America/New_York'));
        $this->assertSame('2026-03-08 00:30:00', $before['at']->format('Y-m-d H:i:s'));

        $after = $suggester->next('America/New_York', CarbonImmutable::parse('2026-03-09 12:00:00', 'America/New_York'));
        $this->assertSame('2026-03-09 23:30:00', $after['at']->format('Y-m-d H:i:s'));
    }

    public function test_missing_and_invalid_timezones_fall_back_to_utc(): void
    {
        $suggester = app(TikTokTimeSuggester::class);
        $now = CarbonImmutable::parse('2026-10-27 10:00:00', 'UTC');

        $missing = $suggester->next(null, $now);
        $invalid = $suggester->next('Not/AZone', $now);

        $this->assertTrue($missing['assumed']);
        $this->assertTrue($invalid['assumed']);
        $this->assertSame(TikTokCatalog::FALLBACK_TIMEZONE, $missing['timezone']);
        $this->assertSame(TikTokCatalog::FALLBACK_TIMEZONE, $invalid['timezone']);
        $this->assertSame('2026-10-27 19:30:00', $missing['at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-27 19:30:00', $invalid['at']->format('Y-m-d H:i:s'));
    }

    public function test_the_instant_is_in_the_future_and_utc(): void
    {
        $now = CarbonImmutable::parse('2026-10-27 23:00:00', 'UTC');
        $result = app(TikTokTimeSuggester::class)->next('UTC', $now);

        $this->assertTrue($result['at']->greaterThan($now));
        $this->assertSame('UTC', $result['at']->timezoneName);
        $this->assertSame(0, $result['at']->offset);
        $this->assertTrue($result['at']->equalTo($result['at']->utc()));
    }
}
