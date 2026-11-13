<?php

namespace App\Support;

/**
 * Gemini Omni video, via the Interactions API.
 *
 * Model, path, and response fields follow the Omni cookbook (gemini-omni-1.2-flash,
 * response_format type video, aspect_ratio 9:16, duration, delivery uri).
 * A published per-clip price was not confirmed. RESERVE_MILLS is a placeholder
 * so the shared daily cap still has room for a script. The Gemini invoice is
 * the source of truth.
 */
final class GeminiCatalog
{
    public const MODEL = 'gemini-omni-1.2-flash';

    public const PATH = '/v1beta/interactions';

    public const FEATURE = 'tiktok_video';

    public const ASPECT = '9:16';

    public const DURATION = '15s';

    public const RESOLUTION = '720p';

    public const RESERVE_MILLS = 80;

    public const FAILED = "We couldn't make the video. Try again, or add your own.";

    public const QUOTA = 'The video service is out of capacity right now. Try again later, or add your own video.';
}
