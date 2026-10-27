# Phase 6: TikTok account linking (OAuth), keys-optional

You are a senior Laravel + Next.js engineer working in the Mackie17 repo (Laravel app "Xpulse", Next.js in `apps/web`). Phases 1-5 are merged. Your job is **account linking only**: let a user connect and disconnect their own TikTok account, store tokens safely, keep them fresh, and make the desk and settings tell the truth about the connection.

Read `docs/SYSTEM.md` (sections 5, 12, 16) and the Phase 1-5 prompts in `docs/prompts/`.

## 1. Context

- TikTok API credentials do not exist yet. They will be added to `.env` after TikTok approves the app. **The code must be complete, tested with faked HTTP, and inert until the keys exist.** With no keys, connecting is unavailable and the UI keeps today's "waiting for TikTok approval" wording.
- X OAuth already works (`ConnectedAccountController`, `GET /api/auth/x/callback`, `connected_accounts`). **Mirror its structure and conventions.**
- Web uses Sanctum bearer tokens, not cookie sessions. The OAuth callback is a browser redirect from TikTok, so it cannot be authenticated by bearer token. It must identify the user through a server-side `state` record.
- **Linking does not enable posting.** Render and upload remain unbuilt. Connecting only flips the `tiktok_connection` blocker. The `video_file` blocker remains.

## 2. Verify TikTok's API first (do not trust this brief's memory)

The details below are from memory and may be outdated. Before coding, check TikTok's current developer docs (Login Kit for Web, OAuth v2) if you have network access, and **put every URL, parameter name, and scope string in one config/constants class** so a correction is one edit. If you cannot reach the docs, say so in your report and mark those constants "UNVERIFIED".

What I expect, to be verified:
- Authorize URL `https://www.tiktok.com/v2/auth/authorize/`, with `client_key` (not `client_id`), `scope`, `response_type=code`, `redirect_uri`, `state`, and PKCE (`code_challenge`, `code_challenge_method=S256`). **Note: TikTok's challenge may be the hex-encoded SHA-256 of the verifier, not base64url.** Verify.
- Token endpoint `https://open.tiktokapis.com/v2/oauth/token/` (form-encoded) for the code exchange and for `grant_type=refresh_token`. Returns `open_id`, `access_token`, `expires_in`, `refresh_token`, `refresh_expires_in`, `scope`.
- User info `https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name,avatar_url` (needs `user.info.basic`). `username` may require `user.info.profile`. Request only what the fields need.
- Revoke `https://open.tiktokapis.com/v2/oauth/revoke/`.
- Access tokens are short-lived (about 24h). Refresh tokens last much longer.
- Posting scopes (`video.upload`, `video.publish`) are **not requested in this phase**. Default scopes are `user.info.basic` only. Scopes come from config so a later phase can add them.

## 3. Before you write any code

1. Read the X connect flow end to end: `ConnectedAccountController`, the X OAuth service, how `state` and PKCE are stored, token storage, callback redirect, and its tests. Mirror it.
2. Read the `connected_accounts` migration/model: encrypted casts, unique key `(user_id, platform, platform_account_id)`, available columns (is there a status or last_error column?).
3. Read `config/services.php` and `.env.example` for the X entries.
4. Read `TikTokClipResource::blockers`, the dashboard `tiktok.connection` block, the Settings page TikTok card, and `QueuedPanel.tsx`.
5. Read the settings page structure for how the X card shows connected state and disconnect.
6. Check how the Phase 5 scheduler and `youtube:run`/`tiktok:run` are registered, for the token-refresh job style.

## 4. Hard boundaries

- Do NOT implement render, upload, posting, or request posting scopes.
- Do NOT change `confirm` semantics. Confirm still never writes a connection and never uploads.
- Do NOT touch X or YouTube logic or X's connect flow beyond reading it.
- Do NOT write to `posts` or `rate_limit_budgets`.
- No new dependencies. No new tables. If `connected_accounts` lacks a status/last_error column and you need one, add a **nullable column in a reversible migration** and tell me why; otherwise do not alter the schema.
- Never log or return access tokens, refresh tokens, the code verifier, the authorization code, or the client secret. Never put tokens in a URL.
- Never hardcode keys. Credentials come from `.env` via config only.
- If the repo contradicts this brief, stop and ask.

## 5. Deliverables (backend)

### A. Config
`config/services.php` `tiktok` block: `client_key`, `client_secret`, `redirect_uri`, `scopes` (default `user.info.basic`), from `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`, `TIKTOK_REDIRECT_URI`, `TIKTOK_SCOPES`. Add them, empty, to `.env.example` with a comment. `TikTokOAuthService::isConfigured()` is true only when key, secret, and redirect URI are all non-empty.

### B. `app/Services/TikTok/TikTokOAuthService.php`
- `authorizationUrl(User $user): string`: creates a single-use state (random, 40+ chars) and PKCE verifier, stores `{user_id, verifier}` in cache under the state with a 10 minute TTL, returns the URL. Throws a typed "not configured" exception when unconfigured.
- `handleCallback(string $state, ?string $code, ?string $error): ConnectedAccount`: consumes the state atomically (pull, so replay fails), rejects unknown/expired state, handles `error`/`access_denied`, exchanges the code, fetches basic user info, then upserts the `connected_accounts` row.
- Storage rules: `platform = tiktok`, `platform_account_id = open_id`, tokens encrypted the same way as X, `token_expires_at` set from `expires_in`, display name stored in the existing username column (or the closest existing column; say which). **One TikTok account per user for now:** connecting a different account replaces the previous TikTok row for that user in one transaction. The same account reconnecting updates in place. State this decision in a docblock (account count per tier is a later concern).
- A TikTok account already linked to a **different** user is rejected with a clear error (no stealing links).
- `refresh(ConnectedAccount $account): ConnectedAccount`: refresh grant, store rotated tokens. On an `invalid_grant`-type failure, mark the connection as needing reconnect (status/last_error) rather than deleting it, and never leave tokens half-updated.
- `ensureFresh(ConnectedAccount $account): ConnectedAccount`: refreshes if expiring within 10 minutes. Future upload code will call this.
- `disconnect(User $user): void`: best-effort revoke call (its failure must not block), then delete the row. Idempotent.
- All HTTP uses Laravel's `Http` client with timeouts, a limited retry on 5xx/connection errors only, and no retry on 4xx.

### C. Routes and controller
Add TikTok methods beside X's in `ConnectedAccountController` (or a dedicated controller if X's is not reusable; match the repo):
| Method | Path | Notes |
|---|---|---|
| POST | `/api/connected-accounts/tiktok/connect` | auth stack as other connected-account routes. Returns `{ "data": { "authorize_url": "..." } }`. Not configured: 503 with `{ "error": { "code": "tiktok_not_configured", "message": "TikTok linking isn't available yet. It's waiting on TikTok's approval." } }`. Throttle it |
| GET | `/api/auth/tiktok/callback` | public (like X). Never returns JSON to the browser: always redirects to the frontend settings page with `?tiktok=connected` or `?tiktok=error&reason=<code>` where code is one of `denied`, `expired`, `already_linked`, `failed`. No sensitive details in the URL |
| GET | `/api/connected-accounts/tiktok/status` | `{ "data": { "configured": bool, "connected": bool, "display_name": ?string, "avatar_url": ?string, "connected_at": ?ISO, "needs_reconnect": bool } }` |
| DELETE | `/api/connected-accounts/tiktok` | Disconnect. Idempotent |

`GET /api/connected-accounts` already lists accounts. Ensure TikTok rows appear without leaking tokens.

### D. Token upkeep
- `php artisan tiktok:refresh-tokens` refreshes TikTok tokens expiring within 24 hours, isolating per-account failures with logging (account id only). Schedule it daily with `withoutOverlapping`, in process like the other entries.
- Expired-but-refreshable tokens refresh lazily through `ensureFresh`.

### E. Truthful state elsewhere
- `TikTokClipResource::blockers`: `tiktok_connection` is removed only when an active, non-`needs_reconnect` TikTok account exists. A needs-reconnect account still counts as a blocker.
- Dashboard `tiktok.connection` becomes `{ "connected": bool, "display_name": ?string, "needs_reconnect": bool, "configured": bool }`. The dashboard `preview` flag and placeholder metrics stay as they are, because no TikTok stats are fetched in this phase and the banner must keep saying so.
- Admin customers/diagnostics: show TikTok connected yes/no and needs-reconnect (read-only, if an existing customer detail view lists connections).
- `diagnose()` is unchanged. Linking is not a guard for generating clips.

## 6. Deliverables (frontend)

- `lib/tiktok.ts`: add `startTikTokConnect`, `getTikTokStatus`, `disconnectTikTok`, reusing the existing error normalizer.
- **Settings TikTok card**, one state at a time:
  | State | UI |
  |---|---|
  | Not configured | Existing "waiting on TikTok's approval" copy. No button |
  | Not connected | Explainer + **Connect TikTok** button. Copy: "Connecting lets us post clips you've confirmed, once video posting is ready. Nothing is posted by connecting." |
  | Redirecting | Disabled button with spinner, `aria-busy`, then `window.location` to `authorize_url` |
  | Connected | Display name, optional avatar (with fallback), connected date, line: "Connected. Posting still needs your video file." and a **Disconnect** button |
  | Needs reconnect | Notice "TikTok needs you to sign in again." with **Reconnect** |
  | Return with `?tiktok=error&reason=...` | Inline message per reason (`denied`: "You cancelled the TikTok sign-in.", `expired`: "That sign-in took too long. Try again.", `already_linked`: "That TikTok account is linked to another Mackie17 user.", `failed`: "We couldn't connect TikTok. Try again."). Clear the query param after showing it |
  | Return with `?tiktok=connected` | Success toast, refetch status |
- Disconnect uses the existing AlertDialog pattern. Copy: "Disconnect TikTok? Confirmed clips stay queued but can't post until you reconnect." Buttons "Disconnect" and "Keep connected".
- **Desk (`QueuedPanel`)**: the connection checklist item shows done as "TikTok connection: connected as {display name}" when `blockers` lacks `tiktok_connection`; otherwise it keeps "waiting for TikTok approval" if not configured, or "not connected yet" with a link to Settings if configured. `Video file: not ready yet` is unchanged.
- Update `/dev/tiktok-states` fixtures with: not configured, not connected, connected, needs reconnect, queued-with-connection-but-no-file.
- Keep all Phase 4 honesty-critical copy. Never imply a clip will post automatically soon after connecting.
- Accessibility: status by icon + text, focus management after returning from the OAuth redirect (focus the TikTok card heading and announce via a polite live region), 44px targets, works at 360px.

## 7. Tests (backend, isolated DB, `Http::fake` and `Http::preventStrayRequests`)

- Unconfigured: connect returns 503 with the code, `isConfigured()` false, no HTTP calls made.
- Authorization URL contains the right params, a state, and a valid PKCE challenge; state is stored with 10 minute TTL and no secret is in the URL.
- Callback success: exchanges the code, fetches user info, creates the row with encrypted tokens (assert ciphertext in DB differs from plaintext), redirects with `?tiktok=connected`.
- Callback with unknown, expired, or **replayed** state: redirects `?tiktok=error&reason=expired`, no row, no HTTP call.
- `access_denied`, token exchange 4xx, user info failure: correct reason, no row left behind.
- Account already linked to another user: rejected, original owner unaffected.
- Reconnecting the same account updates in place. Connecting a different account replaces the old row.
- Refresh: success rotates tokens; `invalid_grant` marks needs-reconnect and keeps the row; 5xx retries then fails without corrupting tokens. `tiktok:refresh-tokens` isolates failures and only touches tokens expiring soon.
- Disconnect: revoke failure does not block deletion; idempotent; a queued clip stays queued and its `tiktok_connection` blocker returns.
- Blockers: connected clears `tiktok_connection` but not `video_file`; needs-reconnect keeps the blocker.
- Another user's connection never appears in status or the connected-accounts list. No token, verifier, or code appears in any response, redirect URL, or log (assert on captured logs).
- `confirm` still never writes `connected_accounts`. Existing X connect tests still pass. The existing suite is green.

Frontend: run `tsc --noEmit` and keep the dev fixtures current. If `next build` fails on Google Fonts again, say so and don't treat it as your bug.

## 8. Manual check (report honestly)

You cannot test against real TikTok without keys. Do instead:
1. Show the unconfigured Settings card and desk in the browser (the user will log in; if no session is possible, say so and use `/dev/tiktok-states`).
2. Document in `docs/TIKTOK_CONNECT_SETUP.md` exactly what the owner must do once approved: create the app and enable Login Kit for Web, register the redirect URI (`{API_URL}/api/auth/tiktok/callback`), note that TikTok redirect URIs generally must be HTTPS and local development will need a tunnel, add the four `.env` values, `php artisan config:clear`, and test the flow. Include a sandbox/target-user note if TikTok's docs require it. List the verified-vs-unverified API details.

## 9. Acceptance criteria

- [ ] Without keys, nothing breaks and the UI keeps "waiting for approval" wording. With keys, the flow is complete.
- [ ] Tokens are encrypted, never logged or exposed, and the state is single-use with a TTL.
- [ ] Callback always redirects (never leaks JSON or details) and rejects replay, expiry, and cross-user links.
- [ ] Disconnect and token refresh work, and failures never corrupt stored tokens.
- [ ] Blockers and the desk reflect real connection state. Posting is still not enabled, and no copy suggests it is.
- [ ] No upload, render, posting scopes, `posts`, `rate_limit_budgets`, X, or YouTube changes. No new dependencies or tables.
- [ ] Tests pass, `tsc` passes.

## 10. Report back with

1. Files created or changed.
2. Which TikTok API details you verified against docs and which are UNVERIFIED.
3. Differences between this brief and the repo, and what you chose (including any column added).
4. Test and type-check results.
5. The exact seams for the future upload phase (`ensureFresh`, scopes config, `blockers`, the confirmed-clip query).
6. Open questions.

If you hit ambiguity at any step, stop and ask instead of inventing a convention.