<?php

namespace Tests\Unit;

use App\Services\Content\FlyerPng;
use App\Services\Content\PngRgb;
use Tests\TestCase;

class CaptionedVisualTest extends TestCase
{
    public function test_a_png_round_trips_without_gd(): void
    {
        $png = (new FlyerPng(24, 12, 80, 200))->png();
        $decoded = PngRgb::decode($png);

        $this->assertNotNull($decoded);
        $this->assertSame(24, $decoded['width']);
        $this->assertSame(24, $decoded['height']);
        $this->assertSame(chr(12).chr(80).chr(200), substr($decoded['rgb'], 0, 3));
    }

    public function test_a_flyer_puts_the_caption_on_top(): void
    {
        $png = FlyerPng::make(210, 'HELLO THERE FRIEND', '');
        $decoded = PngRgb::decode($png);
        $this->assertNotNull($decoded);

        $top = $this->whitePixels($decoded['rgb'], $decoded['width'], 20, FlyerPng::CAPTION_BAND - 20);
        $below = $this->whitePixels($decoded['rgb'], $decoded['width'], 360, 520);

        $this->assertGreaterThan(40, $top);
        $this->assertGreaterThan($below, $top);
    }

    public function test_a_photo_sits_under_the_caption(): void
    {
        $photo = (new FlyerPng(48, 220, 30, 30))->png();
        $decoded = PngRgb::decode($photo);
        $this->assertNotNull($decoded);

        $card = FlyerPng::withPhoto(210, 'HELLO THERE FRIEND', $decoded['rgb'], $decoded['width'], $decoded['height']);
        $out = PngRgb::decode($card);
        $this->assertNotNull($out);

        $this->assertGreaterThan(40, $this->whitePixels($out['rgb'], $out['width'], 20, FlyerPng::CAPTION_BAND - 20));
        $this->assertFalse($this->isRed($out['rgb'], $out['width'], 40, 80));
        $this->assertTrue($this->isRed($out['rgb'], $out['width'], 400, 500));
    }

    private function whitePixels(string $rgb, int $width, int $y0, int $y1): int
    {
        $count = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $i = ($y * $width + $x) * 3;
                if (ord($rgb[$i]) > 240 && ord($rgb[$i + 1]) > 240 && ord($rgb[$i + 2]) > 240) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function isRed(string $rgb, int $width, int $y0, int $y1): bool
    {
        $red = 0;
        $seen = 0;
        for ($y = $y0; $y < $y1; $y += 8) {
            for ($x = 0; $x < $width; $x += 8) {
                $i = ($y * $width + $x) * 3;
                $seen++;
                if (ord($rgb[$i]) > 180 && ord($rgb[$i + 1]) < 80 && ord($rgb[$i + 2]) < 80) {
                    $red++;
                }
            }
        }

        return $seen > 0 && ($red / $seen) > 0.8;
    }
}
