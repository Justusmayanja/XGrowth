<?php

namespace App\Support;

final class TikTokVideoCatalog
{
    public const TYPE_MOTION = 'motion';

    public const TYPE_CARTOON = 'cartoon';

    public const TYPE_AVATAR = 'avatar';

    public const TYPE_STOCK = 'stock';

    public const VOICE_FEMALE = 'female';

    public const VOICE_MALE = 'male';

    public const LANGUAGE_EN = 'en';

    public const CAPTIONS_BOLD = 'bold';

    public const CAPTIONS_CLEAN = 'clean';

    public const LENGTH_SHORT = 'short';

    public const LENGTH_STANDARD = 'standard';

    public const GENERATE_AHEAD = 'ahead';

    public const GENERATE_ON_CONFIRM = 'on_confirm';

    public const FONT_INTER = 'inter';

    public const FONT_SOURCE_SANS = 'source_sans';

    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public const FPS = 30;

    public const PREVIEW_WIDTH = 540;

    public const PREVIEW_HEIGHT = 960;

    public const MAX_SECONDS = 60;

    public const SHORT_SECONDS = 20;

    public const STANDARD_SECONDS = 35;

    public const AUDIO_PAD_SECONDS = 0.3;

    public const MAX_REGENERATIONS = 2;

    public const SIGNED_URL_MINUTES = 30;

    public const LOGO_MAX_BYTES = 2097152;

    public const LOGO_MAX_EDGE = 2000;

    public const ERROR_REGENERATE_LIMIT = 'regenerate_limit';

    /**
     * @return list<array{key: string, label: string, available: bool}>
     */
    public static function types(): array
    {
        return [
            ['key' => self::TYPE_MOTION, 'label' => 'Motion graphics', 'available' => true],
            ['key' => self::TYPE_CARTOON, 'label' => 'Cartoon', 'available' => false],
            ['key' => self::TYPE_AVATAR, 'label' => 'Real person', 'available' => false],
            ['key' => self::TYPE_STOCK, 'label' => 'Stock', 'available' => false],
        ];
    }

    public static function typeAvailable(string $type): bool
    {
        foreach (self::types() as $row) {
            if ($row['key'] === $type) {
                return $row['available'];
            }
        }

        return false;
    }
}
