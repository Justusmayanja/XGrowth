<?php

namespace App\Support;

/**
 * A starter week is three posts. The picture and the job move together.
 * The next week keeps the same three pictures and changes the point.
 */
final class TikTokWeek
{
    public const MOTION = 'motion';

    public const SCENE = 'scene';

    public const CARD = 'card';

    public const TEACH = 'teach';

    public const SHOW = 'show';

    public const CORRECT = 'correct';

    public const INVITE = 'invite';

    /**
     * @return array{index: int, slot: int, format: string, job: string}
     */
    public static function forIndex(int $index): array
    {
        $index = max(0, $index);
        $slot = $index % 3;
        $cycle = intdiv($index, 3);

        $format = match ($slot) {
            1 => self::SCENE,
            2 => self::CARD,
            default => self::MOTION,
        };
        $job = match ($slot) {
            1 => self::SHOW,
            2 => self::INVITE,
            default => $cycle % 2 === 1 ? self::CORRECT : self::TEACH,
        };

        return [
            'index' => $index,
            'slot' => $slot,
            'format' => $format,
            'job' => $job,
        ];
    }
}
