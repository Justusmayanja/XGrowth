<?php

namespace App\Services\Content;

/**
 * Reads an 8-bit RGB or RGBA PNG into packed RGB. No GD.
 *
 * @phpstan-type Decoded array{width: int, height: int, rgb: string}
 */
class PngRgb
{
    /**
     * @return Decoded|null
     */
    public static function decode(string $bytes): ?array
    {
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        $pos = 8;
        $end = strlen($bytes);
        $width = 0;
        $height = 0;
        $bit = 0;
        $color = 0;
        $interlace = 1;
        $idat = '';

        while ($pos + 8 <= $end) {
            $n = unpack('N', substr($bytes, $pos, 4));
            if (! is_array($n)) {
                return null;
            }
            $length = (int) $n[1];
            if ($length < 0 || $pos + 12 + $length > $end) {
                return null;
            }
            $type = substr($bytes, $pos + 4, 4);
            $data = substr($bytes, $pos + 8, $length);
            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight/Cbit/Ccolor/Ccomp/Cfilter/Cinterlace', $data);
                if (! is_array($header)) {
                    return null;
                }
                $width = (int) $header['width'];
                $height = (int) $header['height'];
                $bit = (int) $header['bit'];
                $color = (int) $header['color'];
                $interlace = (int) $header['interlace'];
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
            $pos += 12 + $length;
        }

        if ($width < 1 || $height < 1 || $width > 2048 || $height > 2048) {
            return null;
        }
        if ($bit !== 8 || $interlace !== 0 || ! in_array($color, [2, 6], true) || $idat === '') {
            return null;
        }

        $raw = @gzuncompress($idat);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $bpp = $color === 6 ? 4 : 3;
        $stride = $width * $bpp;
        $needed = ($stride + 1) * $height;
        if (strlen($raw) < $needed) {
            return null;
        }

        $prev = str_repeat("\x00", $stride);
        $rgb = '';
        $cursor = 0;
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$cursor]);
            $cursor++;
            $row = substr($raw, $cursor, $stride);
            $cursor += $stride;
            if (strlen($row) < $stride) {
                return null;
            }
            $row = self::unfilter($filter, $row, $prev, $bpp);
            if ($row === null) {
                return null;
            }
            $prev = $row;
            if ($bpp === 3) {
                $rgb .= $row;
            } else {
                for ($x = 0; $x < $width; $x++) {
                    $rgb .= $row[$x * 4].$row[$x * 4 + 1].$row[$x * 4 + 2];
                }
            }
        }

        return ['width' => $width, 'height' => $height, 'rgb' => $rgb];
    }

    private static function unfilter(int $filter, string $row, string $prev, int $bpp): ?string
    {
        $len = strlen($row);
        if ($filter === 0) {
            return $row;
        }

        $out = $row;
        for ($i = 0; $i < $len; $i++) {
            $x = ord($row[$i]);
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = ord($prev[$i]);
            $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
            $recon = match ($filter) {
                1 => ($x + $a) & 255,
                2 => ($x + $b) & 255,
                3 => ($x + intdiv($a + $b, 2)) & 255,
                4 => ($x + self::paeth($a, $b, $c)) & 255,
                default => null,
            };
            if ($recon === null) {
                return null;
            }
            $out[$i] = chr($recon);
        }

        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }
        if ($pb <= $pc) {
            return $b;
        }

        return $c;
    }
}
