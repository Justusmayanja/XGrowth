# Phase 3: Confirm, results, learning, allowance, and daily cap (API)

You are a senior Laravel engineer working in the Mackie17 repo (Laravel app "Xpulse", folder "XGrowth"). Your job is **Phase 3 only**: expose the TikTok loop over HTTP and enforce the billing allowance and a daily cap. After this phase the full cycle works through the API:

`run → waiting → confirm → queued → results → learned → next clip`

Read `docs/SYSTEM.md` first (source of truth when docs disagree), especially sections 5, 11, 12, 16, and 17. Phases 1 and 2 are merged: `tiktok_clips`, `TikTokClip` (with `transitionTo`, scopes), `TikTokCatalog`, `TikTokEngineService::run()`, `TikTokDemandService`, `TikTokScriptWriter`, `TikTokTimeSuggester`. Build on them. Do not redesign them.

## 1. Context

- TikTok's rule: **nothing uploads until the user explicitly confirms that clip.** And upload does not exist yet. So `confirm` moves a clip to `queued` and says honestly what is still missing. It never uploads and never implies it did.
- Because upload cannot exist yet, a clip would sit in `queued` and block the engine forever (the open-item guard). So this phase adds **discard** and **reschedule**, and lets results be entered from `waiting`, `queued`, or `posted`. These go beyond the route list in SYSTEM.md section 17 and are intentional.
- The monthly allowance is a **clip count** on `billing_subscription_lines` (`platform = tiktok`, `allowance_unit = clips`). It is not the X post counter. Never touch `rate_limit_budgets`.

## 2. Before you write any code

1. Read `YouTubeController` and the YouTube routes in `routes/api.php`: middleware stack, route naming, how confirm and results validate, response shape. **Mirror them.**
2. Read `YouTubeEngineService::confirm` and `recordResults` and the YouTube tests for them.
3. Read the billing code: `config/billing.php`, `billing_subscriptions`, `billing_subscription_lines`, `TrialService`, and how `syncForPlatforms` creates lines during a trial. Answer these before coding, and write the answers in your final report:
   - How do I resolve a user's **active** TikTok line, including during a trial and after cancel/expiry?
   - What is the billing period (start and end) for monthly allowance purposes? Is there a period start column, or only a period end? Does a trial have its own period?
   - For yearly plans, is `monthly_allowance` still per calendar/billing month?
   - What happens for a user who has **no** TikTok line?
   If any of this is ambiguous, **stop and ask**. Do not guess the billing model.
4. Read how admin platform settings are defined and read at runtime (the class or helper that backs `/admin/platform-settings`, defaults, env fallbacks, validation, and the admin UI schema if settings are declared in a registry). Mirror that exactly for the new setting.
5. Read the existing Form Request / validation style and any API Resource classes. Use the same pattern.
6. Re-read Phase 1 `transitionTo` (it locks and re-validates). Use it for every state change.
7. Check how Phase 2 tests call billing (they assumed `withinAllowance()` returns true). You will need a test helper to give a user a TikTok line.

## 3. Hard boundaries

- Do NOT implement upload, render, TikTok OAuth, or anything touching `connected_accounts`.
- Do NOT add scheduler entries, console commands, or `runAll()`. That is Phase 5.
- Do NOT edit `AccountSetupController`, `DashboardController`, or any frontend file. That is Phase 4.
- Do NOT touch `GrowthAgentService`, `SafetyEngineService`, `rate_limit_budgets`, or X code.
- Do NOT write to `posts`.
- Do NOT decrement or modify any allowance counter column. **Usage is derived by query, never stored** (see section 4D), so it cannot drift.
- Never set `render.ready = true` or `publish.uploaded = true`.
- Do NOT add a `GET` list endpoint. The desk will read through the dashboard payload in Phase 4.
- Only one new platform setting is allowed: `tiktok_daily_clip_cap`. No other settings, env vars, or dependencies.
- If the repo contradicts this brief, stop and ask. Do not guess.

## 4. Deliverables

### A. Catalog additions (additive only)

- Skip reason `allowance_spent`, plus `no_plan` if section 2 step 3 shows it is needed.
- Error code constants used by the API: `invalid_state`, `allowance_spent`, `daily_cap_reached`, `invalid_time`, `already_learned`, `no_plan` (if used).
- `learningNote(string $ideaKind, float $completionPercent, int $views, int $follows): string`: builds the note using the bands (`>= HELD_AT_OR_ABOVE` "held", `< LEFT_EARLY_BELOW` "left early", otherwise "just recorded"). Plain sentence, one or two lines, mentions the idea kind in words, and ends with a short instruction the writer can use (for example "Lean into this kind of idea next." / "Try a faster hook or a different kind next."). Deterministic. State in a docblock that bands are a first cut, not a claim about the For You algorithm.
- `reschedule` limits: `MAX_RESCHEDULE_DAYS = 14`.

### B. `TikTokEngineService` additions

Add public methods. Each takes the acting `User` and a clip, and **re-verifies ownership** (clip's `user_id` equals the user) as a second line of defense behind the controller.

1. `confirm(User $user, TikTokClip $clip): TikTokClip`
   - In one DB transaction, lock the clip row (`lockForUpdate`) and validate against the fresh status.
   - **Idempotent:** if already `queued`, return it unchanged with no new timestamps and no error.
   - Only `waiting` may confirm. Anything else throws a typed exception that maps to `invalid_state`.
   - Check, in this order: allowance (4D), then daily cap (4E). Exclude the clip itself from both counts.
   - `transitionTo(queued)`, then set `package.publish` to `defaultPublish()` merged with `note` = exactly: `"Confirmed. We'll post once your video file is ready and your TikTok is connected."` and `confirmed_by = user id`. Keep `uploaded = false`.
   - Never calls any TikTok API.
2. `recordResults(User $user, TikTokClip $clip, array $data): array`
   - `$data`: `views` (int >= 0), `completion_percent` (0..100), `follows` (int >= 0).
   - Allowed from `waiting`, `queued`, `posted`. From `learned`, throw `already_learned`. From `drafted` or `discarded`, throw `invalid_state`.
   - In one transaction with a row lock: store the numbers, write `learning_note` via `TikTokCatalog::learningNote`, `transitionTo(learned)`.
   - **After the transaction commits**, call `run($user)` to prepare the next clip. A failure or skip in `run()` must **never** roll back or fail the results save. Catch, log a warning, and report it in the return value.
   - Return `['clip' => TikTokClip, 'next' => <run() result or null>]`.
3. `discard(User $user, TikTokClip $clip): TikTokClip`
   - Allowed from `drafted`, `waiting`, `queued`. Idempotent if already `discarded`. Otherwise `invalid_state`.
   - Because allowance is derived (4D), discarding a queued clip automatically frees its allowance. Add a docblock saying so.
   - Does **not** automatically generate a next clip. The user can call run.
4. `reschedule(User $user, TikTokClip $clip, CarbonInterface $at): TikTokClip`
   - Only from `waiting`. Status stays `waiting`. Only `suggested_for` changes.
   - Validation: `$at` must be at least `MIN_LEAD_MINUTES` from now and at most `MAX_RESCHEDULE_DAYS` ahead. Otherwise throw `invalid_time` with a clear message. Store in UTC.
   - Record in `package`: `rescheduled_by_user = true`.
5. Replace Phase 2's `withinAllowance()` seam so `run()` returns `skipped` with reason `allowance_spent` (or `no_plan`) when the month is spent. Keep `run()`'s other behavior unchanged.

Exceptions: add small typed exceptions (or one exception with a code) under `App\Exceptions`, reusing Phase 1's style. They carry an error code from section 4A and a human message. The controller maps them to HTTP responses; the service does not know about HTTP.

### C. `TikTokController` (thin)

Routes in `routes/api.php`, inside the same group and middleware as the YouTube routes (`auth:sanctum`, `customer`, `not_blocked`):

| Method | Path | Action |
|---|---|---|
| POST | `/api/tiktok/run` | Run the engine for the current user. Add a throttle (for example 6/min) |
| POST | `/api/tiktok/clips/{clip}/confirm` | Yes, post this |
| POST | `/api/tiktok/clips/{clip}/results` | Store results and learn |
| POST | `/api/tiktok/clips/{clip}/discard` | Skip this clip |
| POST | `/api/tiktok/clips/{clip}/reschedule` | Body: `suggested_for` (ISO 8601 with offset) |

Rules:
- **Scope route-model binding to the authenticated user.** Someone else's clip returns **404** (not 403, so existence is not leaked). Add a test for it.
- Validate with Form Requests. `results`: `views` integer min 0, `completion_percent` numeric 0..100, `follows` integer min 0, all required. `reschedule`: valid date with timezone offset.
- Controllers contain no business logic. They call the service and map results.
- Add `TikTokClipResource` that returns only: `id`, `status`, `status_label`, `topic`, `hook`, `script_lines` (array), `caption`, `on_screen_text`, `shots`, `sound_note`, `suggested_for` (ISO UTC), `timezone`, `timezone_assumed`, `score`, `views`, `completion_percent`, `follows`, `learning_note`, `confirmed_at`, `queued_at`, `posted_at`, `learned_at`, `publish_note`, and `blockers`. `blockers` is a derived array such as `["video_file", "tiktok_connection"]` computed from `render.ready` and whether an active TikTok connected account exists. It must be empty only when truly nothing is missing. **Do not expose** the raw `package`, internal ids, or model/prompt details.
- Response shapes:
  - Success: `{ "data": <clip resource> }`. For `run`, `{ "outcome": "created"|"skipped", "clip": <resource|null>, "reason": <code|null>, "message": <human text> }` (201 created, 200 skipped). For `results`, also include `next` with the same shape as `run`'s response, or null.
  - Errors: `{ "error": { "code": "<constant>", "message": "<plain words, says what to do next>" } }` with 409 for `invalid_state`/`already_learned`, 422 for validation and `invalid_time`, 429 for `allowance_spent`/`daily_cap_reached`. Messages are user-facing: for example "You've used all 8 clips for this month. Your allowance renews on <date>." and "You've reached today's limit of N clips. Try again tomorrow."
- Human messages for each `skipped` reason, in a catalog `skipMessage(string $reason): string` helper (for example `open_clip` → "Finish or skip your current clip first.").

### D. Allowance (derived, never stored)

Add a small `TikTokAllowanceService` in `app/Services/TikTok/`:

- `status(User $user): array` returns `['limit' => int, 'used' => int, 'remaining' => int, 'period_start' => ..., 'period_end' => ..., 'plan_found' => bool]`.
- **Used** = count of the user's clips with `queued_at` inside the current period and `discarded_at` null. A clip counts from the moment it is confirmed (queued) and keeps counting through `posted` and `learned`. Discarding frees it. Clips that never reached `queued` never count.
- Period and limit come from what you found in section 2 step 3. If there is no explicit period start, derive the period by stepping back one month from period end, and fall back to the calendar month (UTC) only if the user has no period at all. Document the choice in a docblock and in your report.
- `canQueue(User $user, ?TikTokClip $excluding = null): bool`.
- The engine reads this service. Billing tables are only **read**. Never written.

### E. Daily cap

- New admin platform setting `tiktok_daily_clip_cap`, default **3**, integer 1..50, wired exactly the way existing safety limits are (defaults, validation, admin settings API and UI schema if declared in a registry).
- Enforced at **confirm** only: count the user's clips with `confirmed_at` within the last rolling 24 hours (excluding the clip being confirmed and excluding discarded clips). If the count is at or above the cap, throw `daily_cap_reached`.
- This is separate from X's `rate_limit_budgets` and from the monthly allowance. Document that in a docblock: its purpose is to stop a burst of confirms from spending the month in an hour.

## 5. Tests

Use the repo's runner and isolated test DB. Add a billing helper that gives a user an active TikTok line with a chosen allowance and period. Use the same model-faking technique as Phase 2. Prevent stray HTTP requests.

**Confirm**
- `waiting → queued`, sets `confirmed_at` and `queued_at`, publish note is the exact string, `uploaded` stays false, `render.ready` stays false.
- Confirming twice is idempotent: same timestamps, 200, no second count.
- Two concurrent/overlapping confirms produce one transition and one stamp.
- From `drafted`, `learned`, `discarded`: 409 `invalid_state`.
- Allowance spent: 429 `allowance_spent`, clip stays `waiting`. Allowance with 1 remaining: succeeds, next confirm fails.
- Daily cap: the (cap+1)th confirm within 24 hours fails with `daily_cap_reached`. A confirm older than 24h does not count. Raising the platform setting lets it through.
- Another user's clip: 404. Unauthenticated: 401. Blocked user: rejected by `not_blocked`.

**Allowance**
- Counts queued, posted, and learned-after-queued clips. Does not count waiting or never-queued learned clips.
- Discarding a queued clip frees the allowance.
- Clips from the previous period do not count.
- Trial user resolves a line. User with no TikTok line behaves as decided (`no_plan` or as you and I agreed in the report).
- The X post counter and `rate_limit_budgets` are never read or written.

**Results**
- From `waiting`, `queued`, and `posted`: stores numbers, sets `learned`, writes the right band note (test ≥50, <30, and in between, including exact boundaries 50 and 30).
- Next clip: the chain `results → learned → new waiting clip` works and the next clip's writer receives the learning note.
- `run()` skipping or throwing does not fail the results call; the results are saved and `next` reports the skip.
- Second submission on a `learned` clip: 409 `already_learned`.
- Validation: negative views, 101%, non-numeric, and missing fields are 422.

**Discard and reschedule**
- Discard from `waiting` and `queued`. Idempotent. Illegal from `learned`/`posted`.
- Discarding a blocking clip lets `run()` create a new one.
- Reschedule: valid time updates `suggested_for` in UTC and status stays `waiting`. Too soon, in the past, beyond 14 days, malformed, and non-`waiting` clips are rejected. An offset like `+03:00` is converted correctly.

**Run endpoint**
- `created` returns 201 with a clip. Each skip reason returns 200 with a reason and a human message. Throttle applies.
- `run` returns `allowance_spent` when the month is used up.

**Resource**
- `blockers` contains `video_file` and `tiktok_connection` for a queued clip with no connection. The raw `package` is not exposed.

## 6. Manual check (report the output)

Provide and run a tinker or HTTP snippet on the **local dev database** for one demo user with a TikTok setup and a TikTok billing line: run → confirm → results → check the next clip appears. Show each response. Do not run destructive commands on the dev database. If no suitable demo user exists, say what you would create.

## 7. Quality bar

- Match existing style, run Pint if configured. Follow `strict_types` only if the repo does.
- Service holds logic, controller stays thin, Form Requests validate, Resource serializes.
- No magic strings outside `TikTokCatalog`.
- Comments only where intent is not obvious: derived allowance, the confirm check order, why `run()` after results is outside the transaction, and the rolling-24h cap.

## 8. Acceptance criteria

- [ ] The full chain `run → confirm → results → next clip` works through HTTP and is covered by tests.
- [ ] Confirm never uploads, never sets `render.ready` or `uploaded` true, and is idempotent under double-click and concurrency.
- [ ] Allowance is derived by query, enforced at confirm and in `run()`, freed by discard, and no billing table is written.
- [ ] `tiktok_daily_clip_cap` exists, is admin-editable, and is enforced at confirm.
- [ ] Other users' clips return 404.
- [ ] No scheduler, command, `runAll()`, frontend, `AccountSetupController`, `DashboardController`, X, `posts`, or `rate_limit_budgets` changes.
- [ ] New tests pass and the existing suite is unaffected.

## 9. When you finish, report back with

1. Files created or changed.
2. The answers to the four billing questions in section 2 step 3, and how you derived the period.
3. How `no_plan` (no TikTok line) behaves and why.
4. Any difference between this brief and the repo, and what you chose.
5. Test summary and the manual-check output.
6. Open questions for Phase 4. In particular: which fields the dashboard payload still needs that the resource does not provide, and whether a user timezone is actually captured anywhere yet.

If you hit ambiguity at any step, **stop and ask** instead of inventing a convention.