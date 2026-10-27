# TikTok engine implementation plan

Living checklist for the TikTok module. Check a box when the work is in the repo and the exit test for that item passes. When a phase’s exit criteria are all true, set its status in the table below.

Source of the product picture: `docs/SYSTEM.md`. Source of the phase decisions: `docs/phases.md`. This file is the one to follow while building. If this file and `docs/SYSTEM.md` section 16 disagree, this file wins for TikTok, because it resolves the stuck-pipeline and storage questions that section 16 left open.

Last updated: 2026-10-27. Nothing in Phases 1–8 is started.

## Status

| Phase | Name | Status | Depends on |
|---|---|---|---|
| 0 | Decisions | Locked | — |
| 1 | Data layer and catalog | Not started | Phase 0 |
| 2 | Engine core | Not started | Phase 1 |
| 3 | Confirm, results, caps | Not started | Phase 2 |
| 4 | Desk UI | Not started | Phase 3 |
| 5 | Scheduling and operations | Not started | Phase 3 |
| 6 | Ideas and results screens | Not started | Phase 3 |
| 7 | Connection, render, upload | Blocked on TikTok developer approval | Phases 4 and 5 |
| 8 | Hardening | Not started | Phases 4, 5, 6 |

Phases 5 and 6 can run in parallel after Phase 3. Phase 7 does not block 1–6. Phases 1–5 are a complete honest product: the user gets a clip, a time, and a confirm step, and the screen never says the clip was posted.

## How to use this file

- Tick `- [ ]` items as they land. Leave the exit criteria unchecked until the named test or walkthrough has been run.
- Do not start a phase whose dependency is still “Not started.”
- A skipped run, a missing file, and a refused confirm must say why. The UI copies the reason. It does not invent a success state.
- Pattern to copy: `YouTubeEngineService`, `YouTubeEngineTest`, `YouTubeCatalog`, `YouTubeVideo::toDesk()`. Do not call `GrowthAgentService` from TikTok code, and do not read or write `rate_limit_budgets` for clips.

## Phase 0 — Decisions

Locked. Do not reopen these inside a phase unless this section is edited first.

- [x] **Clips are the record.** Pipeline, suggested time, confirm, and learning live on `tiktok_clips`. v1 does not copy the caption into `posts`. Compose may still save a standalone `tiktok_caption` draft. That draft is not a clip and does not enter the engine.
- [x] **Timezone is stored twice.** `account_profiles.timezone` is an IANA name captured from the browser at onboarding (`Intl.DateTimeFormat().resolvedOptions().timeZone`), editable later in Settings. `suggested_for` is stored in UTC. Each clip also stores `timezone_hint`, the profile timezone at the moment the time was chosen, so a later profile change does not rewrite history. The client renders `suggested_for` in `timezone_hint`.
- [x] **Allowance is consumed on confirm.** A clip counts when it moves to `queued`. Discard before `posted` releases it. `posted` and `learned` stay counted. The X daily post budget is never touched.
- [x] **The pipeline cannot get stuck.** `run()` will not create a clip while one is `waiting`, `queued`, or `posted` and not yet learned. Because upload does not exist yet, a queued clip would block the engine forever. Escape hatches, beyond the route list in `docs/SYSTEM.md` section 17: discard, reschedule, and results from `waiting`, `queued`, or `posted`.
- [x] **Confirm never uploads.** Until Phase 7, confirm sets `queued` and an honest publish note. It does not set `posted`.
- [x] **TikTok does not autopost.** `platform_setups.settings.publish` stays `confirm` for every tier.

## Shared rules

These apply in every phase.

**Status machine**

```
waiting  → queued      confirm
waiting  → discarded   discard
waiting  → learned     results
queued   → discarded   discard (releases the allowance)
queued   → learned     results
queued   → posted      Phase 7 upload only
posted   → learned     results
```

Illegal examples the transition helper must reject: `waiting → posted`, `discarded → queued`, `learned → queued`, `posted → queued`.

**Who `run()` will serve**

All of these must be true:

- `users.is_blocked` is false
- `account_profiles.onboarding_completed` is true
- a `platform_setups` row exists for `tiktok` with `completed_at` set
- no clip for that user is `waiting`, `queued`, or `posted` without `learned_at`
- the TikTok monthly allowance is not spent
- the per-user cache lock `tiktok-run-{id}` is free (30 seconds)

**Package shape stored on every generated clip**

```json
{
  "hook": "",
  "spoken_lines": [],
  "on_screen": [{ "text": "", "at": "0:00" }],
  "shots": [{ "prompt": "", "seconds": 3, "kind": "clip" }],
  "sound_note": "",
  "render": { "provider": "pending", "ready": false, "note": "The lines are written. The video file is made when the render step is connected." },
  "publish": { "mode": "confirm", "uploaded": false, "note": "Waiting for your yes." }
}
```

`render.ready` stays false until Phase 7 has a real file. A script is not a file.

**Suggested time**

Default local slot is 19:30 in the profile timezone. If that instant is under two hours away, use the next day’s 19:30. `suggested_for` is always in the future. Platform settings `tiktok_suggest_hour` (default 19) and `tiktok_suggest_minute` (default 30) override the slot. Missing timezone falls back to `UTC` and the clip’s `timezone_hint` is `UTC`.

**Learning bands**

Same cut as YouTube, applied to completion percent. These are product copy, not a claim about the For You algorithm.

| Completion | Note |
|---|---|
| ≥ 50 | People stayed. The next clip keeps this shape. |
| < 30 | People left early. The next clip uses a different opening. |
| otherwise | Recorded. The next idea takes this into account. |

**Goal weights inside `TikTokDemandService`**

| Goal key | Strategy | What the score prefers |
|---|---|---|
| `for_you` | fast hook | A first line that can stand alone in the first second |
| `followers` | reason to stay | A reason to follow, stated in the clip |
| `traffic` | link-carrying | A clip that can point at a product or link |
| `promote_business` | offer | The business from the account description |
| `monetize` | series habit | A repeatable format the account can post again |

Do not use `rules_post_threshold` (82) or `rules_engage_threshold` (68).

**Allowance query**

Count `tiktok_clips` for the user where `confirmed_at` falls in the current subscription period, `discarded_at` is null, and `status` is `queued`, `posted`, or `learned`. Compare with the active `billing_subscription_lines` row where `platform = tiktok`. If the user has no line, treat the allowance as the trial line `TrialService` already created when they picked TikTok. If they have no TikTok line at all, `run()` and `confirm` refuse with “TikTok is not on this account.”

**Daily cap**

Platform setting `tiktok_daily_clip_cap`, group `safety`, default `2`. Enforced at confirm. Count confirms whose `confirmed_at` falls on today’s date in `timezone_hint`. A discard does not refund the daily cap. The cap stops a burst. The monthly allowance stops the month.

## Phase 1 — Data layer and catalog

**Goal.** Schema and vocabulary exist. No generation, no routes that create clips, no UI change.

**Files**

- `backend/database/migrations/*_create_tiktok_clips_table.php`
- `backend/database/migrations/*_add_timezone_to_account_profiles.php`
- `backend/app/Models/TikTokClip.php`
- `backend/app/Models/AccountProfile.php` (fillable + cast for `timezone`)
- `backend/app/Models/User.php` (`tiktokClips()` hasMany)
- `backend/app/Support/TikTokCatalog.php`
- `backend/database/factories/TikTokClipFactory.php`
- `backend/tests/Unit/TikTokClipTest.php`

**`tiktok_clips` columns**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `user_id` | fk users, cascade | index with `status` |
| `status` | string(32) | `waiting`, `queued`, `posted`, `learned`, `discarded` |
| `topic` | string, nullable | |
| `hook` | text, nullable | |
| `script` | text, nullable | spoken lines joined |
| `caption` | text, nullable | `tiktok_caption` rules |
| `suggested_for` | timestamptz, nullable | UTC |
| `timezone_hint` | string(64), nullable | IANA name copied at creation |
| `package` | json, nullable | shape in Shared rules |
| `score` | decimal(5,2), nullable | demand score |
| `views` | unsigned int, default 0 | |
| `completion_percent` | decimal(5,2), nullable | |
| `follows` | unsigned int, default 0 | |
| `learning_note` | text, nullable | |
| `learned_at` | timestamptz, nullable | |
| `confirmed_at` | timestamptz, nullable | |
| `queued_at` | timestamptz, nullable | |
| `posted_at` | timestamptz, nullable | |
| `discarded_at` | timestamptz, nullable | |
| `platform_post_id` | string, nullable | empty until Phase 7 |
| `skip_reason` | string, nullable | unused on the clip itself; skips go to the log in Phase 5 |
| timestamps | | |

Index `(user_id, status)`.

**Model**

- [ ] Fillable list matches the columns above. Cast `package` to array, decimals to float, and every `*_at` to datetime.
- [ ] `scopeOpen`: status in `waiting`, `queued`.
- [ ] `scopeUnlearned`: `learned_at` is null and status is not `discarded`.
- [ ] `scopeForUser($userId)`.
- [ ] `transitionTo(string $status): void` throws `ValidationException` on an illegal jump and sets the matching timestamp (`queued_at`, `posted_at`, `learned_at`, `discarded_at`).
- [ ] `toDesk(): array` returns id, status, status label, topic, hook, script, caption, suggested time as ISO-8601, `timezone_hint`, package, score, views, completion, follows, learning note, and the timestamps. This is what Phase 4 renders.

**Catalog**

- [ ] Status labels: Waiting for you, Confirmed, Posted, Learned, Discarded.
- [ ] Goal → strategy map from Shared rules.
- [ ] Learning-band helper `noteFor(float $percent): string`.
- [ ] Caption constraints as constants: max 400 characters, 3 to 5 hashtags.

**Tests**

- [ ] Factory creates a `waiting` clip with `render.ready = false`.
- [ ] `waiting → posted` throws.
- [ ] `waiting → queued → learned` sets `queued_at` and `learned_at`.
- [ ] `discarded → queued` throws.

**Exit criteria**

- [ ] `php artisan migrate` succeeds against the local Postgres (port 5433).
- [ ] `php artisan test --filter=TikTokClipTest` passes.

## Phase 2 — Engine core

**Goal.** `run($user)` stores one `waiting` clip with a future `suggested_for`. Nothing is scheduled. Nothing is confirmed. Nothing is posted.

**Files**

- `backend/app/Services/TikTok/TikTokDemandService.php`
- `backend/app/Services/TikTok/TikTokScriptWriter.php`
- `backend/app/Services/TikTok/TikTokEngineService.php`
- `backend/tests/Feature/TikTokEngineTest.php`

**Demand**

- [ ] `ranked(AccountProfile $profile, User $user, int $limit = 6): array` returns `{topic, kind, reason, score}`.
- [ ] Ideas are local to the niche and audience, the same honesty as `YouTubeDemandService` (“local until search is connected”).
- [ ] Recent clip topics for that user are excluded when any fresh idea remains.
- [ ] Goal weights from Shared rules are applied.
- [ ] A past clip with completion ≥ 50 adds 20 to ideas of that `kind`. Completion < 30 subtracts from that `kind`. Read this from `tiktok_clips`, not from YouTube.
- [ ] Scores never consult the X thresholds.

**Script writer**

- [ ] `clip(User, AccountProfile, idea, ?learningNote): array` returns hook, spoken lines, caption, on-screen text, shots, sound note.
- [ ] The system prompt goes through `AICoreService::buildSystemPrompt('tiktok_script', ...)` so admin AI rules apply.
- [ ] The user prompt includes niche, tone, audience, `goalSentenceFor('tiktok')`, the account description, and the last learning note.
- [ ] Caption obeys the existing rule: first line is a spoken hook, then one or two lines, then 3 to 5 hashtags, under 400 characters. `TikTokCatalog` checks this in `normalize()`.
- [ ] Usage is recorded with feature `tiktok_script`.
- [ ] If the model throws or the key is empty, a local fallback still returns a complete package. The desk must not be empty because the model is down. Mirror `YouTubeScriptWriter`’s fallback.

**Engine `run(User): ?TikTokClip`**

- [ ] Cache lock `tiktok-run-{id}` for 30 seconds. A second caller inside the lock returns the current open clip and does not create another.
- [ ] Refuse, returning null, when the user is blocked, onboarding is incomplete, or TikTok setup is not completed.
- [ ] Refuse when an open or unlearned clip exists. Return that clip.
- [ ] Pick `ranked()[0]`. If the list is empty, return null.
- [ ] Build the package from Shared rules. `publish.note` is “Waiting for your yes.”
- [ ] Set `suggested_for` with the evening-slot rule and copy `timezone_hint` from the profile.
- [ ] Insert status `waiting`.

`runAll()` can wait until Phase 5. Do not register the scheduler in this phase.

**Tests** (`TikTokEngineTest`, `RefreshDatabase`, model faked or key forced empty the way `YouTubeEngineTest` sets `openai.api_key` to `''`)

- [ ] A user who finished TikTok setup gets exactly one clip from `run()`, status `waiting`, `suggested_for` in the future, `render.ready` false, caption within 400 characters.
- [ ] A second `run()` does not insert a second row.
- [ ] A blocked user gets no clip.
- [ ] A user with no completed TikTok setup gets no clip.
- [ ] With the model throwing, one fallback clip is still stored.

**Exit criteria**

- [ ] `php artisan test --filter=TikTokEngineTest` passes.
- [ ] No route, scheduler entry, or UI copy claims a clip was posted.

## Phase 3 — Confirm, results, learning, and caps

**Goal.** The loop is usable through the API. Upload still does not happen.

**Files**

- `backend/app/Http/Controllers/Api/TikTokController.php`
- `backend/app/Services/TikTok/TikTokAllowance.php` (count, refuse, release)
- `backend/routes/api.php` (inside the existing `auth:sanctum` + `customer` group)
- `backend/app/Services/Admin/PlatformSettingsService.php` (new settings only)
- `backend/tests/Feature/TikTokEngineTest.php` (extend)

**Routes**

| Method | Path | From | To |
|---|---|---|---|
| POST | `/api/tiktok/run` | — | calls `run()`, returns the clip or the refusal reason |
| POST | `/api/tiktok/clips/{clip}/confirm` | `waiting` | `queued` |
| POST | `/api/tiktok/clips/{clip}/results` | `waiting`, `queued`, `posted` | `learned`, then `run()` |
| POST | `/api/tiktok/clips/{clip}/discard` | `waiting`, `queued` | `discarded` |
| POST | `/api/tiktok/clips/{clip}/reschedule` | `waiting`, `queued` | same status, new `suggested_for` |

- [ ] Bind `{clip}` with a policy or an explicit `user_id` check. Another user’s clip returns 404.
- [ ] Confirm from any status other than `waiting` returns 422.
- [ ] Confirm sets `confirmed_at`, `queued_at`, and `package.publish` to `{mode: confirm, uploaded: false, note: "Waiting for the video file and your TikTok connection."}`. Status becomes `queued`. It does not call any HTTP client.
- [ ] Confirm checks the monthly allowance first, then the daily cap. Each failure returns 422 with `reason` of `allowance` or `daily_cap` and a sentence the UI can show.
- [ ] Results accepts `views` (int ≥ 0), `completion_percent` (0–100), `follows` (int ≥ 0). It writes the learning note from `TikTokCatalog::noteFor`, sets `learned`, then calls `run()` so the next clip can read the note.
- [ ] Results on a `queued` clip that was never posted still learns. The note keeps the band sentence. The package publish note stays the unsent sentence. This is the escape hatch that lets the next clip exist before TikTok OAuth exists.
- [ ] Discard from `waiting` sets `discarded_at` and does not change the allowance count. Discard from `queued` sets `discarded_at` and the next count query ignores it.
- [ ] Reschedule body is `{ "suggested_for": "<ISO-8601>" }` or `{ "use_default_slot": true }`. The new time must be in the future. Reschedule does not consume a second allowance credit and does not change status.
- [ ] `POST /api/tiktok/run` returns 422 with `reason: allowance` when the month is spent, and does not insert a clip.

**Settings**

- [ ] `tiktok_daily_clip_cap` in `PlatformSettingsService`, group `safety`, type integer, default `2`, label “TikTok clips confirmed per day”.
- [ ] `tiktok_suggest_hour` default `19`, `tiktok_suggest_minute` default `30`, group of their own or `safety`.

**Tests**

- [ ] Confirm from `learned` or `queued` returns 422.
- [ ] Another user’s clip returns 404 on confirm, results, discard, and reschedule.
- [ ] Allowance of 1: the first confirm queues, the second `run()` after that clip is learned returns 422 `allowance`. Discarding the queued clip lets a new confirm succeed.
- [ ] Two confirms on the same UTC day in a timezone where both fall on that local day, with cap 1: the second returns 422 `daily_cap`.
- [ ] Results on a waiting clip sets `learned`, stores the “left early” note when completion is 22, and inserts the next clip with a different topic when demand has a fresh idea.
- [ ] Reschedule rejects a past timestamp.

**Exit criteria**

- [ ] `php artisan test --filter=TikTokEngineTest` passes, including the Phase 2 cases.
- [ ] A manual `curl` or test shows confirm’s JSON publish note contains “TikTok connection” and `uploaded: false`.

## Phase 4 — Desk UI

**Goal.** `/dashboard/tiktok` shows the clip the engine actually has. Every state is honest. Confirm names the time.

**Files**

- `backend/app/Http/Controllers/Api/DashboardController.php` (`platform('tiktok')`)
- `backend/app/Http/Controllers/Api/AccountSetupController.php` (call `run()` when TikTok setup completes)
- `backend/app/Services/TikTok/TikTokEngineService.php` (`desk(User): array`, same role as `YouTubeEngineService::desk()`)
- `apps/web/components/dashboard/PlatformDesk.tsx` (`TikTokBody`)
- `apps/web/app/onboarding/page.tsx` (send `timezone`)
- `apps/web/app/(app)/settings/page.tsx` (show and edit timezone)
- `apps/web/lib/api` client calls for the Phase 3 routes

**API payload**

`GET /api/dashboard/platform/tiktok` adds, beside the fields it already returns:

- [ ] `ideas`: up to three demand rows `{name, suggestion}` where suggestion is the demand `reason`.
- [ ] `pipeline`: latest clips via `toDesk()`, discarded excluded, limit 8.
- [ ] `current`: the open or unlearned clip, or null.
- [ ] `block`: null, or `{reason, message}` when the next run is refused (`allowance`, `daily_cap`, `open_clip`).
- [ ] Preview metrics stay numeric placeholders and keep the existing “Sample” badge until Phase 7 replaces them. The badge text stays “Sample”.

**Setup**

- [ ] Onboarding reads the browser timezone and sends it with `POST /api/account/setup/about` (or with the TikTok platform step if about is already saved). Persist `account_profiles.timezone`.
- [ ] Settings can change it. Existing clips keep their old `timezone_hint`.
- [ ] `AccountSetupController::platform()` for `tiktok`, after the setup row is saved, calls `TikTokEngineService::run($user)`.

**States**

| State | What the user sees | Primary action |
|---|---|---|
| No clip, no block | “Looking for an idea that fits your niche.” Skeleton, then the clip if `run` returns one. | none while the request is in flight |
| `waiting` | Hook large, spoken lines, caption, on-screen text, shot list, suggested time in `timezone_hint` | “Yes, post at {local time}” |
| `queued` | “Confirmed. Not posted yet.” Checklist: video file unchecked, TikTok connection unchecked. | Add results, Discard |
| `posted` | Posted time. Link only if `platform_post_id` exists. | Add results |
| Results form | Views, completion (slider and number), follows. Short hint for where each number sits in the TikTok app. | Save and get the next idea |
| `learned` | The learning note in plain words, and the kind the next clip will prefer. | none |
| Allowance spent | The `block.message` sentence. No confirm button on a new clip. | none |
| Daily cap | Inline error on confirm. The clip stays `waiting`. | Change time, or try tomorrow |

- [ ] After confirm, the visible status is “Confirmed. Not posted yet.” The word “Posted” is reserved for status `posted`.
- [ ] Confirm is one button that includes the local time. “Change time” and “Skip this idea” are separate controls, not beside the confirm button in the same tap target.
- [ ] The clip card is a single column. On viewports under `sm`, the confirm bar sticks to the bottom.
- [ ] Status is a text label, not color alone. Confirm shows a disabled loading label (“Confirming…”) and the error inline.
- [ ] The idea action no longer sends the user only to Compose. It calls `POST /api/tiktok/run`. If `block.reason` is `open_clip`, the button explains that a clip is already waiting. Compose stays available from “New clip” in the sidebar for a caption that is not a clip.
- [ ] `TikTokBody` stops using `desk.counts.drafts` as “Waiting for you”. Those counts are Compose drafts. The waiting number is the count of pipeline items in `waiting`.

**Exit criteria**

- [ ] Each row in the state table renders from a fixture or a seeded clip. A component test or a story-style render is enough for the empty, waiting, queued, learned, and blocked states.
- [ ] Browser walkthrough on a phone-width viewport and a desktop viewport: finish TikTok setup, see one waiting clip, confirm, see the unsent checklist, discard, and see the allowance error when the line is exhausted.
- [ ] `php artisan test --filter=TikTokEngineTest` still passes, including a test that completing TikTok setup creates one clip.

## Phase 5 — Scheduling and operations

**Goal.** Eligible accounts get a clip without someone pressing Run. Support can see why an account was skipped.

**Files**

- `backend/routes/console.php`
- `backend/routes/api.php` (`POST /api/admin/tiktok/run`)
- `backend/app/Http/Controllers/Api/AdminController.php`
- `apps/web/app/(admin)/admin/operations/page.tsx`
- `backend/database/migrations/*_create_tiktok_engine_skips_table.php`
- `backend/tests/Feature/TikTokEngineTest.php`

**Scheduler**

- [ ] `Artisan::command('tiktok:run')` calls `runAll()` and prints the count, matching `youtube:run`.
- [ ] `Schedule::call(...)->hourly()->name('tiktok-engine')->withoutOverlapping(15)`.
- [ ] `runAll()` uses the same eligibility as `run()` and never includes blocked users, incomplete onboarding, or incomplete TikTok setup.

**Skip log**

Table `tiktok_engine_skips`: `user_id`, `reason` (`blocked`, `onboarding`, `setup_incomplete`, `open_clip`, `unlearned`, `allowance`, `lock`), `created_at`. Index `(user_id, created_at)`.

- [ ] Every scheduled skip writes one row. A successful `run()` that creates or returns a clip does not.
- [ ] `runAll()` deletes skip rows older than 14 days.

**Admin**

- [ ] `GET /api/admin/operations` adds `tiktok: { waiting, queued, posted, learned, discarded, last_run_at, skips_24h }`.
- [ ] `POST /api/admin/tiktok/run` calls `runAll()` and returns the count. Admin only.
- [ ] Operations page shows the counts and a “Run TikTok engine” button next to the existing X evaluate action. Failures toast the API message.

**Tests**

- [ ] Seed three users: eligible, blocked, and TikTok setup incomplete. `runAll()` creates a clip only for the eligible user.
- [ ] An eligible user who already has a `waiting` clip gets a skip row `open_clip` and no second clip.
- [ ] `php artisan schedule:list` includes `tiktok-engine`.

**Exit criteria**

- [ ] The tests above pass.
- [ ] Operations page shows the new counts after `tiktok:run` on a seeded user.

## Phase 6 — Ideas and results screens

**Goal.** The TikTok sidebar labels “Ideas” and “What landed” open TikTok data when the active platform is TikTok. X screens stay X screens.

Can ship in parallel with Phase 5.

**Files**

- `apps/web/app/(app)/trends/page.tsx`
- `apps/web/app/(app)/analytics/page.tsx`
- `backend/app/Http/Controllers/Api/DashboardController.php` or a small `TikTokController::ideas` / `results`
- New routes, auth + customer:
  - `GET /api/tiktok/ideas`
  - `GET /api/tiktok/results`

**Ideas**

- [ ] When `usePlatform()` is `tiktok`, `/trends` requests `/api/tiktok/ideas`.
- [ ] The list is `TikTokDemandService::ranked()` with the reason visible (“Fast hook. Fits For You.”).
- [ ] Empty state: “Finish TikTok setup to see ideas.” when setup is incomplete.
- [ ] X and YouTube topic pages are unchanged when those platforms are active.

**What landed**

- [ ] When the platform is `tiktok`, `/analytics` lists clips that have `learned_at`, newest first: topic, views, completion, follows, learning note, suggested time.
- [ ] Sample metrics on the desk stay labeled Sample. This page does not invent account-level views.

**Exit criteria**

- [ ] Feature test: ideas payload includes `reason`, results payload excludes clips with no `learned_at`.
- [ ] Browser check: switch the shell from X to TikTok and confirm Topics and Results show different data.

## Phase 7 — Connection, render, and upload

**Goal.** A confirmed clip can become a real file and a real TikTok post. This phase waits on TikTok developer approval. Do not block Phases 1–6 on it.

**Files**

- `backend/app/Http/Controllers/Api/ConnectedAccountController.php`
- `backend/app/Services/TikTok/TikTokClient.php` (OAuth and upload only)
- `backend/app/Services/TikTok/TikTokRenderer.php`
- `backend/routes/api.php`
- `apps/web/components/settings/ConnectedAccountCard.tsx`
- `routes/console.php` (a poster pass, separate from generation)

**OAuth**

- [ ] `GET /api/connected-accounts/tiktok/status` returns `authorize_url` when the client id is configured, otherwise `{connected: false, available: false}`.
- [ ] Callback stores `connected_accounts` with `platform = tiktok`, encrypted tokens, expiry, and username. Unique key already allows this.
- [ ] `DELETE /api/connected-accounts/tiktok` deactivates the row.
- [ ] Until `available` is true, `ConnectedAccountCard` keeps the sentence “The account connection waits on TikTok.”

**Render**

- [ ] `TikTokRenderer` produces a file only from the stored shots and spoken lines, or returns a structured failure.
- [ ] It sets `package.render.ready = true` and `package.render.provider` to the tool that actually ran (`ffmpeg` or the model name) only after the file is on disk.
- [ ] A failed render leaves `ready` false and writes the reason onto `package.render.note`.

**Upload**

- [ ] A scheduled or queued poster selects clips in `queued` whose render is ready and whose user has an active TikTok connection.
- [ ] Success sets `posted`, `posted_at`, and `platform_post_id`.
- [ ] Failure leaves status `queued`, `uploaded` false, and records the provider message on `package.publish.note`. The desk shows that note. It does not say “Posted.”
- [ ] The poster does not like, follow, comment, duet, or stitch.
- [ ] Desk preview metrics for a connected account come from the stored clip results, not from `crc32` placeholders. The Sample badge goes away only when `connected` is non-null.

**Exit criteria**

- [ ] A test with a fake client: queued + ready file + connection → `posted` and an id. The same test with the client throwing stays `queued` and keeps the error note.
- [ ] A test with no client id: status endpoint returns `available: false` and the settings card copy is unchanged.

## Phase 8 — Hardening

**Goal.** The loop survives a double click, a discard, and a reread of the docs.

- [ ] Two concurrent confirms on one `waiting` clip result in one `queued` row. Use a row lock or an atomic status update. Test it.
- [ ] Discard of a `queued` clip makes the allowance count drop by one. Test it.
- [ ] Discard of a `posted` clip is rejected.
- [ ] `docs/SYSTEM.md` sections 16–19 match this plan: clip table, escape-hatch routes, timezone, allowance on confirm, no caption mirror in v1.
- [ ] `docs/DEVELOPMENT_PLAN.md` Phase 6 TikTok line points here instead of “Not started.”
- [ ] `docs/phases.md` stays the decision note. Its status is not a second checklist.
- [ ] Read the safety section of `docs/SYSTEM.md` against the poster and the confirm path. Confirm the code still cannot send likes, follows, comments, or DMs.

**Exit criteria**

- [ ] `php artisan test --filter=TikTok` passes.
- [ ] The doc edits above are in the same change as the last code fix they describe.

## Worked example

A creator in `Africa/Kampala` finishes TikTok setup with goal `for_you`.

1. Setup saves `timezone` and calls `run()`.
2. Demand picks a fast-hook idea. The writer returns a hook, four spoken lines, and a caption under 400 characters. `render.ready` is false.
3. The clip is `waiting`. `suggested_for` is the next 19:30 Kampala time, stored in UTC. The button reads “Yes, post at 7:30 PM”.
4. Confirm moves it to `queued`, spends one clip of the monthly allowance, and shows “Confirmed. Not posted yet.” with the file and the connection unchecked.
5. They enter views `400`, completion `22`, follows `3`. The note says people left early. Status becomes `learned`. `run()` stores a new `waiting` clip whose opening is a different kind.
6. If they had discarded at step 4, the allowance would be free again and no skip row would be required for the next manual run.

## Out of scope for v1

- Mirroring captions into `posts`.
- Footage upload for TikTok. YouTube has it. TikTok can add it after the confirm loop is real.
- Auto-posting when a score crosses a threshold.
- Likes, follows, comments, duets, stitches, or DMs.
- Scraping TikTok.
- Using the X growth brain or the X safety budget.
- Replacing sample desk numbers before a TikTok account is connected.
