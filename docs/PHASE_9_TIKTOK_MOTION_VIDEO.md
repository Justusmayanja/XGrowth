# Phase 9: TikTok motion-graphics video generation, server storage, render-ahead

You are a senior Laravel + Next.js engineer with video-pipeline experience, working in the Mackie17 repo (Laravel app "Xpulse", Next.js in `apps/web`). Phases 1-8 are merged: clip engine, confirm/results API, allowance, desk, OAuth linking, user video upload (chunked, ffprobe-validated), draft sending (`SendTikTokDraft`), dashboard shell.

**Goal:** when the hourly engine writes a clip, the system automatically renders a finished vertical motion-graphics video (narrated, captioned, branded if the user allows), stores it, and shows it on the desk for preview. The user logs in, watches it, and taps the existing confirm. Confirm remains the only consent. Nothing is sent to TikTok before it, and nothing is ever posted publicly.

**Source of truth is the code, not the docs.** `docs/TIKTOK_PLAN.md` and `docs/SYSTEM.md` are behind. Read the code first.

## 0. Scope

In: private media on the server disk, local working-directory pattern, queue worker and render jobs, scene building, TTS, captions, ffmpeg motion-graphics provider, preview/poster/signed URLs, video preferences and brand/profile opt-ins, desk UI for render states, render-ahead scheduling, sweepers, ops visibility, docs.

Out (do not build): cartoon, avatar, stock providers (show them as "Coming soon" only); Direct Post; any new TikTok scope; changes to X or YouTube; word-level caption alignment; music sourcing.

Work in the milestones in section 3, in order. Each milestone must meet its exit criteria and have passing tests before the next starts. If you cannot finish everything, **stop at a clean milestone boundary** and report.

## 1. Before you write code

1. Read: `TikTokEngineService`, `TikTokScriptWriter`, `TikTokCatalog`, `TikTokDraftService::dispatchIfReady`, `SendTikTokDraft`, the video upload controller/service (chunk assembly, ffprobe validation, `tiktok_clip_videos` model and migration), the sweep commands, `ApiSpendService`, `AiUsageService`, `AccountSetupController` (TikTok step), `TikTokClipResource`, the dashboard `tiktok` payload, and the desk components in `components/dashboard/tiktok/`.
2. Check the machine: `ffmpeg -version`, `ffmpeg -filters | grep -E "ass|subtitles|xfade|overlay|color"`, `ffmpeg -encoders | grep -E "libx264|aac"`, and whether libass and libfreetype are compiled in. Record the version. If something required is missing, stop and tell me what to install.
3. Find out how `platform_setups.settings` is written in the TikTok setup step. **If it replaces the array, it will wipe `video` preferences. Change it to merge.** Add a test.
4. Find the existing tests that assert `render.ready` has a single writer. This phase adds a second legitimate writer (section 3, M4). Update those tests on purpose, do not delete them.
5. Check whether the app has any language/locale setting for accounts, and how the script prompt chooses the language.
6. Read the queue config: connection, retry_after, failed jobs table. Confirm `redis` and `predis` work locally.
7. Never run `migrate:fresh` or destructive commands on the dev DB. Do not create users.

## 2. Hard boundaries

- Confirm semantics are unchanged: it queues, spends the allowance, and dispatches the draft send only if the file and scope are ready. Rendering never consumes allowance, never calls TikTok, never changes clip status.
- Never set `render.ready = true` except through the one method defined in M4, and only after the file is stored, ffprobe-validated, and a `tiktok_clip_videos` row exists.
- Do not touch `posts`, `rate_limit_budgets`, X, YouTube, OAuth, or scopes.
- No shell strings. Run ffmpeg/ffprobe with Symfony `Process` and an **argument array**. User text never goes into a filter string or command line. It goes into generated files (ASS, text) after sanitising.
- Never log or return storage keys, signed URLs, TTS payloads, or account text.
- The dashboard GET stays side-effect free. It must never start a render.
- No new tables. Reversible migrations only. Files stay on the server disk.
- No fabricated licensing claims, no downloading music, fonts, or images from the internet. Use only fonts with an open licence that you can verify (for example Inter under OFL) and include the licence file.
- Keep every honesty-critical string already in the app. Never write "Posted", "Scheduled", or "Live" for anything that is not. If a required copy change conflicts, stop and ask.
- If the repo contradicts this brief, stop and ask.

## 3. Milestones

### M1. Server storage and the working-directory pattern

**Config**
- `.env.example` and `config/filesystems.php`: keep the disk name `tiktok_videos`. It is a prefix on the private `media` disk, whose root is `storage/app/private/media` on this server.
- Object layout: `{user_id}/{clip_id}/source.mp4` (final, 1080x1920), `preview.mp4`, `poster.jpg`; `{user_id}/brand/logo.{ext}`. Server-generated names only.

**Local working directory**
- `TikTokWorkspace`: `begin(clipId): string` creates `storage/app/tiktok-work/{uuid}/`, `localCopy(TikTokClipVideo): string` downloads an object to a temp file, `cleanup()` always runs in `finally`. A sweeper deletes work dirs older than 6 hours.
- Intermediates (scene clips, audio, ASS files, concat lists) stay local and are deleted when the job ends.

**Adapt existing code**
- Chunked browser upload: assemble in a **local staging dir**, run ffprobe there, then stream to the disk with `writeStream`, then delete staging. Only validated files are stored.
- `SendTikTokDraft`: download via `localCopy` (100 MB cap makes this fine), chunk from the temp file, clean up in `finally`.
- Discard, replace, the 7-day sweep, and the orphan sweep must work on the `s3` driver. Orphan listing is per-user-prefix, never a whole-bucket scan.
- `tiktok:migrate-videos --from=local --to=media [--dry-run]`: copies existing objects, verifies checksum against `tiktok_clip_videos.checksum`, updates nothing until verified, and never deletes the source automatically.
- `tiktok:storage-check`: writes a canary object, reads it, deletes it, prints latency per step. Exits non-zero on failure.

**Exit:** with `TIKTOK_VIDEO_DRIVER=local`, the whole existing suite is green. With a faked S3 disk (Flysystem in-memory or `Storage::fake`), upload, send, discard and sweeps pass. `tiktok:storage-check` runs against local and reports.

### M2. Queue worker, render state, and the provider contract

**Schema (one reversible migration)**
- `tiktok_clip_videos`: add nullable `preview_path`, `poster_path`, `preview_bytes` (unsignedBigInteger). Confirm `source` accepts `generated` (string, not an enum).
- No other tables or columns.

**Render state lives in `package.render`** (clip status machine untouched):

```
{ provider, ready, status: none|queued|rendering|ready|failed|skipped,
  note, attempts, regenerations, queued_at, started_at, finished_at,
  ai_generated: true|false }
```

- Defaults still come from `TikTokCatalog::defaultRender()`. Extend it additively. Add status/error constants and plain-words `renderLabel()` / `renderNote()` helpers to the catalog.
- `TikTokRenderState` class owns transitions (`queue`, `start`, `fail(reason)`, `succeed`) under a row lock. Illegal transitions throw.

**Contract**
- `interface VideoProvider { key(); supports(RenderSpec); render(RenderJob): RenderResult }`. `RenderResult` is either a local file set (`video`, `preview`, `poster`) or a typed failure with a user-safe reason.
- `VideoProviderRegistry`: `motion` is available. `cartoon`, `avatar`, `stock` exist in the catalog as `available=false` ("Coming soon") and are never selectable server-side.
- `TikTokRenderService::dispatch(TikTokClip $clip, bool $force=false)`: guards, then queues. `RenderClipJob` (queue `renders`, `ShouldBeUnique` by clip id, `uniqueFor` 15 min, `timeout` 600, `tries` 2, backoff, `afterCommit`) calls the orchestrator.
- Guards (all must hold; otherwise state `skipped` with a reason, nothing queued): clip is `waiting` or `queued`, no user-provided video exists, `video_type` is `motion`, preferences allow it, user not blocked, voice language supported, spend can be reserved.
- Locking: row-lock the clip when starting. At **store time**, re-lock and re-check: clip not discarded and no user video attached since the job began. If violated, delete the render output and exit quietly.
- `docs/QUEUE_WORKER.md` plus a supervisor config sample: `php artisan queue:work redis --queue=renders,default --max-time=3600 --tries=2`, 1 to 2 processes, `nice`, restart on deploy (`queue:restart`).
- `tiktok:sweep-renders` (every 10 minutes, `withoutOverlapping`, in-process like the other schedule entries): `queued` over 30 minutes or `rendering` over 15 minutes become `failed` with the note "Our video maker is busy. Try again." Also cleans old work dirs.
- Admin Operations (existing TikTok section): add render counts by status, oldest queued age, and a "queue worker seems down" warning when queued jobs age past 10 minutes.

**Exit:** state machine tests, guard tests, uniqueness test (two dispatches, one job), a faked provider that succeeds and one that fails, sweeper test.

### M3. Preferences and brand inputs

Stored in `platform_setups.settings.video` for TikTok. **Merge, never overwrite.**

```
video: {
  type: "motion",
  voice: { style: "female|male", language: "en" },
  captions: { enabled: true, style: "bold|clean" },
  length: "short|standard",          // about 20s / about 35s
  music: { enabled: false },
  generate: "ahead|on_confirm",      // default ahead
  brand: { use_logo, use_colors, primary, secondary, font: "inter|<one more OFL font>", outro_text },
  context: { mention_location: false, location_text, show_contact: false, business_name, website, phone }
}
```

- `TikTokVideoPreferences` value object: defaults, normalisation, validation (hex colours, `location_text` max 60, `outro_text` max 80, `phone` and `website` format checks, max lengths). Unknown keys dropped.
- `GET/PUT /api/tiktok/video-settings` (same middleware as other TikTok routes, `{data}` response, `{error:{code,message}}` errors, throttled).
- **Logo:** `POST /api/tiktok/video-settings/logo` (PNG/JPG/WebP/SVG-rasterised; validate by content with `getimagesize`/Imagick if present, otherwise PNG/JPG/WebP only; max 2 MB; max 2000px; strip metadata; store on the disk under `{user}/brand/`), `DELETE` removes it. Never serve it by path.
- **Privacy rule (enforce in code and test):** a field the user has not enabled is **never passed** to the script writer, SceneBuilder, or renderer. `mention_location=false` means the location string does not exist in any downstream input. `show_contact=false` means no business name, website or phone downstream. Location is never inferred from IP or timezone.
- Script prompt additions: when `mention_location` is on, pass `location_text` inside the existing delimited data block. Add one rule to the prompt: "Do not state prices, discounts, offers, or claims that are not in the account data."

**Exit:** validation tests, merge-not-overwrite test, logo upload/replace/delete tests, privacy tests asserting absence of disabled fields in the writer input.

### M4. Scenes, voice, captions

**`SceneBuilder` (deterministic, no model call)**
- Input: the already-normalised script (hook, spoken lines, on-screen text), preferences, and only the *enabled* brand/context fields.
- Output `RenderSpec`: `scenes[]` of `{n, kind, narration, on_screen_text, seconds_estimate}`, plus `voice`, `brand`, `format{1080x1920, 30fps}`.
- Scene count 3 to 7. Length target from preferences (about 20s or 35s) with a hard cap of 60s. Merge or split lines to fit.
- Template choice by heuristic: scene 1 = `statement`; a line with a prominent number = `stat`; lines starting with "1." / "First" patterns = `list_item`; question followed by its answer = `qa`; the last scene = `outro` (logo, outro text, location/contact only if enabled).
- Text sanitiser: strip emoji and unsupported glyphs, collapse whitespace, cap characters per template, escape ASS control characters (`\`, `{`, `}`), neutralise newlines. Unit-test hostile input.
- Store `package.scenes` (plain, small) beside the existing fields so old code is unaffected.

**TTS**
- `SpeechProvider` interface with `OpenAiSpeechProvider` (the speech endpoint via the existing `openai-php/laravel` client; model and voice ids from config constants and `TIKTOK_TTS_MODEL`; verify current model names and mark UNVERIFIED if you cannot). Per-scene calls. Style `female|male` maps to configured voice ids.
- Reserve spend through `ApiSpendService` before each call (feature `tiktok_tts`, estimate by characters), release on failure, record actual usage. Respect `AiUsageService` limits the way `TikTokScriptWriter` does. If spend or usage blocks the render, fail with "Voice isn't available right now. Try again later, or add your own video." There is no silent fallback in v1.
- Cache audio per job in the work dir by `hash(text, voice, model)` so a retry does not pay twice.
- Measure real scene duration with ffprobe on the audio. Scene length = audio length plus 0.3s padding.
- `FakeSpeechProvider` for tests and local dev (`TIKTOK_TTS_DRIVER=fake`): generates a sine tone via ffmpeg, with duration proportional to text length.

**Captions**
- Split narration into 3 to 5 word chunks. Distribute the scene's measured duration across chunks weighted by character count. Document in a docblock that timing is estimated, not aligned.
- `AssBuilder` writes one `.ass` file per scene: styles per template, safe-zone margins (left/right 90px, top 200px, bottom 480px for TikTok's UI), fades, a subtle pop-in on captions, brand colours with a luminance-based text colour chosen to keep contrast at least 4.5:1.

**Render-ready writer:** `TikTokRenderState::succeed()` is the only generated-path writer of `render.ready`. It runs only after store and validation (M5). `render.provider` becomes `ffmpeg`. Set `render.ai_generated=true` and `package.publish.ai_generated=true`. Update the old "single writer" tests deliberately.

**Exit:** SceneBuilder golden tests for several script shapes, sanitiser tests, AssBuilder golden-file tests (assert exact ASS text), TTS tests with spend reservation and release, privacy tests.

### M5. The ffmpeg motion-graphics provider

`MotionGraphicsProvider` builds a plan, runs ffmpeg through `Process` (argument arrays, `-nostdin`, `-threads 2`, timeout per scene), and returns local files.

Per scene:
- Background from a lavfi `color` source in the brand colour, with a gentle gradient if the installed ffmpeg supports it (feature-detect, fall back to solid). Logo as an `overlay` if enabled and present. Text and captions burned with the `ass` filter, fonts from the bundled font directory (`fontsdir=`).
- Narration audio muxed in. Output per scene: H.264, yuv420p, 1080x1920, 30fps, constant parameters so scenes concatenate without re-encoding.

Assembly:
- Concat the scenes, short fades between them. Optional music bed at low volume with fade-out, **only if licensed files exist in `resources/video/music/`** and `music.enabled`. If none exist, music is silently unavailable and the preference UI says so.
- Final: H.264 high profile, `crf` about 23, AAC 128k, `+faststart`, 1080x1920, 30fps. Must satisfy the **existing upload validator exactly** (MP4, H.264/AAC, under 100 MB, under 10 minutes, 9:16). Run that same validator on the output. If our own output fails it, treat it as a bug: fail the render, log a warning with the clip id, and never mark ready.
- Preview rendition: 540x960, `crf` about 28, AAC 64k, `+faststart`, target about 1 Mbps. Poster: one JPEG frame (about 540px wide, small).

Store:
- Upload final, preview, poster to the disk with `writeStream`. Create or replace the single `tiktok_clip_videos` row: `source=generated`, checksum, size, duration, dimensions, `preview_*`. Then `TikTokRenderState::succeed()`. Then, if the clip is already `queued` and upload is permitted, call `TikTokDraftService::dispatchIfReady`.
- On any failure: `render.ready` stays false, `render.status=failed`, `render.note` holds a plain reason, partial remote objects are deleted, local work dir is cleaned.

Templates (each its own class, one style definition): `statement`, `list_item`, `stat`, `qa`, `outro`. Keep the look calm and consistent with Mackie17 tokens when no brand colours are given.

**Exit:** command-builder tests (assert argument arrays, no user text in args), a real-ffmpeg integration test (group `ffmpeg`, auto-skips if ffmpeg/libass is missing) that renders a full short video with `FakeSpeechProvider` and passes the upload validator, output-validation-failure test, cleanup test.

### M6. Render-ahead scheduling and the replace rules

- In `TikTokEngineService::run()`, after the clip is created and committed, call `TikTokRenderService::dispatch()` when `generate=ahead`. This covers the hourly job, `tiktok:run`, setup completion, and the post-results next clip. For `generate=on_confirm`, dispatch inside `confirm()` after the transition commits. Rendering must never make `run()` or `confirm()` fail: catch, log with `user_id` and exception class, and continue.
- When a render completes for a `queued` clip, the draft send dispatches as above.
- `POST /api/tiktok/clips/{clip}/render` (regenerate): allowed for `waiting` or `queued` clips with a failed or ready render, **max 2 regenerations per clip** (`render.regenerations`), error `invalid_state` otherwise, 429-style code `regenerate_limit` with a plain message. Deletes the previous generated files first.
- Uploading the user's own video replaces a generated one: delete generated objects, set `render.provider=user`, cancel any in-flight render (the store-time re-check handles the race).
- `GET /api/tiktok/clips/{clip}/render`: read-only status for polling (`status`, `label`, `note`, `ready`, `can_regenerate`).
- `GET /api/tiktok/clips/{clip}/video/preview`: returns `{ preview_url, poster_url, expires_at, preview_bytes, duration_seconds }` using short-lived signed URLs (30 minutes). Owner only (404 otherwise). No side effects. On the `local` driver, serve through an authenticated streaming route with Range support instead.
- `tiktok:render --clip=ID [--force]` for manual or backfill use (also useful for clips created before this phase).
- Extend `TikTokClipResource` with `render` `{status, label, note, ready, provider, ai_generated, can_regenerate}`. Do not expose paths or raw `package`.
- Blockers: `video_file` clears when `render.ready` is true, regardless of provider.

**Exit:** tests for render-ahead dispatch (hourly run, setup completion, post-results run), render never breaks `run()`/`confirm()`, on_confirm path, regenerate cap, replace-with-own-upload race, preview endpoint scoping (another user is 404), signed URL TTL, dashboard GET starts nothing.

### M7. Desk and Settings UI

Keep the existing design language. Reuse primitives. No new dependencies. Honesty-critical copy stays.

**Desk (`ClipCard` / new `VideoPanel`)**
| State | UI |
|---|---|
| `queued`/`rendering` | Skeleton with the 9:16 aspect, "Making your video…", `aria-busy`, polite live region. Poll the render endpoint every 5s with backoff, only while the tab is visible, stop after 10 minutes |
| `ready` | 9:16 player with the poster, `preload="none"`, native controls, a line "About {size} to watch. Nothing is sent to TikTok until you confirm." Caption: "Made with Mackie17. Check it before you post." Buttons: **Make it again** (shows remaining count), **Use my own video instead** |
| `failed` | Alert: "We couldn't make the video. Try again, or add your own." with the note, **Try again**, and the existing upload dropzone |
| `skipped` | Short reason and a link to Video settings, plus the upload dropzone |
| User video present | Existing upload UI, unchanged |

- The preview URL is fetched only when the video panel mounts in `ready`, and refreshed on expiry or 403. Never put it in the dashboard payload.
- Confirm button copy and helper text: reuse the strings already in the code. If the existing helper text says the video "is not ready", make it accurate for the ready case and tell me what you changed.
- Reduced motion respected. Works at 360px, keyboard operable, state by icon plus text.

**Settings: "Video" card for TikTok**
- Type picker: Motion graphics selectable. Cartoon, Real person, Stock shown disabled with a "Coming soon" badge.
- Voice (female/male, language English only, note "More languages are coming"), captions, length, music (disabled with the explanation if no licensed tracks are installed), generate timing ("Make it ahead" default / "Make it when I confirm").
- Brand: logo upload with preview and remove, colours with a live contrast warning, font, outro text.
- "Use my business details" section, all off by default: location (text field, helper "Only what you type here appears in the video."), contact line.
- Autosave or an explicit Save, whichever matches the existing Settings pattern. Inline validation from the API's field errors.
- Update `/dev/tiktok-states` with: rendering, ready, failed, skipped, regenerate limit reached, queued-with-generated-video.

**Exit:** `tsc --noEmit` clean, `next build` (report the known Google Fonts timeout if it recurs), keyboard pass, screenshots at 360/768/1280 for each new state.

### M8. Ops, docs, hardening

- Admin: per-clip render status and note visible in the existing "Needs attention" and user lookup. Add `diagnose()`-style render guard output ("why wasn't a video made for this clip?") reusing the real guards, no side effects.
- Update `docs/SYSTEM.md` sections 4, 7, 13, 15, 16, 17, 19 to match the code. Add `docs/VIDEO_RENDERING.md` covering the pipeline, templates, font and music licensing rules, ffmpeg requirements, and troubleshooting.
- AI-content labelling: verify TikTok's current requirement for AI-generated content disclosure in the Content Posting API docs and record the finding in `docs/VIDEO_RENDERING.md`. We store `ai_generated=true`. Do not change the send request unless the docs require a parameter for inbox uploads, and if they do, tell me before changing it.
- Privacy audit: confirm no log, response or exception message contains signed URLs, storage keys, TTS input, or account text. Report what you checked.
- Compliance mapping in `docs/SYSTEM.md` section 12: add rows for rendering and storage.
- Concurrency tests: double regenerate, render racing discard, render racing user upload, render racing confirm, two workers on one clip.
- Prompt-injection regression: a description containing instructions cannot reach an ffmpeg argument or alter the ASS structure.

## 4. Quality bar

- Match existing style, run Pint, strict types only if the repo does. Small classes, constructor injection, no magic strings outside `TikTokCatalog` and a `TikTokVideoCatalog` for video constants.
- Everything that shells out is behind one `FfmpegRunner` class (argument arrays, timeouts, captured stderr trimmed and never shown to users).
- Comments only where intent is not obvious: estimated caption timing, safe zones, store-time re-check, why intermediates stay local, the single `ready` writer.

## 5. Manual verification (report real output)

1. Run the full chain with `TIKTOK_TTS_DRIVER=fake` on local storage: run → clip → render → ready → preview → confirm. Show `ffprobe` output of `source.mp4` and `preview.mp4`, file sizes, and the poster.
2. Extract a contact sheet of 6 frames from the final video (ffmpeg `tile`) for a statement, stat, list, qa and outro scene, and attach it. State whether text stays inside the safe zones and is readable.
3. Repeat with real OpenAI TTS if `OPENAI_API_KEY` is set (one clip).
4. Run `tiktok:storage-check` against the server disk and report the result.
5. Throttle the browser to "Fast 3G" and report time-to-poster and time-to-first-frame for the preview.
6. Walk the desk at 360px: rendering, ready, failed (kill the worker), regenerate, replace with own upload, confirm.

## 6. Acceptance criteria

- [ ] An hourly or setup-triggered clip gets a finished 1080x1920 H.264/AAC video without any click, and the desk shows it with a small preview.
- [ ] Confirm is still the only consent. Nothing reaches TikTok before it, and rendering never changes allowance, status, or scopes.
- [ ] `render.ready` is true only for a stored, validated file. Failures leave it false with a plain reason and an upload fallback.
- [ ] Disabled profile fields (location, contact, logo) never reach the script writer, scenes, or renderer.
- [ ] Videos are stored on the server disk. Intermediates stay in the work directory and are deleted when the job ends.
- [ ] The queue worker, sweeper, and ops warnings exist and are documented.
- [ ] User text can never reach a command line or break the ASS structure.
- [ ] Full suite green, `tsc` clean, new tests pass, existing X/YouTube behaviour unchanged.

## 7. Report back with

1. Files created or changed, by milestone.
2. ffmpeg version and the capabilities you detected (libass, drawtext, xfade, gradients).
3. Which OpenAI speech and TikTok API details were verified against docs and which are UNVERIFIED.
4. Differences between this brief and the repo, and what you chose, including the `settings` merge fix.
5. Test, type-check, lint, and build results.
6. The contact sheet, ffprobe output, and desk screenshots, or the reason they could not be produced.
7. Remaining risks (worker capacity, disk space, TTS quality) and open questions.

If you hit ambiguity at any step, stop and ask instead of inventing a convention.