# YouTube in this repo

YouTube is a writing and planning desk with local ffmpeg rendering, a read-only channel connection, and a separate audited upload.

The channel OAuth grant is separate from Google sign-in. The first consent reads channel identity, subscribers, views, and watch time with `youtube.readonly` and `yt-analytics.readonly`. It does not include `youtube.upload`. Upload is a second consent and does not widen that first grant. Local rendering does not use either grant. Setup for the read-only connection is documented in [YOUTUBE_CONNECT_SETUP.md](YOUTUBE_CONNECT_SETUP.md).

Two separate features share the name:

1. **The video engine.** Setup, research, scripts, series, footage edit plans, confirm, and learning. Rows live in `youtube_videos`, `youtube_series`, and `youtube_research`. This is what `/dashboard/youtube` shows.
2. **Text compose.** Title, description, and community post drafts. Those are ordinary `posts` rows (`youtube_title`, `youtube_description`, `youtube_community`) written by `AICoreService` through `/api/content/generate`. They are not video packages and the engine does not read them.

Billing prices YouTube (Starter 4 videos / month, Pro 12, Agency 40) in `config/billing.php`. The marketing cart starts with YouTube turned off (`apps/web/components/landing/Pricing.tsx`). The upload path enforces that monthly allowance from the YouTube subscription line. It counts videos that were actually posted in the current billing period. Ship it does not spend the month. A failed insert does not either. A separate project-wide budget, `YOUTUBE_UPLOAD_DAILY_BUDGET` defaulting to 100, counts `videos.insert` attempts per UTC day across every user. The two limits are independent.

---

## From login to the desk

App login is email, not YouTube.

1. The web app posts `POST /api/auth/email/send` with `purpose: login` or `register`, then `POST /api/auth/email/verify` with the 6-digit code.
2. `EmailCodeService` creates or finds the user and returns a Sanctum token. The browser stores that token and sends it as a bearer token.
3. `GET /api/auth/me` decides the next page: admins go to `/admin`, everyone else goes to `/onboarding` until `account_profiles.onboarding_completed` is true, then `/dashboard`.
4. Optional Google or X OAuth (`SocialAuthController`) is the same kind of login. Google uses `google_id`. It is not a YouTube scope.

Customer API routes sit behind `auth:sanctum`, `not_blocked`, and `customer`. `customer` rejects `is_admin` users so operators stay in the admin console.

After login, onboarding is shared across X, TikTok, and YouTube:

| Step | Endpoint | What it stores |
| --- | --- | --- |
| Which networks | `POST /api/account/setup/platforms` | `account_profiles.platforms`, and a trial line per chosen platform |
| Who the account is | `POST /api/account/setup/about` | purpose, niche, description, audience, tone |
| This network | `POST /api/account/setup/platforms/youtube` | goals plus YouTube settings, and `platform_setups.completed_at` |

Finishing any one platform sets `onboarding_completed`. Finishing YouTube also calls `YouTubeEngineService::run()` immediately. The hourly scheduler does the same for every eligible account.

YouTube’s own wizard steps (`OnboardingWizard`, chapter stages `goals`, `shape`, `publish`) are:

- **Goals**, one to three, from `SetupCatalog::GOALS['youtube']`: subscribers, watch time, search, traffic, promote the business, Partner Program.
- **Shape**: `video_mode` is `short`, `series`, or `footage`. If the mode is not footage, `format` is required: `faceless_voiceover`, `text_on_screen`, `scene`, or `list_facts`.
- **Publish**: `wait` (status `waiting`, the desk asks for a yes) or `auto` (status `queued` as soon as the package exists).

Those three fields land in `platform_setups.settings`. The catalog labels `short` as “YouTube Shorts”, but the writer does not emit a Short. It writes a longer spoken piece. Shorts are cut later from a transcript you paste, or from a local transcription of a rendered file when that provider is configured. See [Shorts](#shorts).

The desk is `GET /api/dashboard/platform/youtube`, rendered by `PlatformDesk` → `components/dashboard/youtube/YouTubeBody` at `/dashboard/youtube`. Typed request and payload contracts live in `apps/web/lib/youtube.ts`. If YouTube was not chosen, or setup is unfinished, the page only offers a link back into onboarding.

Sidebar for YouTube (`apps/web/lib/nav.ts`): Today (`/dashboard/youtube`), New video (`/content-studio`), Titles and scripts (`/drafts`), Ideas (`/trends`), What landed (`/analytics`), Settings. Ideas and What landed both load the YouTube desk payload; they never call X trends or X growth endpoints. Ideas shows the ranked local week and pasted-listing evidence. What landed shows learned-video summaries from results the user submitted.

Compose and Drafts remain ordinary text rows in `posts`. Their UI says plainly that a YouTube title, description, or community post does not create or modify a `youtube_videos` package.

---

## Who the engine will run for

`YouTubeEngineService::runAll()` (Artisan `youtube:run`, schedule name `youtube-engine`, hourly, `withoutOverlapping(15)` in `backend/routes/console.php`) loads users who are:

- not `is_blocked`
- `account_profiles.onboarding_completed = true`
- have a `platform_setups` row with `platform = youtube` and `completed_at` set

`run()` takes a cache lock `youtube-run-{userId}` for 30 seconds. A second call while the lock is held returns the latest video and does not write a new one.

`runUnlocked()` then stops, in order, when:

1. Setup is missing or not completed, or there is no account profile.
2. `video_mode` is not `short` or `series`. **Footage mode never auto-writes a video.** Footage only appears when someone uploads a file.
3. This user already has a video in this mode with status `waiting`, `queued`, or `awaiting_edit`. That open item is returned. One piece at a time.
4. The latest video in this mode has `learned_at = null`. The engine waits until results or a retention export mark it `learned`.
5. Mode is `series` and the latest episode was learned less than 6 days ago. The desk shows that date as `series.holds_until`.

If none of those hold, it writes the next short piece or the next episode.

`POST /api/youtube/run` is the same `run()` for the signed-in user, then returns the desk payload. The Today page does not call it. The first package is created when setup is saved, and later ones come from the hourly job or from saving results, a retention export, or pasted listings (each of those calls `run()` again).

---

## Research: where ideas come from

Ideas come from pasted fallbacks and explicit, cached YouTube Data reads. No research read runs on page load or from `youtube:run`.

- **`listings`**. Public videos someone pasted. The desk form is one line per video: `channel | title | views`. `POST /api/youtube/listings` accepts up to 200, each with `channel`, `title`, `views`, and optional `url` and `duration`. Saving them calls `run()`.
- **`topics`**. Questions pulled out of pasted comments. A question is kept only when it has been asked at least twice (the counter is the max of the counts seen).
- **`api_cache`**. Channel-video and bounded niche-search listings fetched only after a person presses refresh. They are merged with, never replace, pasted listings.

The read-only connection enables five explicit actions: channel videos (estimated cost 3), bounded niche search (101), comments (1), views (1), and retention (1). Successful pulls are reused for 15 minutes. The default app-side budget is 500 units per account per day (`YOUTUBE_READ_QUOTA_DAILY`); it is intentionally much smaller than a Google project quota so this feature cannot consume the project unchecked. A failed or over-budget refresh stores an error for the desk and keeps the last successful cache.

`YouTubeViralRanker::rank()` groups listings by channel. A channel with fewer than four videos is skipped and reported. For the rest it takes the median view count, keeps videos that beat that median (`multiple = views / median`), and labels the title with a hook formula. The formula is a read of the wording. The code says so in the note that goes back to the desk. It is not a claim about why the video worked.

`makeable()` keeps an outlier only when the title shares words with this account’s niche, description, and audience (two overlapping content words, or one word of six letters or more), and the title does not demand money, a studio team, or a hired crew that the description does not already mention. The idea text is those overlapping words, “said in a {tone} voice”.

The week list is at most three items, sorted by score:

- A makeable outlier scores `min(100, multiple * 25)`.
- A repeated comment question scores `min(100, count * 20)`.

`makePiece()` walks that week list and skips topics already used on any `youtube_videos.topic` for this user. If every topic was used, it falls back to the first item. An empty week writes nothing.

The desk’s top metrics are deterministic samples while no YouTube channel is connected. Once connected, subscribers come from the channel statistics endpoint and views/watch hours cover the last 28 days from YouTube Analytics. A failed connected read returns blank values plus an error reason; it never falls back to samples.

---

## What a script package is

`YouTubeScriptWriter` always builds a local package first (`YouTubePackageWriter::compose`). It then asks the model. If the model returns spoken text that passes the checks below, **only `spoken` is replaced**. Titles, hooks, thumbnail, chapters, and description stay on the local package.

The model is skipped, and the local package is stored, when:

- `openai.api_key` is empty
- the user is over the AI generation allowance (`AiUsageService`)
- the API spend reserve cannot be taken (`ApiSpendService::SCRIPT_RESERVE_MILLS`)
- the HTTP call throws, or the body is not JSON

A successful call is recorded as feature `youtube_script`. Temperature `0.7`, `max_tokens` 1800, JSON mode, model from platform settings.

Spoken text from the model is kept only when it:

- contains `[ON SCREEN:`
- does not say “before we get started”, “welcome back”, or “you do not need the expensive version”
- does not say “subscribe” in the first 80 words
- contains “this video is delivering” or “that is the”
- introduces no digit that was not already in the niche, description, audience, topic, or reason

The local package always includes:

- five titles, each linted and scored, winner first, stored title cut to 60 characters on a word boundary
- five candidate hooks, scored, top two kept
- spoken script with `[ON SCREEN: ...]` beats
- runtime at 150 words per minute
- description, queries, tags, chapters, thumbnail (at most three words), thumbnail brief, shots, payoff, ask
- `gate`: “Ship it, or change it?”

`search` in the YouTube goals changes the description: the niche, audience, and description are treated as the queries that should appear in the title and the first lines. Without that goal, the package is marked `search_facing: false` and the desk says the piece is for people who already watch.

Title rules (`YouTubeTitleLinter`): desktop cut near 60 characters, mobile feed near 40, hard ceiling 100, at most two all-caps words, vague adjectives are flagged, the thumbnail must use different words and at most three of them, and a name the account already uses outranks an adjective. A figure the account does not have is left out.

Hook score (`YouTubeHookScorer`): five properties (specificity, address, stakes, curiosity, brevity). The verdict is 60% of the mean and 40% of the weakest. Bands are STRONG at 72 or above, WORKABLE at 55, otherwise WEAK. The formula name comes from regexes in `backend/app/Services/YouTube/data/hooks.json` (21 formulas: The Statistic, Someone Else's Result, The Mistake, Contrarian Flip, The Reveal, and the rest in that file).

Chapters (`YouTubeChapterWriter`) must start at 0:00, have at least three entries, and give each chapter at least 10 seconds. Titles are three to five words. `chapters_valid` records whether that bar was met.

Shots depend on format. `text_on_screen` is marked render kind `text`. Series episodes are `narration`. Anything else is `clips`. In every case `package.render` starts as `{ provider: "pending", ready: false }` plus a note that the file is made locally when you ask. Nothing is uploaded. See [Local render](#local-render).

Publish note on the row says the upload waits for the video file and the YouTube connection, whether the status is `waiting` or `queued`. A local preview does not change that note or the status. `queued` still means approved, not sent.

---

## Statuses

| Status | Meaning |
| --- | --- |
| `waiting` | Publish mode was `wait`. The desk shows **Ship it**. |
| `queued` | Publish mode was `auto`, or the user confirmed a waiting video. Approved on this server. Not posted. |
| `awaiting_edit` | Footage was uploaded and an edit plan was written. Making the local file applies the plan and leaves this status in place. |
| `posted` | A resumable `videos.insert` returned a real YouTube video id and the privacy YouTube applied. Both are stored. |
| `learned` | Views were stored, or a Studio retention CSV was stored. The next `run()` may write another piece, subject to the series gap. |

`YouTubeCatalog::statusLabel()` is what the badge shows. `queued` is labeled “Approved, not posted”. Unknown statuses render as “In progress”.

Confirm (`POST /api/youtube/videos/{id}/confirm`) only works from `waiting`. It sets `queued`, `queued_at`, and rewrites `package.publish` to mode `auto`. It does not call Google and it does not set `posted`. **Ship it** is that confirm. It approves the package. It does not upload or post.

A failed send leaves the row `queued` (or `waiting`, if it was never approved), with `package.upload` saying why and whether another send is allowed. `posted` is set only in the same update as `posted_video_id` and `posted_privacy`. A failed or missing ffmpeg render leaves `provider` as `pending` and `ready` false, with a plain reason, and does not change `waiting`, `queued`, or `awaiting_edit`. `youtube:run` does not render and does not insert.

---

## Series

The first series run with no active `youtube_series` row calls `YouTubeSeriesPlanner::plan()`. The premise is the account description, or the niche if the description is empty. Characters are “The channel” and the audience. The outline is six episodes derived from clauses in that premise (split on “and” and commas). The planner does not call the model, and it does not reuse one generic six-beat template across niches: the episode titles are slices of this premise.

Each engine run scripts `outline[next_episode - 1]`, then increments `next_episode`. If the outline runs out, it appends a beat that says to stay inside the premise. Format falls back to `faceless_voiceover` when the saved format is not in the catalog. Episode scripts go through the same writer, with the episode number, title, beat, and premise attached. The characters array is passed in and then ignored.

A new episode is blocked for six days after `learned_at` on the latest series video.

---

## Footage

`POST /api/youtube/footage` with `footage` (mp4, mov, or webm, max 102400 KB) and an optional `note` (max 2000 characters).

Refused when setup is unfinished, when `video_mode` is not `footage`, or when five footage rows are already `awaiting_edit`.

The file is stored on the `local` disk at `youtube/{userId}/...`. `YouTubeEditPlanner::plan()` writes the plan from the filename and the note: a title, a description, caption lines split on sentences (up to 12, three seconds apart), a caption style, three zoom points, a color preset, loudness `-14` LUFS, and a silence trim over 0.6 seconds. `render.provider` stays `pending` until `POST /api/youtube/videos/{id}/render` applies that plan with ffmpeg. Word timings come from a pasted transcript or from local transcription after the file exists. Caption text is written into a caption file, not into an ffmpeg argument.

The row’s `script` is the note. Status stays `awaiting_edit` after a successful or failed render. This path does not call the script writer and does not enter the hourly loop.

The desk hides the edit block until a transcript exists (`toDesk()` drops `edit` and `shorts` when `package.transcript` is empty, and exposes `has_transcript` instead).

---

## Local render

`POST /api/youtube/videos/{id}/render` is explicit. Page loads, `youtube:run`, and listing saves do not render. The route is allowed for `waiting`, `queued`, and `awaiting_edit`. It refuses any other status. It never changes that status and never calls `videos.insert`.

`YouTubeFfmpegRenderer` uses `FfmpegRunner` (the same process caller as TikTok) and no Remotion or cloud renderer.

- `text_on_screen` burns the on-screen beats onto a 1920×1080 frame, with silent audio.
- Faceless voiceover and series narration time a local tone to the spoken script, or call the shared speech provider when `YOUTUBE_TTS_DRIVER=openai`. The spoken sentences are the captions. `[ON SCREEN:]` markers are not spoken.
- `scene` and `list_facts` turn each shot prompt and its seconds into a card. Empty shots fall back to the on-screen beats.
- Footage applies the saved edit plan: silence longer than `trim_over_seconds` (0.6), the color values, the three zooms, loudness `-14` LUFS, and the caption lines.

`package.render.ready` becomes true, and `provider` becomes `ffmpeg`, only after ffprobe accepts a non-empty mp4/mov/webm with a video stream, duration, and dimensions. The file is then stored on the private `youtube_media` disk (`youtube/{userId}/{videoId}/render.mp4`, plus a preview and a poster). `GET /api/youtube/videos/{id}/media` returns a short-lived preview and download URL. The response says the file is local.

If ffmpeg or ffprobe is missing, a filter is missing, or the file fails those checks, `provider` stays `pending`, `ready` stays false, and `note` says what to do. No path is kept.

`YOUTUBE_TRANSCRIPTION_DRIVER=none` (the default) does not invent a transcript. Paste still works. `openai` sends the local file to Whisper (`/v1/audio/transcriptions`) when `OPENAI_API_KEY` is set, then `storeTranscript()` replaces `package.shorts` through `YouTubeShortsCutter`. A transcription failure leaves the file ready and tells you to paste. Rendering does not call the YouTube Caption API. A later paste replaces the transcript and cuts Shorts again. After a real posted video id exists, `captions.list` can refresh that transcript. See [Upload](#upload).

---

## Shorts

`YouTubeShortsCutter` does not write a Short from the niche. `POST /api/youtube/videos/{id}/transcript` stores the text on `package.transcript` and replaces `package.shorts` with cuts. A successful local transcription uses that same method, so a new transcript regenerates the cuts. Paste remains available either way. A caption track downloaded after a posted video id uses the same method. If `captions.list` or the track download fails, the pasted or local transcript stays.

The transcript is either timestamped lines or SRT blocks. A cut is kept when:

- it opens on a clean line (the previous cue ended on `.` `?` or `!`, or this is the start)
- it lasts between 20 and 55 seconds
- it ends on sentence punctuation
- the span contains a turn word: but, except, actually, instead, wrong, until, however

Overlapping spans are dropped. At most five cuts are kept, longest first. Each cut gets a scored opening line, on-screen words, a loop note, and a crop note. Fewer than three cues returns an empty list.

---

## Comments

`POST /api/youtube/videos/{id}/comments` accepts a paste of at most 20,000 characters, one comment per line. `POST /api/youtube/videos/{id}/refresh-comments` fetches top-level comments for a supplied published YouTube video ID. Both pass the same text through `YouTubeCommentTriage`, which sorts each line:

- **bait** if it matches insult or dismissal patterns (ratio, cope, clown, idiot, and similar). No reply.
- **questions** if it contains `?` or starts with how, why, what, when, where, who, can, should, do, does, is, are
- **corrections** if it looks like a factual pushback
- **praise** if it matches love, great, helpful, thanks, and similar. At most two praise replies.
- **aside** otherwise. No reply.

Replies are template sentences in the channel voice. They are not a model call. A question that asks for a video the script has not already covered is not promised. Questions that repeat (count at least 2) are written onto `youtube_research.topics` and can show up in the next week list as “The Question”.

The package stores counts, replies, and one pin (the first question, when there is one).

---

## Learning

Two inputs, and they do different jobs.

**Views** (`POST /api/youtube/videos/{id}/results`, or explicit `refresh-views`): the paste path accepts `views` and `average_view_percent` from `waiting`, `queued`, `posted`, or `learned`. The API path reads the same values from YouTube Analytics for a supplied published video ID. Both use `recordResults`, set status `learned`, and write `package.model_learning.case = unshaped` with `change_opening: false`. Average view percent does not choose the next opening. A posted video id is kept when results are saved.

**Retention** (`POST /api/youtube/videos/{id}/retention`, or explicit `refresh-retention`): the paste path accepts a Studio CSV; the API path reads `elapsedVideoTimeRatio`/`audienceWatchRatio` from YouTube Analytics. Both feed `YouTubeRetentionReader`, which needs at least eight points and names one case:

| Case | When | Lesson passed into the next script |
| --- | --- | --- |
| `hook_leak` | At least 25% lost inside the first 30 seconds (or the first 10% of a 0–100 axis when duration is missing) | `change_opening` is true. Next script is told to rewrite the first fifteen seconds. |
| `cliff` | A single drop of at least 8 points, and the opening did not leak | Keep the opening. Cut the moment people left. If the video has cue timings, the note includes the line that was being said. |
| `slide` | Drops after the opening add up to at least 15, and there is no cliff | Keep the opening. Cut the middle. |
| `packaging` | Opening held and the middle is flat | Keep the opening. Change the title and thumbnail. |
| `unreadable` | Fewer than eight points | No lesson. |

`lessonFor()` reads `package.model_learning` on the previous video of the same mode. `learning_note` on the row is set to null by both result paths. The lesson the writer sees is the case above, not that column. `recordResults` therefore clears any previous retention lesson, because it overwrites `model_learning` with `unshaped`.

Saving either kind of result calls `run()` again, so the next piece can be written in the same request if the guards allow it.

---

## The weekly audit

`YouTubeAudit::audit()` looks at the latest videos (up to ten) and returns one `fix`, a few `working` lines, and a `not_yet`. It does not use retention numbers.

Priority:

1. Three or more recent thumbnails fail the title/thumbnail linter (too many words, “Episode N”, or the thumbnail repeats the title) → rewrite the three thumbnail words. Do not rework the series outline.
2. Two of the last three openings score under 55 → rewrite the first fifteen seconds.
3. Uploads are bunched (a gap of 2 days or less and a gap of 21 days or more) → post once and keep the gap.
4. Four or more recent titles share the same first two content words → change the shape of the next title.
5. Otherwise → post the piece in front of you and leave a gap.

Empty history says to collect a public listing before inventing a weekly theme, and not to write a Short from a niche template.

---

## Upload

`POST /api/youtube/videos/{id}/upload` is explicit. Page loads, confirm, render, and `youtube:run` do not insert. The route is behind the same `auth:sanctum`, `not_blocked`, and `customer` middleware, and it 404s for a video owned by someone else.

The send is refused, with no Google insert, unless all of these are true:

- Status is `queued`. **Ship it** only moves `waiting` to `queued`.
- `package.render.ready` is true and the file exists on the `youtube_media` disk. The extension must be mp4, mov, or webm.
- The user has an active YouTube connection whose stored scopes include `https://www.googleapis.com/auth/youtube.upload`.
- The YouTube billing line has remaining monthly allowance.
- The shared UTC-day insert budget has room. The default is 100 attempts for the whole project.

`YOUTUBE_UPLOAD_AUDIT_APPROVED` defaults to false. While it is false the server never requests `public` or `unlisted`. A private send starts a resumable `videos.insert` and tells the desk the insert is private. A send that asks for public or unlisted is held on this server, the row stays `queued`, and the note says public and unlisted are not requested. If YouTube answers with public or unlisted while the audit is off, the row is not marked `posted` and the returned visibility is not claimed. When the audit flag is true, the request may ask for private, unlisted, or public, and `posted_privacy` is whatever YouTube returned.

A spent daily budget does not call Google. The row stays `queued`, `package.upload.retryable` stays true, and `next_attempt_on` is the next UTC date. A spent monthly allowance is a different message and does not consume the daily budget. An insert that fails, or that returns no video id or no privacy, stays unsent and can be sent again. Each attempt that reaches Google reserves one daily slot in `youtube_insert_attempts`, including a failed attempt.

Success writes `status = posted`, `posted_at`, `posted_video_id`, and `posted_privacy` together. The id must match `^[A-Za-z0-9_-]{6,20}$`. The privacy must be one of `private`, `unlisted`, or `public`, and must be private while the audit is off. The desk shows that id and the visibility YouTube applied. It is not the research id used for comments, views, and retention reads.

The upload uses the resumable protocol: a session `POST` to `https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status`, then one or more `PUT`s with `Content-Range`. A `308` continues from the byte YouTube reports. The session URL must be https, and outside tests it must be a `googleapis.com` or `googleusercontent.com` host.

After that id and privacy are stored, the server may call `captions.list` for that id and download one track. A transcript that comes back replaces `package.transcript` and regenerates Shorts through `YouTubeShortsCutter`. A failed list, an empty list, or a failed download leaves the pasted or local transcript in place. `captions.list` is not called before a video id exists, and it is not called when the insert did not post.

The second consent is `POST /api/connected-accounts/youtube/upload-consent`. It requires the read-only channel connection first. Its authorize URL requests only `youtube.upload`, with `include_granted_scopes=true`, so Google can keep the read-only grant. `YOUTUBE_SCOPES` is still filtered to the two read-only scopes on the first connect. The callback stores the upload scope only when the token response includes it, and it merges that scope onto the existing channel row. A denied or failed upload consent leaves the read-only tokens and scopes unchanged.

The desk payload includes `upload`: audit flag, scope (connected, upload granted, needs the second consent), monthly allowance, shared daily budget, and the privacy policy sentence. Each video’s `package.upload` carries the last send state, the note, whether it can be tried again, and, only after a real post, the YouTube id and applied privacy. `toDesk()` also exposes `posted_video_id`, `posted_privacy`, and `posted_at`.

## HTTP API

All customer routes are `auth:sanctum` + `not_blocked` + `customer`. The OAuth callback is public and validated by a single-use cached state. Video routes 404 when the video belongs to someone else.

| Method | Path | Effect |
| --- | --- | --- |
| GET | `/api/dashboard/platform/youtube` | Desk: setup, preview metrics, ideas, week, niche research, audit, pipeline (latest 8 videos), series |
| POST | `/api/youtube/run` | `run()` then the same desk |
| POST | `/api/youtube/listings` | Store pasted public videos, then `run()` |
| POST | `/api/youtube/research/channel-videos` | Explicitly refresh cached connected-channel listings |
| POST | `/api/youtube/research/niche-search` | Explicitly run a bounded, cached niche search |
| POST | `/api/youtube/footage` | Upload and write an edit plan |
| POST | `/api/youtube/videos/{video}/confirm` | `waiting` → `queued`. Does not post |
| POST | `/api/youtube/videos/{video}/results` | Store views and average view percent, mark `learned`, `run()` |
| POST | `/api/youtube/videos/{video}/transcript` | Store transcript and cut Shorts |
| POST | `/api/youtube/videos/{video}/render` | Make a local file with ffmpeg. Does not change status and does not upload |
| POST | `/api/youtube/videos/{video}/upload` | Resumable `videos.insert` when the file, scope, allowance, and daily budget allow it |
| GET | `/api/youtube/videos/{video}/media` | Signed preview, poster, and download for a ready local file |
| POST | `/api/youtube/videos/{video}/retention` | Read a retention CSV, mark `learned`, `run()` |
| POST | `/api/youtube/videos/{video}/comments` | Triage comments and store repeated questions |
| POST | `/api/youtube/videos/{video}/refresh-comments` | Fetch cached comments, then use the same triage path |
| POST | `/api/youtube/videos/{video}/refresh-views` | Fetch cached views/AVP, then use `recordResults` |
| POST | `/api/youtube/videos/{video}/refresh-retention` | Fetch cached retention, then use `YouTubeRetentionReader` |
| POST | `/api/account/setup/platforms/youtube` | Save goals, mode, format, publish, then `run()` |
| POST | `/api/content/generate` | Text only, when `content_type` is `youtube_title`, `youtube_description`, or `youtube_community` |
| POST | `/api/content/drafts` | Save that text as a `posts` draft |
| POST | `/api/connected-accounts/youtube/connect` | Start read-only channel OAuth |
| POST | `/api/connected-accounts/youtube/upload-consent` | Second consent for `youtube.upload` only |
| GET | `/api/auth/youtube/callback` | Absolute OAuth callback; returns to Settings |
| GET | `/api/connected-accounts/youtube/status` | Connection identity and read-only metrics |
| DELETE | `/api/connected-accounts/youtube` | Best-effort revoke, then unconditional local deletion |

`toDesk()` strips `transcript` and `model_learning` from the JSON the browser sees. The client gets `has_transcript` and, only when a transcript exists, `shorts` and `edit`. The top-level desk payload also has `learned`, a limited summary containing only id, mode, title, submitted views, average view percent, learned time, and the public retention case. It never includes the internal package object. A series card exposes only `has_retention_lesson`, not the lesson package.

---

## Tables

`youtube_series`: `user_id`, `title`, `premise`, `characters` (json), `outline` (json), `next_episode`, `status` (`active`).

`youtube_videos`: `user_id`, optional `youtube_series_id`, `mode`, `format`, `status`, `topic`, `title`, `script`, `description`, `package` (json), `score`, `episode_number`, `source_path`, `source_name`, `views`, `impressions` (column exists, nothing writes it), `average_view_percent`, `learning_note` (written as null by the current result paths), `learned_at`, `queued_at`, `posted_at`, `posted_video_id`, `posted_privacy`. Index on `(user_id, mode, status)`. `posted_video_id` and `posted_privacy` are written only when status becomes `posted`.

`youtube_insert_attempts`: `user_id`, local `youtube_video_id`, `reserved_on` (UTC date). One row per insert attempt that was about to call Google. The daily budget counts every user.

`youtube_research`: unique `user_id`, pasted `listings` (json), `topics` (json), `api_cache` (json), `quota_date`, and `quota_used`.

Migrations: `2026_10_24_200000_create_youtube_videos_tables.php`, `2026_10_27_140000_create_youtube_research_table.php`, `2026_11_05_140000_add_youtube_upload_columns.php`.

---

## Code map

| Path | Role |
| --- | --- |
| `backend/app/Services/YouTube/YouTubeEngineService.php` | Run loop, confirm, results, footage, desk |
| `backend/app/Services/YouTube/YouTubeDemandService.php` | Week list from listings and comment questions |
| `backend/app/Services/YouTube/YouTubeViralRanker.php` | Median outliers and “can this channel make it” |
| `backend/app/Services/YouTube/YouTubeScriptWriter.php` | Model call, local fallback, spoken overlay |
| `backend/app/Services/YouTube/YouTubePackageWriter.php` | Local titles, hooks, script, chapters, thumbnail |
| `backend/app/Services/YouTube/YouTubeTitleLinter.php` | Title and thumbnail rules |
| `backend/app/Services/YouTube/YouTubeHookScorer.php` | Five-property score and formula name |
| `backend/app/Services/YouTube/data/hooks.json` | The 21 formula patterns |
| `backend/app/Services/YouTube/YouTubeChapterWriter.php` | Chapter timings |
| `backend/app/Services/YouTube/YouTubeWords.php` | Tokens, digits, cuts, emoji |
| `backend/app/Services/YouTube/YouTubeSeriesPlanner.php` | Six-episode outline from the description |
| `backend/app/Services/YouTube/YouTubeEditPlanner.php` | Footage edit plan |
| `backend/app/Services/YouTube/YouTubeFfmpegRenderer.php` | Local ffmpeg cards and footage edits |
| `backend/app/Services/YouTube/YouTubeRenderService.php` | Stores a validated file and keeps the row status |
| `backend/app/Services/YouTube/YouTubeMediaInspector.php` | ffprobe acceptance before `ready` |
| `backend/app/Http/Controllers/Api/YouTubeMediaController.php` | Render, preview, and download |
| `backend/app/Services/YouTube/YouTubeShortsCutter.php` | 20–55s cuts from a transcript |
| `backend/app/Services/YouTube/YouTubeRetentionReader.php` | Hook leak, cliff, slide, packaging |
| `backend/app/Services/YouTube/YouTubeCommentTriage.php` | Sort pasted comments and draft replies |
| `backend/app/Services/YouTube/YouTubeAudit.php` | One fix for the week |
| `backend/app/Support/YouTubeCatalog.php` | Modes, formats, status labels |
| `backend/app/Http/Controllers/Api/YouTubeController.php` | The routes above |
| `backend/app/Services/YouTube/YouTubeOAuthService.php` | Read-only OAuth state, tokens, refresh, metrics, disconnect |
| `backend/app/Services/YouTube/YouTubeUploadConsentService.php` | Second consent that requests only `youtube.upload` |
| `backend/app/Services/YouTube/YouTubeUploadService.php` | Gates, resumable insert, posted state, caption refresh |
| `backend/app/Services/YouTube/YouTubeUploadClient.php` | Fakeable resumable `videos.insert` and `captions.list` |
| `backend/app/Services/YouTube/YouTubePrivacy.php` | Private insert or local hold while the audit is off |
| `backend/app/Services/YouTube/YouTubeAllowanceService.php` | Monthly posted-video allowance from the billing line |
| `backend/app/Services/YouTube/YouTubeInsertBudget.php` | Shared project insert budget, default 100 per UTC day |
| `backend/app/Services/YouTube/YouTubeChannelClient.php` | Fakeable Google token, channel, Analytics, and revoke HTTP client |
| `backend/app/Services/YouTube/YouTubeDataClient.php` | Fakeable channel videos, search, and comments reads |
| `backend/app/Services/YouTube/YouTubeAnalyticsClient.php` | Fakeable per-video views and retention reads |
| `backend/app/Services/YouTube/YouTubeResearchRefreshService.php` | Explicit cache, quota, failure retention, and integration orchestration |
| `backend/app/Http/Controllers/Api/DashboardController.php` | Merges the engine desk into the platform payload |
| `apps/web/components/dashboard/PlatformDesk.tsx` | Shared platform shell |
| `apps/web/components/dashboard/youtube/YouTubeBody.tsx` | YouTube Today desk and submissions |
| `apps/web/components/dashboard/youtube/YouTubeRenderPanel.tsx` | Local render status, preview, and download |
| `apps/web/components/dashboard/youtube/YouTubeIdeas.tsx` | YouTube-only Ideas route |
| `apps/web/components/dashboard/youtube/YouTubeLanded.tsx` | YouTube-only learned results route |
| `apps/web/lib/youtube.ts` | Typed YouTube desk client and contracts |
| `apps/web/components/onboarding/OnboardingWizard.tsx` | Goals, shape, publish |
| `apps/web/lib/profile.ts` | Goal and format labels shared with the API |
| `backend/tests/Feature/YouTubeEngineTest.php` | Engine, setup trigger, confirm, listings. Confirm stays queued |
| `backend/tests/Feature/YouTubeUploadTest.php` | Consent, resumable insert, audit privacy, both budgets, retry, captions |
| `backend/tests/Unit/YouTubeCraftTest.php` | Ranker, linter, hooks, chapters, retention, shorts, comments, audit privacy, billing allowances |

`docs/SYSTEM.md` has the shorter cross-platform summary. This file is the detailed contract: ideas may come from pasted listings, cached explicit API reads, and repeated comment questions; only retention creates an opening lesson.

---

## What is still unfinished on this machine

Phases 1–5 are in the tree. This verification did not produce a public video, a real upload, or a finished encode.

- `ffmpeg` and `ffprobe` are not on PATH. No file was encoded here. A missing binary leaves `render.provider` as `pending` and `ready` false. `TikTokFfmpegRenderTest` skipped for the same reason.
- Every Google response in the tests is faked. There was no live OAuth, Data API, Analytics, or `videos.insert` call. `YOUTUBE_UPLOAD_AUDIT_APPROVED` still defaults to false, so the server does not request or claim `public` or `unlisted`.
- Today, Ideas, What landed, Settings, and Compose were read from the page source. They were not clicked through in a browser.

Route isolation from that reading:

- Today loads `GET /api/dashboard/platform/{platform}` only. The YouTube branch renders `YouTubeBody` and does not call `GET /api/trends` or `GET /api/analytics/growth`.
- Ideas (`/trends`) returns `YouTubeIdeas` or `TikTokIdeas` before the X topic list. YouTube loads the desk, and research refresh is a button. The X branch still loads `GET /api/dashboard/platform/x`.
- What landed (`/analytics`) returns `YouTubeLanded` or `TikTokLanded` before the X chart. `GET /api/analytics/growth` stays in that X chart only.
- Settings still loads the account, the X connection, and safety for every platform. The X automation card and the TikTok video-settings card stay on their own platforms. The YouTube card calls `GET /api/connected-accounts/youtube/status`, on the same pattern as the TikTok card.
- Compose posts `POST /api/content/generate` and `POST /api/content/drafts`. Those write `posts` rows (`youtube_title`, `youtube_description`, `youtube_community`). They do not create `youtube_videos`. Suggest and search can still use the shared trend helper inside content generation. That is not `GET /api/trends`, and it does not update the video engine.

`impressions` and `learning_note` are still unused by the current write paths. Desk metrics stay seeded samples until a channel is connected. A connected metrics failure still returns blanks plus a reason, not samples.

`npm run lint` in `apps/web` is not a usable check. The repo has no ESLint config, and that script opens an interactive setup. No ESLint config was added.

## What was run for this phase

Google HTTP was faked. ffmpeg is not on PATH, and no real encode was required. No web files were edited, so `npx tsc --noEmit` and `npm run build` were not run again.

PHPUnit, with `APP_KEY` set to the documented test key and the sqlite extensions loaded, full backend suite: 264 tests, 2189 assertions, 4 failures, 1 skipped. The YouTube suites passed: `YouTubeEngineTest`, `YouTubeCraftTest`, `YouTubeUploadTest`, `YouTubeOAuthTest`, `YouTubeResearchRefreshTest`, `YouTubeRenderTest`, `YouTubeRenderPlanTest`, and `YouTubeChannelClientTest`.

The four failures are outside those suites, and the YouTube phases did not edit the files they assert:

- `BillingTest` expects a mixed X Starter + TikTok Pro quote of 10620 cents and Paddle amounts 2720 and 7900. X Starter in `config/billing.php` is 2700 cents, so the same cart totals 10195. That config file was not part of this work.
- `PlanChangeTest` expects a yearly price of 29000 cents to be rejected. The X Starter yearly floor is the monthly minimum times 12, which is 26400, so 29000 is accepted.
- `TikTokVideoUploadTest` passes alone. After the retry test in the same class, the next test cannot write `1/1/source.mp4` (`UnableToWriteFile`). The upload path leaves that file handle open. `TikTokDraftService` was not edited by these phases.
