# Turning on read-only YouTube channel linking

This connection is separate from Google sign-in. It reads channel identity, subscribers, views, and watch time. It cannot upload or publish videos. Local ffmpeg rendering does not use this OAuth client and does not add upload scopes.

## Google Cloud setup

1. Create or select a project in [Google Cloud Console](https://console.cloud.google.com/).
2. Enable **YouTube Data API v3** and **YouTube Analytics API**.
3. Configure the OAuth consent screen and add the users needed while the app is in testing.
4. Create a **Web application** OAuth client.
5. Register this redirect URI exactly:

   `{API_URL}/api/auth/youtube/callback`

   Local example: `http://127.0.0.1:8000/api/auth/youtube/callback`

6. Put only these scopes in `YOUTUBE_SCOPES`:
   - `https://www.googleapis.com/auth/youtube.readonly`
   - `https://www.googleapis.com/auth/yt-analytics.readonly`

Do not add `youtube.upload` to `YOUTUBE_SCOPES`. The first connect filters the configured scopes down to those two read-only scopes. Upload is a second consent from Settings or the YouTube desk. That authorize URL requests only `https://www.googleapis.com/auth/youtube.upload` and asks Google to keep the scopes already granted.

## API environment

Add these values to `backend/.env`:

```dotenv
YOUTUBE_CLIENT_ID=
YOUTUBE_CLIENT_SECRET=
YOUTUBE_REDIRECT_URI=https://api.example.com/api/auth/youtube/callback
YOUTUBE_SCOPES=https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/yt-analytics.readonly
YOUTUBE_READ_QUOTA_DAILY=500
YOUTUBE_RESEARCH_CACHE_MINUTES=15
YOUTUBE_UPLOAD_AUDIT_APPROVED=false
YOUTUBE_UPLOAD_DAILY_BUDGET=100
```

`YOUTUBE_UPLOAD_AUDIT_APPROVED` defaults to false. While it is false, sends are private or they stay on this server. Public and unlisted are not requested. `YOUTUBE_UPLOAD_DAILY_BUDGET` defaults to 100 insert attempts per UTC day for the whole project, shared by every user. It is not the monthly video allowance (Starter 4, Pro 12, Agency 40).

The client ID, secret, and redirect URI must all be non-empty. Then clear cached configuration and restart the API:

The read budget is an app-side guard, separate from the Google project quota. Research refreshes are explicit, cache successful pulls, show failures while retaining prior data, and never run from `youtube:run`.

```sh
php artisan config:clear
```

## Verify the flow

1. Sign in to Mackie17 and open Settings.
2. Select **Connect YouTube**, approve the two read-only permissions, and return to `/settings?youtube=connected`.
3. Confirm Settings and the YouTube desk show the channel name and live metrics.
4. If Google rejects a metrics request, connected metrics must be blank with an error reason; seeded samples must not appear.
5. Choose **Allow uploads**. That second screen is only `youtube.upload`. Cancelling it leaves the read-only connection in place. Sending a video is still a separate step after that consent.
6. Disconnect. The app attempts to revoke the grant and always removes the local connection, even when revocation fails.
