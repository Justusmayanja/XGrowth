<?php

namespace App\Support;

/**
 * ElevenLabs text-to-speech constants. The in-app cap is an estimate.
 * The ElevenLabs invoice is the source of truth.
 *
 * Verified against the Create speech reference (POST /v1/text-to-speech/{voice_id},
 * header xi-api-key, body text and model_id, default model eleven_multilingual_v2,
 * default output_format mp3_44100_128) and the API pricing page.
 *
 * UNVERIFIED: the 429 response body, concurrency headers, and a model id for "v4 Turbo".
 * A 429 is treated as a normal failure and the body is never parsed.
 * Published character limits are above the 500-character speak() cap, so they are not enforced here.
 */
final class ElevenLabsCatalog
{
    public const PATH = '/v1/text-to-speech';

    public const DEFAULT_MODEL = 'eleven_multilingual_v2';

    public const DEFAULT_OUTPUT_FORMAT = 'mp3_44100_128';

    public const FEATURE = 'tiktok_tts';

    /**
     * Mills per 1,000 characters. 1 mill is $0.001, so 80 mills is $0.08.
     *
     * @var array<string, int>
     */
    public const MILLS_PER_THOUSAND = [
        'eleven_multilingual_v2' => 80,
        'eleven_v3' => 80,
        'eleven_v4' => 80,
        'eleven_flash_v2' => 40,
        'eleven_flash_v2_5' => 40,
        'eleven_turbo_v2_5' => 40,
    ];

    public static function millsPerThousand(string $model): int
    {
        return self::MILLS_PER_THOUSAND[$model] ?? max(self::MILLS_PER_THOUSAND);
    }

    /**
     * Reserve at least 10 mills. Unknown models use the highest known rate.
     */
    public static function reserveMills(string $model, int $characters): int
    {
        $characters = max(0, $characters);
        $mills = (int) ceil($characters * self::millsPerThousand($model) / 1000);

        return max(10, $mills);
    }
}
