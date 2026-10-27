# Mackie17 system description

This document is the working picture of the product as it exists in this repository. Use it as context before changing TikTok. Older docs disagree with the code: `docs/DEVELOPMENT_PLAN.md` still says TikTok and YouTube are “not started,” and `docs/PROJECT_FINDINGS.md` says to stay on X until X has paying users. The app has already moved on. YouTube has an engine. TikTok has the product contract, the desk, billing, and caption drafts, and no engine.

Names in the repo:

| Name | Where it appears |
|---|---|
| **Mackie17** | The product the user sees. Landing, app shell, billing, legal pages. |
| **Xpulse** | Laravel app name, API health payload (`"app": "Xpulse API"`), Artisan commands (`xpulse:evaluate`). |
| **XGrowth** | Repository folder. |
| **GrowthApp** | Early spec name in `docs/PROJECT_SPEC.md`. Ignore it for new work. |

## 1. What we are building

Mackie17 is one desk for people who already run **X, TikTok, and YouTube**. They sign in once, pick the networks they want, and describe each account: the business or brand, who it is for, and how it should sound. The system watches that niche, drafts in that voice, and publishes only under that network’s rule.

It is an AI growth assistant. It is not a shared content calendar, and it is not a bot that posts the same asset everywhere.

The loop on every network is:

**Observe → Analyze → Predict → Generate → Decide → Act → Learn**

A quiet niche stays quiet. What went out changes the next piece. The product does not send DMs. It does not retweet the user while they sleep. It does not scrape a whole social network. It does not like, follow, or comment in order to game a feed.

## 2. How the three networks differ

The same account description must not produce the same sentence on every network. Setup stores one profile, then a separate `platform_setups` row per network.

| | X | TikTok | YouTube |
|---|---|---|---|
| Medium | Text: post, thread, poll, quote | Short clip plus a caption | Short, episode, or an edit of footage the user filmed |
| When it publishes | No calendar. If posting is on, it sends when a moment scores high enough to travel | It suggests a time, then waits. Nothing goes out until the user confirms | User chooses at setup: post on its own, or wait for a yes |
| What “automation” means | Opportunity scoring and, at higher levels, unattended posting | Make the clip and offer a time. Confirmation stays on, including paid tiers | Make or edit the video, then follow the publish setting |
| Live connection | X OAuth works | Connection is not built. Settings says it waits on TikTok | Connection is not built. Upload waits on the channel connection and a video file |
| Engine in code | `GrowthAgentService`, every 5 minutes | None | `YouTubeEngineService`, hourly and once when setup completes |
| Desk | `/dashboard/x` | `/dashboard/tiktok` | `/dashboard/youtube` |

### TikTok product contract

This is the feature under construction. The landing page, billing copy, setup defaults, and the TikTok desk all say the same thing:

1. The user connects their own TikTok account and describes that account.
2. The system looks for ideas that fit that brand and those goals.
3. It writes what should be said and makes the clip.
4. It suggests a time.
5. It waits. The user confirms. Only then does it post.
6. How that clip did (views, completion, follows) changes the next idea.

TikTok goals, stored on `platform_setups.goals`:

| Key | Meaning |
|---|---|
| `for_you` | Get clips onto the For You feed |
| `followers` | Turn viewers into followers |
| `traffic` | Send people to a link or product |
| `promote_business` | Promote the business |
| `monetize` | Work toward TikTok creator payouts |

Setup writes TikTok settings as `{ "publish": "confirm" }`. There is no switch that turns TikTok into X-style autopilot. Starter, Pro, and Agency all say “suggests a time and waits for your yes.”

Do not copy `GrowthAgentService` onto TikTok. That service posts to X when the score is high and `auto_post_enabled` is on. TikTok’s act step is confirm, then upload.

### X, so TikTok work does not collide with it

X watches the niche and posts when a moment is strong. There is no schedule. The growth evaluation runs every five minutes. A loud moment can post within minutes. A quiet day posts nothing, even though the timer fired. The comment in `backend/routes/console.php` states that on purpose.

X automation levels live on `account_profiles` and are mirrored into the X `platform_setups.settings`:

| `automation_level` | What the desk says | Posting |
|---|---|---|
| 0 | Suggests only. You post | Drafts stay drafts |
| 1 (default) | Drafts the moment. You approve | Drafts stay drafts |
| 2 | Posts the strong moments on its own, if posting is on | `auto_post_enabled` is set when level is 2 or 3 |
| 3 | Posts on its own, even while you are offline, if posting is on | Same, plus the settings screen treats this as full auto |

Other X switches on `account_profiles`: `engine_paused`, `auto_reply_enabled` (default off), `auto_engage_enabled`, `mode` (`reactive` or `proactive`), `max_silence_minutes` (default 60). Proactive mode is described in the UI as “even on a quiet day.” Reactive mode is “when something is happening.”

Rule fallback thresholds, overridable in admin platform settings: engage at 68, post at 82 (`rules_engage_threshold`, `rules_post_threshold`). A fresh spike or a long silence can also justify a post. If the growth brain is on, it chooses POST, ENGAGE, or WAIT, and the safety budget can still turn a POST into a WAIT.

### YouTube, the pattern TikTok should follow

YouTube is the video module that already exists. TikTok should be a sibling of it, with a stricter publish rule.

At YouTube setup the user picks:

- `publish`: `auto` or `wait`
- `video_mode`: `short`, `series`, or `footage`
- `format`, unless the mode is footage: `faceless_voiceover`, `text_on_screen`, `scene`, `list_facts`

Goals: `subscribers`, `watch_time`, `search`, `traffic`, `promote_business`, `monetize`.

When YouTube setup is saved, `AccountSetupController` calls `YouTubeEngineService::run()`. The scheduler also calls `runAll()` hourly (`youtube:run`, schedule name `youtube-engine`).

`runAll()` only includes users who are not blocked, have finished onboarding, and have a completed YouTube `platform_setups` row. `run()` takes a per-user cache lock (`youtube-run-{id}`, 30 seconds).

`runUnlocked()` does nothing unless `video_mode` is `short` or `series`. Footage is user-uploaded, not invented by the hourly run. It also refuses to start a new piece while one is `waiting`, `queued`, or `awaiting_edit`, or while the last piece has no `learned_at`. A series waits six days after the last episode was learned.

Then:

- **Short.** `YouTubeDemandService::ranked()` scores local niche ideas (YouTube search is not connected). `YouTubeScriptWriter::short()` asks the model for JSON: title, hook, spoken lines, description, thumbnail, on-screen text, shots. The last `learning_note` is passed in. `text_on_screen` is marked as a text render. Other formats are marked as clips.
- **Series.** A `youtube_series` row holds title, premise, characters, and an outline. Each run scripts the next episode and advances `next_episode`.
- **Footage.** `storeFootage()` saves the upload and `YouTubeEditPlanner` writes an edit plan. Status becomes `awaiting_edit`.

`storePackage()` sets status to `queued` when publish is `auto`, otherwise `waiting`. The `package` JSON records hook, thumbnail, on-screen lines, shots, a render block (`provider: pending`, `ready: false`), and a publish block. The render step and the YouTube upload are both still unconnected. Confirming a waiting video moves it to `queued` and sets publish mode to `auto`. The note on that row still says the upload waits for the video file and the YouTube connection.

`recordResults(views, average_view_percent)` is allowed from `waiting` or `queued`. It writes a learning note (stayed if percent ≥ 50, left early if percent < 30, otherwise just recorded), sets status to `learned`, and immediately runs the engine again. Demand scoring boosts idea kinds that previously held viewers and lowers kinds that lost them.

YouTube routes, all behind `auth:sanctum` and `customer`:

| Method | Path | Action |
|---|---|---|
| POST | `/api/youtube/run` | Run the engine for the current user |
| POST | `/api/youtube/footage` | Upload footage plus an optional note |
| POST | `/api/youtube/videos/{video}/confirm` | Yes, post this |
| POST | `/api/youtube/videos/{video}/results` | Store views and average view percent |

## 3. Stack

| Layer | Choice |
|---|---|
| Web | Next.js 15, React 19, TypeScript, Tailwind, shadcn-style UI. `apps/web`. Dev server `http://localhost:3000`. |
| API | Laravel 12, PHP 8.2+, Sanctum. `backend`. `php artisan serve` → `http://127.0.0.1:8000`. Health: `GET /api/health`. |
| Database | PostgreSQL 16. Database, user, and password are `xpulse` in Docker. |
| Cache and queue | Redis 7. Laravel queue connection `redis`, cache store `redis`, client `predis`. Sessions use the database. |
| AI | `openai-php/laravel`. Key and default model live in `backend/.env`. Admin → Platform Settings overrides model and toggles at runtime. |
| Billing | `BILLING_GATEWAY` is `paddle` or `flutterwave`. |
| Mail | SMTP, used for email sign-in codes. |

The web app calls the API with `NEXT_PUBLIC_API_URL` (`apps/web/.env.local`, usually `http://127.0.0.1:8000/api`). Sanctum bearer tokens, not cookie sessions. `SANCTUM_STATEFUL_DOMAINS` includes `localhost:3000`.

### Local run

```bash
# from the repo root, once Docker is up
docker compose up -d

# API
cd backend && php artisan serve

# Web
cd apps/web && npm run dev
```

On this machine, system PostgreSQL already listens on 5432, so `docker-compose.override.yml` publishes the project database on **5433**. `DB_PORT=5433` in `backend/.env`. Redis stays on 6379.

PHP and Composer may be a user install in `~/.local/bin` rather than distro packages. That build can lack the `intl` extension, which makes `php artisan db:show` throw. The API itself runs without it.

Grant admin with `php artisan xpulse:make-admin you@example.com`.

## 4. Repository map

```
apps/web/                          Next.js app
  app/(app)/                       Signed-in product
    dashboard/[platform]/          Today desk for x, tiktok, youtube
    content-studio/                Compose
    drafts/                        Drafts, or "Waiting for you" on TikTok
    growth-agent/                  X queue
    trends/                        Topics, or "Ideas" on TikTok and YouTube
    engagement/                    X replies
    analytics/                     Results
    safety/                        X daily limits
    settings/
  app/(admin)/admin/               Overview, customers, activity, AI rules, platform settings, operations
  app/onboarding/                  Account setup
  components/dashboard/PlatformDesk.tsx
  components/AppShell.tsx          Nav changes with the active platform
  lib/platform.ts                  Active platform from the path, query, or localStorage
  lib/profile.ts                   Platform and goal catalogs on the client
  lib/content-formats.ts           tweet, thread, quote, poll, tiktok_caption, youtube_*

backend/
  app/Http/Controllers/Api/        Thin controllers
  app/Services/GrowthAgent/        X decision loop and safety budgets
  app/Services/TrendEngine/        Shared trend catalog and opportunity scores
  app/Services/AICore/             Model calls, prompts, usage limits
  app/Services/YouTube/            The video engine TikTok does not have yet
  app/Services/XApi/               X OAuth publish and account activity
  app/Services/Billing/            Quotes, trials, Paddle, Flutterwave
  app/Services/Auth/               Email codes
  app/Support/SetupCatalog.php     Platforms, goals, content-type mapping
  app/Support/YouTubeCatalog.php   Modes, formats, status labels
  routes/api.php
  routes/console.php               Scheduler and xpulse:* / youtube:run commands
  config/billing.php               Shelf prices and allowances

docs/                              Specs. This file is the one that matches the code.
docker-compose.yml                 postgres:16 and redis:7
```

There is no `app/Services/TikTok/`. There is no `tiktok_clips` table. There is no `tiktok:run` command.

## 5. Auth and who can call what

Sign-in is an email code, not a password form as the main path.

| Method | Path | Notes |
|---|---|---|
| POST | `/api/auth/email/send` | Throttled 8/min |
| POST | `/api/auth/email/verify` | Throttled 20/min. Returns a Sanctum token |
| POST | `/api/auth/logout` | Auth |
| GET | `/api/auth/me` | Auth |
| GET | `/api/auth/x/callback` | X OAuth return. Public, because the provider redirects here |

`users` carries `is_admin`, `is_blocked`, and `subscription_plan` (default `free`). Middleware `not_blocked` wraps the authenticated group. `customer` wraps the product routes. `admin` wraps `/api/admin/*`.

X connect status and disconnect:

- `GET /api/connected-accounts`
- `GET /api/connected-accounts/x/status`
- `DELETE /api/connected-accounts/x`

`connected_accounts.platform` can name any network, but only X has a connect flow. Tokens are stored on that row (`access_token`, `refresh_token`, `token_expires_at`, `platform_account_id`, `username`). Unique on `(user_id, platform, platform_account_id)`.

## 6. Onboarding

The wizard is `apps/web/app/onboarding/page.tsx`, backed by `AccountSetupController`.

1. **Platforms.** `POST /api/account/setup/platforms` with `platforms: x | tiktok | youtube` and optional `tiers` (`starter`, `pro`, `agency`). This writes `account_profiles.platforms` as a comma-separated list and starts or syncs a free trial for those lines.
2. **About.** `POST /api/account/setup/about`. Purpose, up to three niches, optional custom niche, description (20–2000 characters), optional audience, up to three tones, and a discovery blob (interests, weekly time, on-camera / voice-only / faceless).
3. **Per platform.** `POST /api/account/setup/platforms/{platform}`. Goals, one to three, from that platform’s list. X also requires `automation_level` and `mode`. YouTube also requires `publish`, `video_mode`, and `format` unless the mode is footage. TikTok only requires goals. The controller then stores `publish: confirm`.

Finishing any one platform sets `onboarding_completed`. Finishing X clears the growth-brain cooldown. Finishing YouTube runs the YouTube engine. Finishing TikTok does not start a job.

Purposes: `business`, `creator`, `side_hustle`, `exploring`.

Niches: `football`, `technology`, `business`, `crypto`, `motivation`, `memes`, `local_news`, `entertainment`, plus a custom string.

Tones: `professional`, `passionate`, `funny`, `motivational`, `news`.

If the niche step uses AI (`POST /api/account/setup/niche-ideas`) and the model is down, the controller falls back to three rule-based ideas. The lead platform changes the suggested format: TikTok becomes “short clips that each answer one question,” YouTube becomes a weekly video, X becomes a daily post with one opinion or tip.

`GET /api/account/setup` returns the wizard state.

## 7. Data model

### `account_profiles` (one per user)

Who the person is, plus the X engine switches. Niche, tone, and description are shared context. Platform-specific goals are not stored only here.

Important columns: `purpose`, `platforms` (comma-separated), `goal` (legacy X goals, still written when X setup completes), `niche`, `custom_niche`, `description`, `tone`, `audience`, `discovery` (JSON), `mode`, `automation_level`, `max_silence_minutes`, `onboarding_completed`, `engine_paused`, `auto_post_enabled`, `auto_reply_enabled`, `auto_engage_enabled`.

`AccountProfile::resolvedNiche()`, `goalsFor($platform)`, and `goalSentenceFor($platform)` are what the model prompts use.

### `platform_setups` (one per user per platform)

`platform`, `goals` (comma-separated keys), `settings` (JSON), `completed_at`. Unique on `(user_id, platform)`.

| Platform | `settings` today |
|---|---|
| `x` | `automation_level`, `mode` |
| `tiktok` | `publish: confirm` |
| `youtube` | `publish`, `video_mode`, `format` |

### `posts`

The shared draft table. Used for X posts and for TikTok captions. YouTube videos do **not** live here. They live in `youtube_videos`.

Columns: `content_type`, `content`, `status` (`draft` or `posted`), `trend_id`, `platform_post_id`, `virality_predicted`, `impressions`, `likes`, `reposts`, `replies`, `posted_at`, optional `connected_account_id`.

Content types (`ContentController::CONTENT_TYPES`):

| Type | Platform |
|---|---|
| `tweet`, `thread`, `quote`, `poll` | X |
| `tiktok_caption` | TikTok |
| `youtube_title`, `youtube_description`, `youtube_community` | YouTube text, not the video package |

`SetupCatalog::platformForContentType()` maps a type by prefix: `tiktok*` → tiktok, `youtube*` → youtube, anything else → x.

`POST /api/content/drafts/{post}/publish` rejects anything that is not an X draft with “Only X drafts can be posted from here.” A TikTok caption cannot be published through this route. That is intentional until a TikTok publish path exists.

### `youtube_videos` and `youtube_series`

See section 2. Status values: `waiting`, `queued`, `awaiting_edit`, `learned`. `package` is the script, render, and publish payload. `learning_note` and `learned_at` close the loop.

### `trends`

Shared catalog, not per user. `name`, `slug`, `score`, `velocity`, `growth_rate`, `competition`, `niche_tags`, `metadata`, `detected_at`. `TrendEngineService::fetchGlobalTrends()` refreshes it from X. `rankedInNiche($user)` is what the X growth agent reads. `OpportunityScorerService::scoreForProfile()` adds niche overlap, growth rate, and low competition onto the base score.

### `ai_decisions`

One row per X growth decision. `decision` (POST, ENGAGE, WAIT, and similar), `action_taken`, `opportunity_score`, `reason`, `context` JSON (engine name, trend, draft preview, token counts). This feed is the X queue at `/growth-agent`. TikTok has no equivalent log yet.

### `rate_limit_budgets`

Daily counters per user and `action_type`: `post`, `reply`, `follow`, `like`. Defaults from platform settings: post 25, reply 50, follow 10, like 100. Reset just after midnight (`SafetyResetJob` or an inline schedule call). These limits are the X safety meter. A TikTok clip cap should be its own budget, not a reuse of the X post counter, because a caption and a clip are different actions and the billing allowance is monthly clips, not daily tweets.

### Billing tables

- `platform_prices` — admin-editable shelf price per `(platform, tier)`. Seeded from `config/billing.php`.
- `billing_subscriptions` — status, interval, currency, subtotal, discount, total, gateway ids, period end.
- `billing_subscription_lines` — one row per platform on that subscription: tier, list and charged cents, whether it is the anchor (full price) line, accounts included, monthly allowance, allowance unit (`posts` on X, `clips` on TikTok, videos on YouTube).

Trials are layered on subscriptions (`TrialService`). Picking platforms during setup calls `syncForPlatforms`. `xpulse:expire-trials` and `xpulse:billing-sweep` run hourly.

### Other tables

`ai_rules` (admin drafting rules injected into prompts), `ai_usage_logs`, `platform_settings` (runtime overrides), `email_login_codes`, `personal_access_tokens`, Laravel `jobs`, `cache`, `sessions`.

## 8. Desks and navigation

`AppShell` swaps the sidebar from the active platform. `usePlatform()` reads `/dashboard/{platform}`, then `?platform=`, then `localStorage` key `mackie17-platform`.

| | X | TikTok | YouTube |
|---|---|---|---|
| Today | `/dashboard/x` | `/dashboard/tiktok` | `/dashboard/youtube` |
| Create | Compose | New clip → `/content-studio` | New video → `/content-studio` |
| Holding pen | Drafts | Waiting for you → `/drafts` | Titles and scripts → `/drafts` |
| Queue | `/growth-agent` | none | none |
| Ideas | Topics `/trends` | Ideas `/trends` | Ideas `/trends` |
| Replies | `/engagement` | none | none |
| Results | `/analytics` | What landed `/analytics` | What landed `/analytics` |
| Limits | `/safety` | none | none |
| Settings | `/settings` | `/settings` | `/settings` |

`GET /api/dashboard/platform/{platform}` builds the Today payload.

- **Chosen** if that platform is in `account_profiles.platforms`.
- **Setup** is the `platform_setups` row: completed flag, goals, settings.
- **Connected** is the active `connected_accounts` username, or null.
- **Preview** is true for TikTok and YouTube when nothing is connected. Preview metrics are deterministic placeholders from `crc32(userId + platform + niche)`, not live data. X metrics come from `XApiService::accountActivity` when X is connected.
- **Counts and drafts** come from `posts`, filtered by content-type prefix. TikTok drafts are `content_type LIKE 'tiktok%'`.
- **Ideas** are filled only for a connected X account (trend topics) and for a completed YouTube setup (`YouTubeEngineService::desk()`). The TikTok branch does not call a service, so `ideas` is empty and the pipeline steps “Ideas found” and “Written” both collapse onto draft counts.
- **Pipeline and series** are YouTube-only.

`TikTokBody` in `PlatformDesk.tsx` shows four steps: Ideas found, Written, Waiting for you, Posted. The waiting section says “Nothing goes out on TikTok until you confirm.” The idea button says “Write a clip” and links to Compose with `format=tiktok_caption`. There is no confirm button on a TikTok draft. The YouTube desk has “Yes, post this,” which calls `POST /api/youtube/videos/{id}/confirm`.

`/trends` and `/analytics` are still largely the X topic and growth screens. The TikTok sidebar renames them, but they are not a TikTok idea ranker or a TikTok results log.

## 9. Content generation

`POST /api/content/generate` asks `AICoreService` for a draft in a content type. `POST /api/content/drafts` saves it. `GET /api/content/posts` lists them. Drafts can be edited and deleted while `status` is `draft`.

TikTok caption instructions inside `AICoreService::formatInstructions()`:

> First line is a spoken hook. Then one or two lines of context. End with 3 to 5 specific hashtags. Under 400 characters.

`suggestMoments($user, $profile, $platform)` can invent four moments for TikTok, YouTube, or X from the account description. It is a shared prompt, not a clip generator. It forbids copying the user’s notes, category labels, and worldwide news, and it asks for one observation, one short story, one question, and one opinion. The TikTok desk does not call it yet. YouTube ideas come from `YouTubeDemandService`, which is a local ranked list, also not live platform search.

System prompts are wrapped by `buildSystemPrompt($feature, $instructions)` so admin `ai_rules` apply. Usage is counted in `ai_usage_logs` and blocked by `AiUsageService` when the user is over the limit. Web search is a separate toggle and quota, used more by X research than by captions.

If the model is missing, YouTube script writing falls back to a local writer and logs a warning. TikTok captions have no local fallback of their own beyond whatever `generate` already does for a failed call.

## 10. X growth engine, briefly

`GrowthAgentService::evaluateAllUsers()` runs for onboarded, unblocked, unpaused users. The scheduler calls it every 5 minutes in-process (`withoutOverlapping(15)`). It does not depend on a queue worker. That was a deliberate fix: a queued job never posts when no worker is running.

Per user, `evaluateOpportunities()`:

1. Loads niche-ranked trends, capped by `growth_brain_prefilter_limit` (default 5).
2. If the list is empty, logs WAIT: “Nothing loud enough is happening to post.”
3. If the growth brain is enabled, asks it to choose. On failure, falls back to rules.
4. POST still requires `SafetyEngineService::canAct($user, 'post')`.
5. `actOnPostOpportunity()` drafts in the account voice. Level 0–1 leaves the draft. Level 2–3 with `auto_post_enabled` calls `publishOrHold()`, which publishes through `XApiService` when the safety budget allows.
6. Every outcome is an `ai_decisions` row.

`GET /api/growth-agent/feed` and `POST /api/growth-agent/evaluate` expose this. Settings: `GET/PUT /api/growth-agent/settings`.

Engagement drafts are separate: `GET /api/engagement/feed`, `POST /api/engagement/reply-drafts`. They are an X feature. TikTok does not get a reply bot.

Trend refresh: `FetchGlobalTrendsJob` at the times in platform setting `TREND_FETCH_TIMES` (default `06:00,14:00,22:00`) when the queue is Redis. Otherwise the scheduler calls `TrendEngineService::fetchGlobalTrends()` at those times. `php artisan xpulse:seed-trends` forces a refresh. `php artisan xpulse:evaluate` runs the growth pass by hand.

## 11. Billing

Prices are per platform, not one bundle with three networks glued together. A cart keeps the most expensive line at full price and takes `discount_percent` (15) off the other lines, stopping at each line’s private minimum. Yearly charges 10 months (`yearly_months_charged`).

Shelf prices in `config/billing.php` (USD cents, before admin edits in `platform_prices`):

| | Starter | Pro | Agency |
|---|---|---|---|
| X | $32, 1 account, 40 posts | $49, 3 accounts, 150 posts, autopilot | $99, 10 accounts, 600 posts, 5 seats |
| TikTok | $49, 1 account, 8 clips | $79, 3 accounts, 24 clips | $149, 10 accounts, 80 clips, 5 seats |
| YouTube | priced in the same file | same shape | same shape |

TikTok’s summary string: “Makes the clip, suggests a time, and waits for your yes.”

Public catalog: `GET /api/billing/catalog`, `GET /api/plans`, `POST /api/billing/quote`. Authenticated checkout, cancel, subscription, and confirm live under `/api/billing/*`. Webhooks: `POST /api/billing/webhooks/paddle` and `/flutterwave` (no auth; signature checks live in the gateway). Admin can patch prices at `/api/admin/billing/prices`.

A TikTok monthly allowance is a clip count on the subscription line. The engine that would consume it does not exist. Do not decrement the X post allowance when a TikTok clip is confirmed.

## 12. Safety and boundaries

`SafetyEngineService` is the X daily meter shown at `/safety`. It is not a TikTok policy engine. Defaults are high on purpose for X text (25 posts a day is the setting default, not a promise that the product posts that often). The growth agent still refuses to post once the day’s post budget is spent.

Product boundaries that apply to TikTok work:

- Publish only to an account the user connected, with a token they authorized.
- TikTok posts only after an explicit confirm on that clip. Suggesting a time is not permission to upload.
- Stay inside the monthly clip allowance for their tier.
- Keep a daily cap so a confirm burst cannot dump the month in an hour. That cap should be TikTok-specific.
- Do not automate likes, follows, comments, duets, or stitches to manufacture reach.
- Do not scrape TikTok for a full-platform index. Observe the niche the user named, the way X observes a niche and YouTube ranks a local idea list until search is connected.
- Do not send DMs.
- A blocked user (`is_blocked`) is excluded from YouTube `runAll` and from the X evaluator. A TikTok runner must do the same.
- Admin AI rules apply to every model call, including clip scripts.
- Failed publishes must leave the clip unsent and say why. YouTube already records that the file and the connection are still missing instead of pretending the upload happened.

## 13. Scheduler, jobs, and commands

Defined in `backend/routes/console.php`.

| Schedule | What it does |
|---|---|
| Every 5 minutes | `GrowthAgentService::evaluateAllUsers()` |
| Hourly | `YouTubeEngineService::runAll()` |
| Hourly | Expire trials, billing sweep |
| Daily at configured times | Refresh the X trend catalog |
| Daily 00:05 | Delete expired safety budget rows |

| Command | Purpose |
|---|---|
| `php artisan xpulse:evaluate` | Growth pass for every onboarded user |
| `php artisan xpulse:seed-trends` | Force a trend refresh |
| `php artisan xpulse:reset-safety` | Drop old budget rows |
| `php artisan xpulse:make-admin {email}` | Set `is_admin` |
| `php artisan xpulse:seed-demo` | Demo users, posts, decisions |
| `php artisan xpulse:expire-trials` | End due trials |
| `php artisan xpulse:billing-sweep` | End lapsed plans, flag unconfirmed renewals |
| `php artisan youtube:run` | YouTube engine for every eligible account |

Jobs in `backend/app/Jobs/`: `FetchGlobalTrendsJob`, `SafetyResetJob`, `EvaluateGrowthOpportunitiesJob`, `EvaluateAllGrowthOpportunitiesJob`. The scheduled growth pass does not use those evaluate jobs.

There is no TikTok command and no TikTok schedule entry.

## 14. Admin

`/admin` requires `is_admin`. Shell links: Overview, Customers, Activity, Drafting rules, Platform Settings, Operations.

API under `/api/admin`: overview, customers (list, show, patch), decisions, posts, operations, system, seed trends, seed demo, evaluate-all, reset safety, plans, AI rules, platform settings, billing prices.

Platform settings are the runtime knobs for model name, web search, growth brain, evaluation cooldown, trend fetch times, and the safety limits. They override env fallbacks. AI rules are the standing instructions prepended to generation.

## 15. AI services

| Service | Role |
|---|---|
| `AICoreService` | Chat JSON, captions, moments, niche ideas, reply drafts, usage recording |
| `AiContextBuilder` | Account digest and content-mix hints for the X brain |
| `AiUsageService` | Generation and web-search quotas |
| `GrowthBrainService` | Optional model that picks POST / ENGAGE / WAIT for X. Cooldown via `growth_brain_eval_cooldown` |
| `YouTubeScriptWriter` | Short and episode JSON, with a local fallback |
| `YouTubeEditPlanner` | Edit plan for uploaded footage |
| `YouTubeDemandService` | Local niche ideas, adjusted by what the account already learned |

The model call pattern to copy: build a system prompt with the feature name, pass the account niche, tone, audience, goal sentence, and the last learning note, require JSON, normalize it, and record tokens against a feature string (`youtube_script`, `desk_moments`, and so on). If the model is down, YouTube still stores a locally written package so the desk is not empty.

## 16. What TikTok automation should become

Build a TikTok engine beside YouTube, not inside the X growth agent.

Suggested shape, matching code that already exists:

```
backend/app/Services/TikTok/TikTokEngineService.php    run, runAll, confirm, recordResults
backend/app/Services/TikTok/TikTokDemandService.php    ideas for this niche and these goals
backend/app/Services/TikTok/TikTokScriptWriter.php     hook, spoken lines, on-screen text, caption
backend/app/Support/TikTokCatalog.php                  formats, statuses, labels
backend/app/Models/TikTokClip.php
backend/app/Http/Controllers/Api/TikTokController.php
```

A clip row should look like `youtube_videos`, not like a `posts` caption. A caption can still be mirrored into `posts` with `content_type = tiktok_caption` if Compose must keep listing it, but the pipeline, the suggested time, the confirm, and the learning note belong on the clip.

Useful columns: `user_id`, `status`, `topic`, `hook`, `script`, `caption`, `suggested_for` (the offered time), `package` (shots, on-screen text, sound note, render block, publish block), `score`, `views`, `completion_percent` or average watch, `follows`, `learning_note`, `learned_at`, `confirmed_at`, `queued_at`, `posted_at`, `platform_post_id`.

Status machine:

```
drafted → waiting → queued → posted → learned
```

- `waiting` means a time has been suggested and the user has not said yes.
- `queued` means they confirmed. Upload still needs a rendered file and a connected TikTok account, the same honesty YouTube uses.
- `posted` means TikTok accepted the upload.
- `learned` means results were stored and the next run may start. Do not start a second clip while one is still `waiting` or `queued`, matching YouTube’s open-item guard.

`AccountSetupController` already completes TikTok without calling an engine. After the engine exists, completing TikTok setup should call `TikTokEngineService::run($user)`, and `routes/console.php` should schedule `runAll()` on its own name, for example `tiktok-engine`, with `withoutOverlapping`. A manual `tiktok:run` command should match `youtube:run`.

Desk wiring:

- `DashboardController::platform('tiktok')` should fill `ideas` and a pipeline from the TikTok engine, the way YouTube fills `pipeline` and `series`.
- `TikTokBody` should list waiting clips with the suggested time and a confirm action, the way `YouTubeBody` calls `/youtube/videos/{id}/confirm`.
- Confirm is `POST /api/tiktok/clips/{clip}/confirm`. It must not upload before the file and the connection exist, and it must not upload at all before this call.
- Results are `POST /api/tiktok/clips/{clip}/results`, then `run()` again so the next idea can use the learning note.
- Preview metrics stay placeholders until `connected_accounts.platform = tiktok` is real.

Generation:

- Demand is niche plus TikTok goals. `for_you` should prefer a fast hook. `traffic` should prefer a clip that can carry a link. `followers` should prefer a reason to stay. Do not rank with the X post threshold of 82.
- The script is short. One question, one answer, a spoken hook in the first line. Caption rules already exist for `tiktok_caption`.
- Render stays `provider: pending` until a video model or FFmpeg step is actually connected. Do not mark `ready: true` for a script alone.
- Optional footage, matching YouTube’s footage mode, is reasonable later. It is not required for the first engine pass.

Connect:

- A TikTok OAuth connect beside `ConnectedAccountController`’s X methods. Until the provider is approved, the settings card should keep saying the connection waits, and confirm should queue without pretending the upload succeeded.

Learning:

- Store views, completion, and follows. Write a short `learning_note` the next script call can read. YouTube’s bands (held if view percent ≥ 50, left early if < 30) are a reasonable first cut for completion, not a research claim about the For You algorithm.

Billing:

- On a successful queue or post, count the clip against `billing_subscription_lines.monthly_allowance` where `platform = tiktok` and `allowance_unit = clips`. Refuse a new run when the month is spent. Keep the X `rate_limit_budgets` post counter out of this.

## 17. API surface the TikTok work touches

Already present:

| Method | Path | TikTok relevance |
|---|---|---|
| GET | `/api/dashboard/platform/tiktok` | Today desk. Ideas empty. Draft counts from `tiktok_*` posts |
| POST | `/api/account/setup/platforms` | User can choose `tiktok` |
| POST | `/api/account/setup/platforms/tiktok` | Saves goals and `publish: confirm` |
| POST | `/api/content/generate` | Can emit `tiktok_caption` |
| POST | `/api/content/drafts` | Saves that caption |
| POST | `/api/content/drafts/{post}/publish` | Refuses TikTok |
| GET | `/api/billing/catalog` | TikTok tiers and the “waits for your yes” summary |

Not present, and the module needs them:

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/tiktok/run` | Run the engine for the current user |
| POST | `/api/tiktok/clips/{clip}/confirm` | User says yes |
| POST | `/api/tiktok/clips/{clip}/results` | Store performance and learn |
| GET | `/api/connected-accounts/tiktok/status` | OAuth, when the provider exists |
| DELETE | `/api/connected-accounts/tiktok` | Disconnect |

## 18. Docs that will mislead you

| File | Trust it for |
|---|---|
| `docs/SYSTEM.md` | This picture. Prefer it when it conflicts with the others. |
| `docs/DEVELOPMENT_PLAN.md` | Early X build order and route list. Phase 6 “TikTok not started / YouTube not started” is stale. YouTube’s engine and the TikTok desk landed after that checklist. |
| `docs/PROJECT_FINDINGS.md` | Original X growth-agent idea, safety principles, and the observe loop. Its “do not build TikTok until X is proven” is a sequencing note, not the current product. |
| `docs/PROJECT_SPEC.md` | An older X-only spec, including a Chrome extension that this repo does not ship. |
| `docs/SOURCE_CONVERSATION.md` | Raw product conversation. Useful for why TikTok waits and YouTube can post. Noisy. |
| `LOCAL_DEV.md` | How to boot the stack. It still mentions Windows and XAMPP. |
| `README.md` | Short start commands. |

## 19. Current status in one pass

Shipped enough to use:

- Email sign-in, Sanctum, onboarding for X, TikTok, and YouTube.
- X connect, trend refresh, opportunity scoring, growth brain with a rules fallback, draft or publish, safety budgets, decision feed.
- YouTube setup, hourly engine, scripts, series, footage edit plans, confirm, and a learning handoff. Render and upload are explicitly pending.
- Shared Compose for TikTok captions and YouTube text formats.
- Billing catalog, quotes, trials, Paddle and Flutterwave webhooks, admin price edits.
- Admin customers, rules, platform settings, operations.

TikTok specifically:

- The contract is fixed in the UI, setup, and prices.
- The desk renders and tells the user they must confirm.
- Captions can be generated and saved as drafts.
- There is no idea ranker, no clip record, no suggested time stored anywhere, no confirm endpoint, no render, no TikTok OAuth, no upload, and no learning loop.
- Dashboard numbers are previews until a TikTok account is connected.
