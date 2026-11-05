<?php

namespace App\Support;

final class YouTubeOAuth
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const CHANNELS_URL = 'https://youtube.googleapis.com/youtube/v3/channels';

    public const PLAYLIST_ITEMS_URL = 'https://youtube.googleapis.com/youtube/v3/playlistItems';

    public const VIDEOS_URL = 'https://youtube.googleapis.com/youtube/v3/videos';

    public const RESUMABLE_UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

    public const CAPTIONS_URL = 'https://www.googleapis.com/youtube/v3/captions';

    public const SEARCH_URL = 'https://youtube.googleapis.com/youtube/v3/search';

    public const COMMENTS_URL = 'https://youtube.googleapis.com/youtube/v3/commentThreads';

    public const ANALYTICS_URL = 'https://youtubeanalytics.googleapis.com/v2/reports';

    public const READONLY_SCOPE = 'https://www.googleapis.com/auth/youtube.readonly';

    public const ANALYTICS_READONLY_SCOPE = 'https://www.googleapis.com/auth/yt-analytics.readonly';

    public const UPLOAD_SCOPE = 'https://www.googleapis.com/auth/youtube.upload';

    public const STATE_TTL_MINUTES = 10;

    public const REFRESH_WITHIN_MINUTES = 10;
}
