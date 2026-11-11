<?php

namespace Tests\Unit;

use App\Support\TikTokWeek;
use PHPUnit\Framework\TestCase;

class TikTokWeekTest extends TestCase
{
    public function test_a_starter_week_is_a_teaching_motion_a_scene_and_an_invite(): void
    {
        $first = TikTokWeek::forIndex(0);
        $second = TikTokWeek::forIndex(1);
        $third = TikTokWeek::forIndex(2);

        $this->assertSame([TikTokWeek::MOTION, TikTokWeek::TEACH], [$first['format'], $first['job']]);
        $this->assertSame([TikTokWeek::SCENE, TikTokWeek::SHOW], [$second['format'], $second['job']]);
        $this->assertSame([TikTokWeek::CARD, TikTokWeek::INVITE], [$third['format'], $third['job']]);
    }

    public function test_the_next_week_keeps_the_pictures_and_corrects_instead_of_teaching(): void
    {
        $next = TikTokWeek::forIndex(3);

        $this->assertSame(TikTokWeek::MOTION, $next['format']);
        $this->assertSame(TikTokWeek::CORRECT, $next['job']);
        $this->assertSame(TikTokWeek::SCENE, TikTokWeek::forIndex(4)['format']);
        $this->assertSame(TikTokWeek::CARD, TikTokWeek::forIndex(5)['format']);
    }
}
