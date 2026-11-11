<?php

namespace App\Services\TikTok;

/**
 * Small transparent icons for a motion-graphics post.
 * They are drawn here so the moving picture is type and symbols, not a generated frame.
 */
class MotionIcon
{
    private const SIZE = 96;

    public function write(string $path, string $name, string $hex = '#F4F1EA'): void
    {
        $rgb = $this->rgb($hex);
        $pixels = array_fill(0, self::SIZE, array_fill(0, self::SIZE, "\x00\x00\x00\x00"));
        $ink = static function (int $x, int $y) use (&$pixels, $rgb): void {
            if ($x < 0 || $y < 0 || $x >= self::SIZE || $y >= self::SIZE) {
                return;
            }
            $pixels[$y][$x] = $rgb;
        };

        match ($name) {
            'receipt' => $this->receipt($ink),
            'clock' => $this->clock($ink),
            'steps' => $this->steps($ink),
            'arrow' => $this->arrow($ink),
            default => $this->calendar($ink),
        };

        $raw = '';
        foreach ($pixels as $row) {
            $raw .= "\x00".implode('', $row);
        }
        $png = "\x89PNG\r\n\x1a\n"
            .$this->chunk('IHDR', pack('N2C5', self::SIZE, self::SIZE, 8, 6, 0, 0, 0))
            .$this->chunk('IDAT', zlib_encode($raw, ZLIB_ENCODING_DEFLATE))
            .$this->chunk('IEND', '');
        file_put_contents($path, $png);
    }

    private function calendar(callable $ink): void
    {
        $this->rect($ink, 18, 22, 60, 56, false);
        $this->rect($ink, 18, 22, 60, 14, true);
        $this->rect($ink, 30, 12, 6, 16, true);
        $this->rect($ink, 60, 12, 6, 16, true);
    }

    private function receipt(callable $ink): void
    {
        $this->rect($ink, 28, 10, 40, 76, false);
        foreach ([28, 40, 52, 64] as $y) {
            $this->rect($ink, 36, $y, 24, 4, true);
        }
    }

    private function clock(callable $ink): void
    {
        $this->ring($ink, 48, 48, 30, 24);
        $this->rect($ink, 46, 28, 4, 22, true);
        $this->rect($ink, 46, 46, 18, 4, true);
    }

    private function steps(callable $ink): void
    {
        foreach ([20, 48, 76] as $x) {
            $this->disc($ink, $x, 48, 10);
        }
    }

    private function arrow(callable $ink): void
    {
        $this->rect($ink, 16, 44, 40, 8, true);
        $this->rect($ink, 48, 32, 8, 32, true);
        $this->rect($ink, 56, 40, 8, 16, true);
        $this->rect($ink, 64, 46, 12, 6, true);
    }

    private function rect(callable $ink, int $x, int $y, int $w, int $h, bool $fill): void
    {
        for ($yy = $y; $yy < $y + $h; $yy++) {
            for ($xx = $x; $xx < $x + $w; $xx++) {
                $edge = $xx < $x + 3 || $yy < $y + 3 || $xx >= $x + $w - 3 || $yy >= $y + $h - 3;
                if ($fill || $edge) {
                    $ink($xx, $yy);
                }
            }
        }
    }

    private function disc(callable $ink, int $cx, int $cy, int $radius): void
    {
        for ($y = $cy - $radius; $y <= $cy + $radius; $y++) {
            for ($x = $cx - $radius; $x <= $cx + $radius; $x++) {
                if ((($x - $cx) ** 2) + (($y - $cy) ** 2) <= $radius ** 2) {
                    $ink($x, $y);
                }
            }
        }
    }

    private function ring(callable $ink, int $cx, int $cy, int $outer, int $inner): void
    {
        for ($y = $cy - $outer; $y <= $cy + $outer; $y++) {
            for ($x = $cx - $outer; $x <= $cx + $outer; $x++) {
                $distance = (($x - $cx) ** 2) + (($y - $cy) ** 2);
                if ($distance <= $outer ** 2 && $distance >= $inner ** 2) {
                    $ink($x, $y);
                }
            }
        }
    }

    private function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            $hex = 'F4F1EA';
        }

        return hex2bin($hex)."\xff";
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
