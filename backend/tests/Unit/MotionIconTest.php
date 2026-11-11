<?php

namespace Tests\Unit;

use App\Services\TikTok\MotionIcon;
use PHPUnit\Framework\TestCase;

class MotionIconTest extends TestCase
{
    public function test_an_icon_is_a_png(): void
    {
        $path = sys_get_temp_dir().'/mackie-motion-icon.png';
        (new MotionIcon)->write($path, 'calendar');
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $bytes);
        $this->assertGreaterThan(200, strlen($bytes));
    }
}
