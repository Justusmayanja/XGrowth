<?php

namespace App\Support;

/**
 * TikTok Login Kit addresses, checked against the developer docs on 2026-08-04.
 *
 * Web authorize parameters are client_key, scope, redirect_uri, state, and response_type.
 * PKCE is documented for desktop, iOS, and Android, and the challenge is the hex SHA-256
 * of the verifier (not base64url). This phase sends that challenge on web as well so the
 * code exchange can include code_verifier. The token guide marks code_verifier as required
 * for mobile and desktop only.
 */
final class TikTokOAuth
{
    public const AUTHORIZE_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    public const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    public const REVOKE_URL = 'https://open.tiktokapis.com/v2/oauth/revoke/';

    public const USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

    public const USER_FIELDS = 'open_id,display_name,avatar_url';

    public const CHALLENGE_METHOD = 'S256';

    public const DEFAULT_SCOPES = 'user.info.basic';

    public const STATE_TTL_MINUTES = 10;

    public const REFRESH_WITHIN_MINUTES = 10;

    public const REFRESH_JOB_WITHIN_HOURS = 24;
}
