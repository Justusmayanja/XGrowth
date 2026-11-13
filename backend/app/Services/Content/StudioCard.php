<?php

namespace App\Services\Content;

/**
 * A square poster. One caption, two matched colors, and a photograph only when one exists.
 * The type is Inter. Nothing else is written on the card.
 */
class StudioCard
{
    public const SIZE = 1080;

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && self::font() !== null;
    }

    public static function font(): ?string
    {
        $path = resource_path('video/fonts/Inter-Regular.ttf');

        return is_file($path) ? $path : null;
    }

    public static function flyer(string $seed, string $name, string $offer, string $contact = '', ?string $photoBytes = null): ?string
    {
        if (! self::available()) {
            return null;
        }

        $font = (string) self::font();
        $size = self::SIZE;
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            return null;
        }

        $name = trim($name);
        $offer = trim($offer);
        $contact = trim($contact);
        $photo = self::load($photoBytes);

        if ($photo !== null) {
            $panel = $contact === '' ? 340 : 400;
            $ink = self::matchedInk($photo);
            self::cover($image, $photo, 0, 0, $size, $size - $panel);
            $bar = imagecolorallocate($image, $ink[0], $ink[1], $ink[2]);
            imagefilledrectangle($image, 0, $size - $panel, $size, $size, $bar);
            $rule = imagecolorallocate($image, $ink[3], $ink[4], $ink[5]);
            imagefilledrectangle($image, 0, $size - $panel, $size, $size - $panel + 8, $rule);
            $white = imagecolorallocate($image, 255, 255, 255);
            self::stack($image, $font, $name, $offer, $contact, 72, $size - $panel + 28, $panel - 48, $size - 144, $white);
            imagedestroy($photo);

            return self::png($image);
        }

        $palette = self::palette($seed);
        $paper = imagecolorallocate($image, $palette['paper'][0], $palette['paper'][1], $palette['paper'][2]);
        $ink = imagecolorallocate($image, $palette['ink'][0], $palette['ink'][1], $palette['ink'][2]);
        $accent = imagecolorallocate($image, $palette['accent'][0], $palette['accent'][1], $palette['accent'][2]);
        imagefilledrectangle($image, 0, 0, $size, $size, $paper);
        imagefilledrectangle($image, 96, 128, 248, 136, $accent);
        self::stack($image, $font, $name, $offer, $contact, 96, 180, 760, $size - 192, $ink);

        return self::png($image);
    }

    private static function stack(\GdImage $image, string $font, string $name, string $offer, string $contact, int $x, int $top, int $height, int $width, int $color): void
    {
        $cursor = $top;
        if ($name !== '') {
            [$sizePx, $lines, $lineH] = self::fit($font, $name, $width, 180, 64, 36, 1.15, 2);
            self::paint($image, $font, $lines, $sizePx, $lineH, $x, $cursor, max($lineH, count($lines) * $lineH), $color);
            $cursor += max(80, count($lines) * $lineH) + 28;
        }
        $offerHeight = $height - ($cursor - $top) - ($contact === '' ? 0 : 72);
        if ($offer !== '' && $offerHeight > 40) {
            [$sizePx, $lines, $lineH] = self::fit($font, $offer, $width, $offerHeight, 36, 24, 1.3, 5);
            self::paint($image, $font, $lines, $sizePx, $lineH, $x, $cursor, $offerHeight, $color);
        }
        if ($contact !== '') {
            [$sizePx, $lines, $lineH] = self::fit($font, $contact, $width, 64, 28, 22, 1.2, 1);
            self::paint($image, $font, $lines, $sizePx, $lineH, $x, $top + $height - 64, 64, $color);
        }
    }

    public static function titled(string $photoBytes, string $caption): ?string
    {
        if (! self::available()) {
            return null;
        }

        $photo = self::load($photoBytes);
        if ($photo === null) {
            return null;
        }

        $font = (string) self::font();
        $size = self::SIZE;
        $image = imagecreatetruecolor($size, $size);
        if ($image === false) {
            imagedestroy($photo);

            return null;
        }

        $caption = trim($caption);
        [$sizePx, $lines, $lineH] = self::fit($font, $caption, $size - 160, 240, 44, 26, 1.25, 4);
        $band = max(180, min(320, (count($lines) * $lineH) + 96));
        self::cover($image, $photo, 0, 0, $size, $size);
        $ink = self::matchedInk($photo);
        imagedestroy($photo);
        $bar = imagecolorallocate($image, $ink[0], $ink[1], $ink[2]);
        imagefilledrectangle($image, 0, 0, $size, $band, $bar);
        $white = imagecolorallocate($image, 255, 255, 255);
        self::paint($image, $font, $lines, $sizePx, $lineH, 72, 0, $band, $white);

        return self::png($image);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function paint(\GdImage $image, string $font, array $lines, int $size, int $lineH, int $x, int $top, int $height, int $color): void
    {
        if ($lines === []) {
            return;
        }

        $block = count($lines) * $lineH;
        $y = $top + (int) (($height - $block) / 2) + $size;
        foreach ($lines as $line) {
            imagettftext($image, $size, 0, $x, $y, $color, $font, $line);
            $y += $lineH;
        }
    }

    /**
     * @return array{0: int, 1: list<string>, 2: int}
     */
    private static function fit(string $font, string $text, int $maxWidth, int $maxHeight, int $start, int $min, float $leading, int $maxLines): array
    {
        $text = trim($text);
        if ($text === '') {
            return [$start, [], (int) round($start * $leading)];
        }

        $size = $start;
        while ($size >= $min) {
            $lines = self::wrap($font, $size, $text, $maxWidth);
            $lineH = (int) round($size * $leading);
            if (count($lines) <= $maxLines && count($lines) * $lineH <= $maxHeight) {
                return [$size, $lines, $lineH];
            }
            $size -= 2;
        }

        $lineH = (int) round($min * $leading);
        $lines = array_slice(self::wrap($font, $min, $text, $maxWidth), 0, $maxLines);

        return [$min, $lines, $lineH];
    }

    /**
     * Dark ink and a lighter rule taken from the photograph, so the band matches the picture.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    private static function matchedInk(\GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(max($width, $height) / 36));
        $red = 0;
        $green = 0;
        $blue = 0;
        $count = 0;
        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $color = imagecolorat($image, $x, $y);
                $red += ($color >> 16) & 255;
                $green += ($color >> 8) & 255;
                $blue += $color & 255;
                $count++;
            }
        }
        $red = (int) round($red / max(1, $count));
        $green = (int) round($green / max(1, $count));
        $blue = (int) round($blue / max(1, $count));
        $hue = self::hue($red, $green, $blue);
        if ($hue === null) {
            return [28, 28, 28, 210, 210, 210];
        }

        $ink = FlyerPng::hsl($hue, 38, 16);
        $rule = FlyerPng::hsl($hue, 48, 58);

        return [$ink[0], $ink[1], $ink[2], $rule[0], $rule[1], $rule[2]];
    }

    private static function hue(int $red, int $green, int $blue): ?int
    {
        $r = $red / 255;
        $g = $green / 255;
        $b = $blue / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;
        if ($delta < 0.08) {
            return null;
        }

        $hue = match ($max) {
            $r => 60 * fmod((($g - $b) / $delta), 6),
            $g => 60 * ((($b - $r) / $delta) + 2),
            default => 60 * ((($r - $g) / $delta) + 4),
        };
        if ($hue < 0) {
            $hue += 360;
        }

        return (int) round($hue) % 360;
    }

    /**
     * @return array{paper: array{0: int, 1: int, 2: int}, ink: array{0: int, 1: int, 2: int}, accent: array{0: int, 1: int, 2: int}}
     */
    private static function palette(string $seed): array
    {
        $palettes = [
            ['paper' => [244, 240, 230], 'ink' => [28, 26, 23], 'accent' => [176, 74, 42]],
            ['paper' => [17, 34, 46], 'ink' => [245, 242, 234], 'accent' => [214, 164, 92]],
            ['paper' => [247, 244, 239], 'ink' => [24, 24, 24], 'accent' => [27, 107, 74]],
            ['paper' => [42, 24, 18], 'ink' => [248, 244, 238], 'accent' => [214, 112, 58]],
            ['paper' => [231, 240, 242], 'ink' => [16, 40, 48], 'accent' => [14, 116, 144]],
            ['paper' => [32, 24, 40], 'ink' => [246, 242, 236], 'accent' => [196, 96, 122]],
        ];
        $index = (int) (sprintf('%u', crc32($seed)) % count($palettes));

        return $palettes[$index];
    }

    private static function load(?string $bytes): ?\GdImage
    {
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    private static function cover(\GdImage $dest, \GdImage $src, int $dx, int $dy, int $dw, int $dh): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1 || $dw < 1 || $dh < 1) {
            return;
        }

        $scale = max($dw / $sw, $dh / $sh);
        $cropW = max(1, (int) round($dw / $scale));
        $cropH = max(1, (int) round($dh / $scale));
        $sx = max(0, (int) (($sw - $cropW) / 2));
        $sy = max(0, (int) (($sh - $cropH) / 2));
        imagecopyresampled($dest, $src, $dx, $dy, $sx, $sy, $dw, $dh, $cropW, $cropH);
    }

    /**
     * @return list<string>
     */
    private static function wrap(string $font, int $size, string $text, int $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $try = $current === '' ? $word : $current.' '.$word;
            $box = imagettfbbox($size, 0, $font, $try);
            $width = is_array($box) ? abs($box[2] - $box[0]) : $maxWidth + 1;
            if ($width > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $try;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private static function png(\GdImage $image): ?string
    {
        ob_start();
        $wrote = imagepng($image, null, 6);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $wrote && is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
