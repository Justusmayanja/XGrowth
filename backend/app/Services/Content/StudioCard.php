<?php

namespace App\Services\Content;

/**
 * A square card set in Inter. The type is real, the color is flat,
 * and a photograph is cropped in only when one was given.
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

    /**
     * @param  list<string>  $lines
     */
    public static function flyer(int $hue, string $name, array $lines, ?string $photoBytes = null): ?string
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

        [$paperR, $paperG, $paperB] = FlyerPng::hsl($hue, 16, 94);
        [$inkR, $inkG, $inkB] = FlyerPng::hsl($hue, 22, 14);
        [$accentR, $accentG, $accentB] = FlyerPng::hsl($hue, 48, 38);
        $paper = imagecolorallocate($image, $paperR, $paperG, $paperB);
        $ink = imagecolorallocate($image, $inkR, $inkG, $inkB);
        $muted = imagecolorallocate($image, (int) (($inkR + $paperR) / 2), (int) (($inkG + $paperG) / 2), (int) (($inkB + $paperB) / 2));
        $accent = imagecolorallocate($image, $accentR, $accentG, $accentB);
        imagefilledrectangle($image, 0, 0, $size, $size, $paper);

        $photo = self::load($photoBytes);
        $textBottom = 150;
        $nameLines = array_slice(self::wrap($font, 64, $name !== '' ? $name : 'Today', 880), 0, 3);
        foreach ($nameLines as $line) {
            $textBottom += 84;
            imagettftext($image, 64, 0, 84, $textBottom, $ink, $font, $line);
        }

        $textBottom += 36;
        imagefilledrectangle($image, 84, $textBottom, 220, $textBottom + 6, $accent);
        $textBottom += 48;

        $body = [];
        foreach ($lines as $line) {
            foreach (self::wrap($font, 32, $line, 880) as $wrapped) {
                $body[] = $wrapped;
                if (count($body) === 5) {
                    break 2;
                }
            }
        }
        foreach ($body as $line) {
            $textBottom += 48;
            imagettftext($image, 32, 0, 84, $textBottom, $muted, $font, $line);
        }

        if ($photo !== null) {
            $top = min($size - 280, $textBottom + 48);
            self::cover($image, $photo, 72, $top, $size - 144, $size - $top - 72);
            imagedestroy($photo);
        } else {
            [$fieldR, $fieldG, $fieldB] = FlyerPng::hsl($hue, 28, 86);
            $field = imagecolorallocate($image, $fieldR, $fieldG, $fieldB);
            $top = min($size - 220, $textBottom + 64);
            imagefilledrectangle($image, 0, $top, $size, $size, $field);
        }

        return self::png($image);
    }

    public static function titled(int $hue, string $title, string $photoBytes): ?string
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

        self::cover($image, $photo, 0, 0, $size, $size);
        imagedestroy($photo);

        [$barR, $barG, $barB] = FlyerPng::hsl($hue, 30, 10);
        $bar = imagecolorallocate($image, $barR, $barG, $barB);
        $white = imagecolorallocate($image, 255, 255, 255);
        $band = 200;
        imagefilledrectangle($image, 0, 0, $size, $band, $bar);

        $lines = array_slice(self::wrap($font, 42, $title !== '' ? $title : 'Today', 920), 0, 2);
        $y = count($lines) === 1 ? 118 : 84;
        foreach ($lines as $line) {
            imagettftext($image, 42, 0, 64, $y, $white, $font, $line);
            $y += 64;
        }

        return self::png($image);
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

        return $lines !== [] ? $lines : ['Today'];
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
