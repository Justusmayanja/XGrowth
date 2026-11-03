<?php

namespace App\Services\TikTok;

/**
 * User text is written into files, never into a command line.
 * ASS control characters are escaped so a line cannot change the script structure.
 */
final class SceneText
{
    public static function plain(string $text, int $max = 120): string
    {
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '', $text) ?? '';
        $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = trim($text);
        if (mb_strlen($text) > $max) {
            $text = rtrim(mb_substr($text, 0, $max - 1)).'…';
        }

        return $text;
    }

    public static function ass(string $text, int $max = 120): string
    {
        $text = self::plain($text, $max);
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('{', '\\{', $text);
        $text = str_replace('}', '\\}', $text);

        return $text;
    }
}
