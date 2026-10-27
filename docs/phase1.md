# Phase 1: TikTok data layer and catalog

You are a senior Laravel engineer working in the Mackie17 repo (Laravel app name "Xpulse", folder "XGrowth"). Your job is **Phase 1 only**: the schema, model, catalog, factory, and tests for a TikTok clip pipeline. No behavior, no endpoints, no UI, no scheduler.

Read `docs/SYSTEM.md` first (it is the source of truth when docs disagree), especially sections 2, 7, 12, and 16.

## 1. Context in five lines

- TikTok currently has a product contract, a desk, billing lines, and caption drafts in `posts`. It has **no engine, no clip table, no commands**.
- We are building a TikTok engine as a **sibling of the YouTube module**, not inside the X `GrowthAgentService`.
- TikTok's publish rule is stricter than YouTube's: **nothing is uploaded until the user explicitly confirms that clip**, on every tier.
- Later phases will add the engine, controller, desk UI, scheduler, and OAuth. This phase gives them a safe foundation.
- Backend: Laravel 12, PHP 8.2+, PostgreSQL 16 (Docker, host port **5433**), Redis, Sanctum.

## 2. Before you write any code

Do these and keep what you learn in mind. Do not skip them.

1. Read the `youtube_videos` and `youtube_series` migrations, the `YouTubeVideo` model, and `app/Support/YouTubeCatalog.php`. **Mirror their conventions** (naming, casts, how status constants and labels are exposed, how relations are declared on `User`).
2. Check whether `account_profiles` already has a timezone column. If it does, reuse it and skip migration B below.
3. Check which test runner is in use (Pest or PHPUnit) and what `phpunit.xml` points the test database at. **Never run tests or `migrate:fresh` against the dev database.** If tests are not isolated from dev data, stop and tell me.
4. Check whether `database/factories` has a `YouTubeVideoFactory` to copy the style from.

## 3. Hard boundaries (do not violate)

- Do NOT create controllers, routes, services, jobs, commands, or schedule entries.
- Do NOT touch `GrowthAgentService`, `SafetyEngineService`, `rate_limit_budgets`, or any X code.
- Do NOT write to or migrate the `posts` table. TikTok clips are **not** stored in `posts`.
- Do NOT modify frontend files.
- Do NOT add TikTok OAuth or any `connected_accounts` changes.
- Do NOT use a database ENUM for status (strings only, so the machine can evolve).
- Do NOT add dependencies.
- If something in the repo contradicts this brief, stop and ask. Do not guess.

## 4. Deliverables

### A. Migration: `create_tiktok_clips_table`

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `user_id` | foreignId | constrained, `cascadeOnDelete` |
| `status` | string(24) | default `drafted` |
| `topic` | string | nullable |
| `hook` | text | nullable. The spoken first line |
| `script` | text | nullable. Spoken lines, newline separated |
| `caption` | text | nullable. Must fit the existing `tiktok_caption` rules (enforced later, not here) |
| `suggested_for` | timestampTz | nullable. **Always stored in UTC** |
| `package` | jsonb | nullable. Shots, on-screen text, sound note, `render` block, `publish` block |
| `score` | unsignedSmallInteger | nullable |
| `views` | unsignedBigInteger | nullable |
| `completion_percent` | decimal(5,2) | nullable. 0 to 100 |
| `follows` | unsignedInteger | nullable |
| `learning_note` | text | nullable |
| `learned_at` | timestampTz | nullable |
| `confirmed_at` | timestampTz | nullable |
| `queued_at` | timestampTz | nullable |
| `posted_at` | timestampTz | nullable |
| `discarded_at` | timestampTz | nullable |
| `platform_post_id` | string | nullable |
| `created_at` / `updated_at` | timestamps | |

Indexes: `(user_id, status)`, `(user_id, created_at)`, and a plain index on `platform_post_id`.

`down()` must drop the table cleanly.

### B. Migration (only if no timezone column exists): `add_timezone_to_account_profiles_table`

- Add nullable `timezone` string(64) to `account_profiles`. IANA name (for example `Africa/Kampala`). No default. No backfill.
- Reason: a "suggested time" is meaningless without the user's zone. Capturing it in the UI is a later phase. This phase only makes room for it.
- Keep it in its own migration so it can be reverted independently.

### C. `app/Support/TikTokCatalog.php`

Static, dependency-free, mirrors `YouTubeCatalog`. It must expose:

- Status constants: `drafted`, `waiting`, `queued`, `posted`, `learned`, `discarded`.
- `statuses(): array` and `label(string $status): string` with user-facing labels in plain words (for example `waiting` → "Waiting for your yes", `queued` → "Confirmed, not posted yet", `posted` → "Posted", `learned` → "Results in", `discarded` → "Skipped"). **Never label `queued` as "Posted" or "Scheduled".** The label must not imply an upload happened.
- `transitions(): array`: the allowed-next-states map (section 5).
- `goalStrategies(): array` keyed by the five TikTok goal keys (`for_you`, `followers`, `traffic`, `promote_business`, `monetize`). Each value is a small array: `label`, `prefers` (short string such as "fast hook"), and `weights` (`hook_speed`, `reason_to_stay`, `link_fit`, each a float 0 to 1). Use these meanings: `for_you` favors a fast hook, `followers` favors a reason to stay, `traffic` favors a clip that can carry a link. `promote_business` and `monetize` get sensible, documented values. These are **first-cut heuristics, not claims about the For You algorithm**. Say so in a docblock.
- Learning band constants: `HELD_AT_OR_ABOVE = 50`, `LEFT_EARLY_BELOW = 30` (same bands as YouTube, as a first cut).
- Render and publish default blocks as helper methods: `defaultRender()` returns `['provider' => 'pending', 'ready' => false]` and `defaultPublish()` returns `['mode' => 'confirm', 'uploaded' => false, 'note' => null]`. Later code must start from these so no clip can claim `ready: true` or `uploaded: true` by accident.

### D. `app/Models/TikTokClip.php`

- Table `tiktok_clips`. Explicit `$fillable` (no `$guarded = []`).
- Casts: `package` => array, all `*_at` and `suggested_for` => datetime, `completion_percent` => decimal:2, `views`/`follows`/`score` => integer.
- Relation: `user(): BelongsTo`. Add `tiktokClips(): HasMany` on `User`, in the same style as the YouTube relation.
- Scopes:
  - `forUser($query, $user)`
  - `open()`: status in `waiting`, `queued`
  - `blocking()`: status not in `learned`, `discarded` (this is the open-item guard the engine will use: no new clip while one is unlearned and not discarded)
  - `withStatus($query, string ...$statuses)`
- `transitionTo(string $to): static` (see section 5). It validates against `TikTokCatalog::transitions()`, sets the matching timestamp, saves, and returns `$this`. On an illegal move it throws a dedicated exception (for example `App\Exceptions\InvalidTikTokTransition`) with a message naming both states.
- `canTransitionTo(string $to): bool`.
- Helpers: `isOpen()`, `isTerminal()`, `statusLabel()`.
- A `package` accessor or helper that returns the render and publish blocks **with defaults filled in**, so callers never read a missing key.

### E. `database/factories/TikTokClipFactory.php`

- Default state: a valid `waiting` clip with realistic hook, script, caption (under 400 chars, 3 to 5 hashtags), `suggested_for` in the future, package using `TikTokCatalog::defaultRender()` and `defaultPublish()`.
- Named states: `drafted()`, `waiting()`, `queued()`, `posted()`, `learned()`, `discarded()`. **Each state must set the timestamps that status implies** (for example `queued` sets `confirmed_at` and `queued_at`) so tests never start from an impossible row.

### F. Tests

Match the existing test style. Use the isolated test database with `RefreshDatabase`.

1. **Factory validity**: each named state persists and has the timestamps for its status.
2. **Every legal transition** succeeds and stamps the right timestamp.
3. **Every illegal transition** throws, including these explicitly: `waiting → posted`, `drafted → queued`, `learned → waiting`, `discarded → waiting`, `posted → queued`, and any transition to the current state.
4. **Scopes**: `open()`, `blocking()`, `forUser()` return the right rows for a mixed set across two users.
5. **Defaults**: a clip built with no package still returns `render.ready === false` and `publish.uploaded === false`.
6. **Cascade**: deleting a user deletes their clips.
7. **Migration reversibility**: `migrate:rollback` for these migrations works (run locally; document the result, no test needed).

## 5. The status machine (exact)

```
drafted   → waiting | discarded
waiting   → queued | learned | discarded
queued    → posted | learned | discarded
posted    → learned
learned   → (terminal)
discarded → (terminal)
```

Notes for your implementation:

- `waiting → learned` and `queued → learned` exist because results can be entered before any upload path exists (same as YouTube's `recordResults` from `waiting` or `queued`).
- Timestamp stamping: `queued` sets `confirmed_at` and `queued_at` (both, now); `posted` sets `posted_at`; `learned` sets `learned_at`; `discarded` sets `discarded_at`. Never overwrite an existing timestamp.
- Reschedule is **not** a status change. It will later just update `suggested_for` while staying `waiting`. Do not model it here.
- `transitionTo` must be safe under concurrency: inside a transaction, re-read the row with `lockForUpdate()` and validate against the **fresh** status, not the in-memory one. (A double-clicked confirm must not double-stamp or double-count later.)

## 6. Quality bar

- `declare(strict_types=1)` only if the repo already uses it. Match existing style and formatting (run Pint if configured).
- Small methods, no clever abstractions, docblocks only where intent is not obvious (the heuristics and the "queued is not posted" rule deserve one).
- No magic strings outside `TikTokCatalog`. Everything else references its constants.
- No new config keys, platform settings, or env vars in this phase.

## 7. Acceptance criteria (all must be true)

- [ ] `php artisan migrate` succeeds against the Docker Postgres on port 5433, and `migrate:rollback` cleanly reverses both migrations.
- [ ] The full new test suite passes, and the existing suite is unaffected.
- [ ] `TikTokClip::factory()->create()` produces a valid row.
- [ ] No file outside `database/migrations`, `database/factories`, `app/Models`, `app/Support`, `app/Exceptions`, and `tests` is changed, except the one-line `User` relation.
- [ ] No route, controller, service, job, command, or frontend file was added or changed.
- [ ] No catalog label or default anywhere implies a clip is uploaded or ready before that is true.

## 8. When you finish, report back with

1. The list of files created or changed.
2. Anything in the repo that differed from this brief, and what you chose (and why).
3. Test output summary and the rollback result.
4. Open questions for Phase 2. In particular, tell me which test runner and which `User` relation style you found.

If you hit ambiguity at any step, **stop and ask** instead of inventing a convention.