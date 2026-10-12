<?php

namespace Tests\Unit;

use App\Services\Billing\QuoteCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class QuoteCalculatorTest extends TestCase
{
    public function test_mixed_tiers_discount_every_platform_except_the_most_expensive(): void
    {
        $quote = $this->calculator()->quote(
            [
                ['platform' => 'x', 'tier' => 'starter'],
                ['platform' => 'tiktok', 'tier' => 'pro'],
            ],
            'monthly',
            $this->catalog(),
        );

        $this->assertSame(10620, $quote['total_cents']);
        $this->assertSame(480, $quote['discount_cents']);
        $this->assertSame(2720, $quote['lines'][0]['charged_cents']);
        $this->assertFalse($quote['lines'][0]['is_anchor']);
        $this->assertSame(7900, $quote['lines'][1]['charged_cents']);
        $this->assertTrue($quote['lines'][1]['is_anchor']);
        $this->assertSame(0, $quote['lines'][1]['discount_cents']);
    }

    public function test_one_platform_pays_the_list_price(): void
    {
        $quote = $this->calculator()->quote(
            [['platform' => 'x', 'tier' => 'starter']],
            'monthly',
            $this->catalog(),
        );

        $this->assertSame(3200, $quote['total_cents']);
        $this->assertSame(0, $quote['discount_cents']);
        $this->assertTrue($quote['lines'][0]['is_anchor']);
    }

    public function test_three_platforms_discount_the_two_cheaper_lines(): void
    {
        $quote = $this->calculator()->quote(
            [
                ['platform' => 'x', 'tier' => 'starter'],
                ['platform' => 'tiktok', 'tier' => 'starter'],
                ['platform' => 'youtube', 'tier' => 'pro'],
            ],
            'monthly',
            $this->catalog(),
        );

        $this->assertSame(10900, $quote['lines'][2]['charged_cents']);
        $this->assertTrue($quote['lines'][2]['is_anchor']);
        $this->assertSame(4165, $quote['lines'][1]['charged_cents']);
        $this->assertSame(2720, $quote['lines'][0]['charged_cents']);
        $this->assertSame(17785, $quote['total_cents']);
    }

    public function test_discount_stops_at_the_platform_minimum(): void
    {
        $catalog = $this->catalog();
        $catalog['x']['tiers']['starter']['list_cents'] = 2900;

        $quote = $this->calculator()->quote(
            [
                ['platform' => 'x', 'tier' => 'starter'],
                ['platform' => 'tiktok', 'tier' => 'pro'],
            ],
            'monthly',
            $catalog,
        );

        $this->assertSame(2500, $quote['lines'][0]['charged_cents']);
        $this->assertTrue($quote['lines'][0]['discount_limited']);
        $this->assertSame(400, $quote['lines'][0]['discount_cents']);
    }

    public function test_yearly_price_cannot_average_below_the_monthly_minimum(): void
    {
        $quote = $this->calculator()->quote(
            [
                ['platform' => 'x', 'tier' => 'starter'],
                ['platform' => 'tiktok', 'tier' => 'pro'],
            ],
            'yearly',
            $this->catalog(),
        );

        $this->assertSame(30000, $quote['lines'][0]['charged_cents']);
        $this->assertTrue($quote['lines'][0]['yearly_floor_applied']);
        $this->assertSame(79000, $quote['lines'][1]['charged_cents']);
        $this->assertSame(109000, $quote['total_cents']);
        $this->assertTrue($quote['yearly_floor_applied']);
    }

    public function test_equal_prices_keep_the_first_platform_at_full_price(): void
    {
        $catalog = $this->catalog();
        $catalog['tiktok']['tiers']['starter']['list_cents'] = 3200;
        $catalog['tiktok']['tiers']['starter']['minimum_cents'] = 2500;

        $quote = $this->calculator()->quote(
            [
                ['platform' => 'x', 'tier' => 'starter'],
                ['platform' => 'tiktok', 'tier' => 'starter'],
            ],
            'monthly',
            $catalog,
        );

        $this->assertTrue($quote['lines'][0]['is_anchor']);
        $this->assertSame(3200, $quote['lines'][0]['charged_cents']);
        $this->assertFalse($quote['lines'][1]['is_anchor']);
        $this->assertSame(2720, $quote['lines'][1]['charged_cents']);
    }

    public function test_duplicate_platform_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator()->quote(
            [
                ['platform' => 'x', 'tier' => 'starter'],
                ['platform' => 'x', 'tier' => 'pro'],
            ],
            'monthly',
            $this->catalog(),
        );
    }

    private function calculator(): QuoteCalculator
    {
        return new QuoteCalculator;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function catalog(): array
    {
        $tier = function (string $name, int $list, int $minimum): array {
            return [
                'name' => $name,
                'list_cents' => $list,
                'minimum_cents' => $minimum,
                'accounts' => 1,
                'allowance' => 10,
                'allowance_unit' => 'posts',
                'features' => ['Included'],
            ];
        };

        return [
            'x' => [
                'name' => 'X',
                'summary' => 'X',
                'tiers' => [
                    'starter' => $tier('Starter', 3200, 2500),
                    'pro' => $tier('Pro', 4900, 4000),
                    'agency' => $tier('Agency', 9900, 8000),
                ],
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'summary' => 'TikTok',
                'tiers' => [
                    'starter' => $tier('Starter', 4900, 4000),
                    'pro' => $tier('Pro', 7900, 6500),
                    'agency' => $tier('Agency', 14900, 12000),
                ],
            ],
            'youtube' => [
                'name' => 'YouTube',
                'summary' => 'YouTube',
                'tiers' => [
                    'starter' => $tier('Starter', 6900, 5500),
                    'pro' => $tier('Pro', 10900, 9000),
                    'agency' => $tier('Agency', 19900, 16000),
                ],
            ],
        ];
    }
}
