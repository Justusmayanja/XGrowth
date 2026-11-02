<?php

namespace App\Support;

/**
 * TikTok Content Posting API, inbox upload only.
 * Init and status are the documented v2 endpoints. Direct Post is not used here.
 */
final class TikTokContentPosting
{
    public const INBOX_INIT_URL = 'https://open.tiktokapis.com/v2/post/publish/inbox/video/init/';

    public const STATUS_URL = 'https://open.tiktokapis.com/v2/post/publish/status/fetch/';

    public const UPLOAD_SCOPE = 'video.upload';

    public const MAX_BYTES = 104857600;

    public const CHUNK_BYTES = 1572864;

    public const MAX_DURATION_SECONDS = 600;

    public const RETENTION_DAYS = 7;

    public const STUCK_MINUTES = 30;

    public const GIVE_UP_MINUTES = 120;

    public const DISK = 'tiktok_videos';
}
