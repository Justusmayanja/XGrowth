<?php

namespace App\Services\Content;

/**
 * A square flyer as a PNG, drawn without GD so it works on this PHP.
 * Caption sits on a top bar. The picture fills the space under it.
 */
class FlyerPng
{
    public const SIZE = 720;

    public const CAPTION_BAND = 200;

    private int $size;

    /** @var string packed RGB */
    private string $pixels;

    public function __construct(int $size, int $red, int $green, int $blue)
    {
        $this->size = $size;
        $this->pixels = str_repeat(chr($red).chr($green).chr($blue), $size * $size);
    }

    public static function make(int $hue, string $headline, string $footer): string
    {
        $size = self::SIZE;
        $band = self::CAPTION_BAND;
        [$pr, $pg, $pb] = self::hsl($hue, 38, 22);
        $canvas = new self($size, $pr, $pg, $pb);

        [$ar, $ag, $ab] = self::hsl($hue, 72, 58);
        $canvas->fillCircle(580, $band + 90, 210, $ar, $ag, $ab);
        $canvas->fillRect(0, 612, $size, 28, $ar, $ag, $ab);

        [$sr, $sg, $sb] = self::hsl(($hue + 40) % 360, 30, 28);
        $canvas->fillCircle(90, 660, 150, $sr, $sg, $sb);

        $canvas->captionBar($hue, $headline);

        if ($footer !== '') {
            $canvas->drawText(mb_substr($footer, 0, 28), $size - 48, 3, 230, 226, 216, 'left');
        }

        return $canvas->png();
    }

    public static function withPhoto(int $hue, string $headline, string $rgb, int $width, int $height): string
    {
        $size = self::SIZE;
        $band = self::CAPTION_BAND;
        [$br, $bg, $bb] = self::hsl($hue, 35, 10);
        $canvas = new self($size, $br, $bg, $bb);
        $canvas->cover($rgb, $width, $height, 0, $band, $size, $size - $band);
        $canvas->captionBar($hue, $headline);

        return $canvas->png();
    }

    public function png(): string
    {
        $raw = '';
        $row = $this->size * 3;
        for ($y = 0; $y < $this->size; $y++) {
            $raw .= "\x00".substr($this->pixels, $y * $row, $row);
        }

        $chunks = self::chunk('IHDR', pack('NNCCCCC', $this->size, $this->size, 8, 2, 0, 0, 0));
        $chunks .= self::chunk('IDAT', (string) gzcompress($raw, 6));
        $chunks .= self::chunk('IEND', '');

        return "\x89PNG\r\n\x1a\n".$chunks;
    }

    public function fillRect(int $x, int $y, int $width, int $height, int $r, int $g, int $b): void
    {
        $x0 = max(0, $x);
        $y0 = max(0, $y);
        $x1 = min($this->size, $x + $width);
        $y1 = min($this->size, $y + $height);
        $pixel = chr($r).chr($g).chr($b);

        for ($row = $y0; $row < $y1; $row++) {
            $start = ($row * $this->size + $x0) * 3;
            $this->pixels = substr_replace($this->pixels, str_repeat($pixel, $x1 - $x0), $start, ($x1 - $x0) * 3);
        }
    }

    public function fillCircle(int $cx, int $cy, int $radius, int $r, int $g, int $b): void
    {
        $pixel = chr($r).chr($g).chr($b);
        $r2 = $radius * $radius;
        $y0 = max(0, $cy - $radius);
        $y1 = min($this->size - 1, $cy + $radius);

        for ($y = $y0; $y <= $y1; $y++) {
            $dy = $y - $cy;
            $span = (int) floor(sqrt(max(0, $r2 - $dy * $dy)));
            $x0 = max(0, $cx - $span);
            $x1 = min($this->size - 1, $cx + $span);
            $start = ($y * $this->size + $x0) * 3;
            $this->pixels = substr_replace($this->pixels, str_repeat($pixel, $x1 - $x0 + 1), $start, ($x1 - $x0 + 1) * 3);
        }
    }

    public function captionBar(int $hue, string $headline): void
    {
        [$br, $bg, $bb] = self::hsl($hue, 35, 10);
        $this->fillRect(0, 0, $this->size, self::CAPTION_BAND, $br, $bg, $bb);

        $lines = array_slice(self::wrap($headline, 18), 0, 3);
        $scale = 5;
        $lineH = 8 * $scale + 12;
        $y = 32;
        foreach ($lines as $line) {
            $this->drawText($line, $y, $scale, 255, 255, 255, 'left');
            $y += $lineH;
        }
    }

    public function cover(string $src, int $srcW, int $srcH, int $dx, int $dy, int $dw, int $dh): void
    {
        if ($srcW < 1 || $srcH < 1 || strlen($src) < $srcW * $srcH * 3) {
            return;
        }

        $scale = max($dw / $srcW, $dh / $srcH);
        $ox = (int) (($srcW * $scale - $dw) / 2);
        $oy = (int) (($srcH * $scale - $dh) / 2);
        $pixels = $this->pixels;

        for ($y = 0; $y < $dh; $y++) {
            $sy = min($srcH - 1, max(0, (int) floor(($y + $oy) / $scale)));
            for ($x = 0; $x < $dw; $x++) {
                $sx = min($srcW - 1, max(0, (int) floor(($x + $ox) / $scale)));
                $si = ($sy * $srcW + $sx) * 3;
                $di = (($dy + $y) * $this->size + ($dx + $x)) * 3;
                $pixels[$di] = $src[$si];
                $pixels[$di + 1] = $src[$si + 1];
                $pixels[$di + 2] = $src[$si + 2];
            }
        }

        $this->pixels = $pixels;
    }

    public function drawText(string $text, int $top, int $scale, int $r, int $g, int $b, string $align = 'center'): void
    {
        $glyphs = [];
        $width = 0;
        foreach (self::chars($text) as $char) {
            $glyph = self::glyph($char);
            $glyphs[] = $glyph;
            $width += (count($glyph[0]) + 1) * $scale;
        }
        $left = $align === 'left'
            ? 28
            : max(24, (int) (($this->size - $width) / 2));
        $x = $left;
        foreach ($glyphs as $glyph) {
            $this->stamp($glyph, $x, $top, $scale, $r, $g, $b);
            $x += (count($glyph[0]) + 1) * $scale;
        }
    }

    /**
     * @param  list<list<int>>  $glyph
     */
    private function stamp(array $glyph, int $left, int $top, int $scale, int $r, int $g, int $b): void
    {
        $pixel = chr($r).chr($g).chr($b);
        foreach ($glyph as $row => $cols) {
            foreach ($cols as $col => $on) {
                if ($on !== 1) {
                    continue;
                }
                $x0 = $left + $col * $scale;
                $y0 = $top + $row * $scale;
                if ($x0 >= $this->size || $y0 >= $this->size) {
                    continue;
                }
                $x1 = min($this->size, $x0 + $scale);
                $y1 = min($this->size, $y0 + $scale);
                for ($y = max(0, $y0); $y < $y1; $y++) {
                    $start = ($y * $this->size + max(0, $x0)) * 3;
                    $this->pixels = substr_replace($this->pixels, str_repeat($pixel, $x1 - max(0, $x0)), $start, ($x1 - max(0, $x0)) * 3);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function wrap(string $text, int $max): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $try = $current === '' ? $word : $current.' '.$word;
            if (mb_strlen($try) > $max && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $try;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return array_slice($lines !== [] ? $lines : ['Today'], 0, 4);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function hsl(int $hue, float $saturation, float $lightness): array
    {
        $saturation /= 100;
        $lightness /= 100;
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $match = $lightness - $chroma / 2;
        [$red, $green, $blue] = match (true) {
            $hue < 60 => [$chroma, $x, 0],
            $hue < 120 => [$x, $chroma, 0],
            $hue < 180 => [0, $chroma, $x],
            $hue < 240 => [0, $x, $chroma],
            $hue < 300 => [$x, 0, $chroma],
            default => [$chroma, 0, $x],
        };

        return [
            (int) round(($red + $match) * 255),
            (int) round(($green + $match) * 255),
            (int) round(($blue + $match) * 255),
        ];
    }

    /**
     * @return list<string>
     */
    private static function chars(string $text): array
    {
        $clean = (string) preg_replace('/[^\x20-\x7E]/', '', $text);
        $letters = preg_split('//', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice($letters, 0, 28);
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', hexdec(hash('crc32b', $type.$data)));
    }

    /**
     * @return list<list<int>>
     */
    private static function glyph(string $char): array
    {
        $map = self::font();
        $bits = $map[$char] ?? $map[strtoupper($char)] ?? $map['?'];
        $rows = [];
        foreach (preg_split('/\s+/', trim($bits)) ?: [] as $row) {
            $cols = [];
            foreach (str_split($row) as $bit) {
                $cols[] = $bit === '1' ? 1 : 0;
            }
            $rows[] = $cols;
        }

        return $rows;
    }

    /**
     * 5x7 bitmaps for the letters a flyer needs.
     *
     * @return array<string, string>
     */
    private static function font(): array
    {
        return [
            ' ' => '00000 00000 00000 00000 00000 00000 00000',
            'A' => '01110 10001 10001 11111 10001 10001 10001',
            'B' => '11110 10001 10001 11110 10001 10001 11110',
            'C' => '01110 10001 10000 10000 10000 10001 01110',
            'D' => '11110 10001 10001 10001 10001 10001 11110',
            'E' => '11111 10000 10000 11110 10000 10000 11111',
            'F' => '11111 10000 10000 11110 10000 10000 10000',
            'G' => '01110 10001 10000 10111 10001 10001 01110',
            'H' => '10001 10001 10001 11111 10001 10001 10001',
            'I' => '11111 00100 00100 00100 00100 00100 11111',
            'J' => '00111 00010 00010 00010 00010 10010 01100',
            'K' => '10001 10010 10100 11000 10100 10010 10001',
            'L' => '10000 10000 10000 10000 10000 10000 11111',
            'M' => '10001 11011 10101 10101 10001 10001 10001',
            'N' => '10001 11001 10101 10011 10001 10001 10001',
            'O' => '01110 10001 10001 10001 10001 10001 01110',
            'P' => '11110 10001 10001 11110 10000 10000 10000',
            'Q' => '01110 10001 10001 10001 10101 10010 01101',
            'R' => '11110 10001 10001 11110 10100 10010 10001',
            'S' => '01111 10000 10000 01110 00001 00001 11110',
            'T' => '11111 00100 00100 00100 00100 00100 00100',
            'U' => '10001 10001 10001 10001 10001 10001 01110',
            'V' => '10001 10001 10001 10001 10001 01010 00100',
            'W' => '10001 10001 10001 10101 10101 10101 01010',
            'X' => '10001 10001 01010 00100 01010 10001 10001',
            'Y' => '10001 10001 01010 00100 00100 00100 00100',
            'Z' => '11111 00001 00010 00100 01000 10000 11111',
            'a' => '00000 00000 01110 00001 01111 10001 01111',
            'b' => '10000 10000 11110 10001 10001 10001 11110',
            'c' => '00000 00000 01110 10000 10000 10001 01110',
            'd' => '00001 00001 01111 10001 10001 10001 01111',
            'e' => '00000 00000 01110 10001 11111 10000 01110',
            'f' => '00110 01001 01000 11100 01000 01000 01000',
            'g' => '00000 00000 01111 10001 01111 00001 01110',
            'h' => '10000 10000 11110 10001 10001 10001 10001',
            'i' => '00100 00000 01100 00100 00100 00100 01110',
            'j' => '00010 00000 00110 00010 00010 10010 01100',
            'k' => '10000 10000 10010 10100 11000 10100 10010',
            'l' => '01100 00100 00100 00100 00100 00100 01110',
            'm' => '00000 00000 11010 10101 10101 10101 10101',
            'n' => '00000 00000 11110 10001 10001 10001 10001',
            'o' => '00000 00000 01110 10001 10001 10001 01110',
            'p' => '00000 00000 11110 10001 11110 10000 10000',
            'q' => '00000 00000 01111 10001 01111 00001 00001',
            'r' => '00000 00000 10110 11001 10000 10000 10000',
            's' => '00000 00000 01111 10000 01110 00001 11110',
            't' => '01000 01000 11100 01000 01000 01001 00110',
            'u' => '00000 00000 10001 10001 10001 10011 01101',
            'v' => '00000 00000 10001 10001 10001 01010 00100',
            'w' => '00000 00000 10001 10101 10101 10101 01010',
            'x' => '00000 00000 10001 01010 00100 01010 10001',
            'y' => '00000 00000 10001 10001 01111 00001 01110',
            'z' => '00000 00000 11111 00010 00100 01000 11111',
            '0' => '01110 10001 10011 10101 11001 10001 01110',
            '1' => '00100 01100 00100 00100 00100 00100 01110',
            '2' => '01110 10001 00001 00010 00100 01000 11111',
            '3' => '01110 10001 00001 00110 00001 10001 01110',
            '4' => '00010 00110 01010 10010 11111 00010 00010',
            '5' => '11111 10000 11110 00001 00001 10001 01110',
            '6' => '01110 10000 11110 10001 10001 10001 01110',
            '7' => '11111 00001 00010 00100 01000 01000 01000',
            '8' => '01110 10001 10001 01110 10001 10001 01110',
            '9' => '01110 10001 10001 01111 00001 00001 01110',
            "'" => '00100 00100 01000 00000 00000 00000 00000',
            '"' => '01010 01010 00000 00000 00000 00000 00000',
            ',' => '00000 00000 00000 00000 00100 00100 01000',
            '.' => '00000 00000 00000 00000 00000 00100 00100',
            '?' => '01110 10001 00001 00010 00100 00000 00100',
            '!' => '00100 00100 00100 00100 00100 00000 00100',
            ':' => '00000 00100 00100 00000 00100 00100 00000',
            '-' => '00000 00000 00000 11111 00000 00000 00000',
            '/' => '00001 00010 00100 01000 10000 00000 00000',
        ];
    }
}
