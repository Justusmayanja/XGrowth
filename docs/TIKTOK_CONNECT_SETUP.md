# Turning on TikTok account linking

The linking code is already in the app. It does nothing until TikTok approves an app and these four values are set. Connecting an account does not post, render, or upload.

## What to do in the TikTok developer portal

1. Create an app at [developers.tiktok.com](https://developers.tiktok.com) and open **Manage apps**.
2. Add the **Login Kit** product and enable it for **Web**.
3. Copy the client key and client secret. They belong in the API `.env`, never in the Next.js app and never in git.
4. Register this redirect URI exactly. TikTok requires it to be absolute `https`, static (no query string), and without a `#` fragment. Ten URIs maximum, each under 512 characters.

   `{API_URL}/api/auth/tiktok/callback`

   Example: `https://api.example.com/api/auth/tiktok/callback`

5. Local `http://127.0.0.1:8000/...` will be rejected. For a local test, expose the API with an HTTPS tunnel and register that tunnel URL as the redirect URI.
6. Request only `user.info.basic` for this phase. Do not add `video.upload` or `video.publish` until a later phase posts video.
7. Scopes and the app itself may need TikTok's review before any TikTok user can sign in. If the portal shows a sandbox or a target-user list, add the account you will use to test. That sandbox step was not on the Login Kit pages fetched on 4 August 2026, so confirm it in the portal.

## What to put in `backend/.env`

```
TIKTOK_CLIENT_KEY=
TIKTOK_CLIENT_SECRET=
TIKTOK_REDIRECT_URI=https://api.example.com/api/auth/tiktok/callback
TIKTOK_SCOPES=user.info.basic
```

`TIKTOK_REDIRECT_URI` must match the URI registered with TikTok, character for character. All three of the key, the secret, and the redirect URI must be non-empty or the Settings card stays on "waits on TikTok" and connect returns 503.

Then:

```
php artisan config:clear
```

Restart the API process so it reads the new env.

## Try the flow

1. Sign in to Mackie17 and open Settings.
2. **Connect TikTok** should leave the site for TikTok and come back to `/settings?tiktok=connected`.
3. The desk checklist for a confirmed clip should say the TikTok connection is connected, and still say the video file is not ready.
4. **Disconnect** should remove the link even if TikTok's revoke call fails.

## API details that were checked

Checked against TikTok's docs (Login Kit for Web, User Access Token Management, Get User Info), pages last updated 4 August 2026:

| Detail | Value |
|---|---|
| Authorize | `https://www.tiktok.com/v2/auth/authorize/` |
| Authorize params for Web | `client_key`, `response_type=code`, `scope` (comma-separated), `redirect_uri`, `state`. Optional `disable_auto_auth` is not sent |
| Token and refresh | `POST https://open.tiktokapis.com/v2/oauth/token/` form body. `grant_type` is `authorization_code` or `refresh_token` |
| Token fields | `client_key`, `client_secret`, `code`, `redirect_uri`, and `code_verifier` when PKCE was used. Response includes `open_id`, `access_token`, `expires_in` (24 hours), `refresh_token`, `refresh_expires_in` (365 days), `scope` |
| Revoke | `POST https://open.tiktokapis.com/v2/oauth/revoke/` with `client_key`, `client_secret`, and `token` (the access token) |
| User info | `GET https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name,avatar_url`. `display_name` and `avatar_url` are `user.info.basic`. `username` needs `user.info.profile` and is not requested |
| Error body | `error` plus `error_description` on the token endpoint. User info uses `error.code`, and `ok` means success |

PKCE note: the Web guide does not list `code_challenge`. Desktop, iOS, and Android do, and the challenge is the hex SHA-256 of the verifier, not base64url. The token guide says `code_verifier` is required for mobile and desktop only. This app still sends the hex challenge and the verifier, because a later correction is one change in `App\Support\TikTokOAuth` and `TikTokOAuthService`. If TikTok rejects those fields on a web app, remove them there.

Display name is stored in `connected_accounts.username`. There is no avatar column, so the status payload's `avatar_url` is null and the Settings card shows an initial. A link that needs a new sign-in keeps the row and sets `is_active` to false. Disconnect deletes the row.
