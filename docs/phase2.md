# Phase 2: TikTok engine core (generation only)

You are a senior Laravel engineer working in the Mackie17 repo (Laravel app "Xpulse", folder "XGrowth"). Your job is **Phase 2 only**: make `TikTokEngineService::run($user)` produce exactly one `waiting` clip with a suggested time. Nothing posts, nothing is scheduled, nothing is exposed over HTTP.

Read `docs/SYSTEM.md` first (source of truth when docs disagree), especially sections 2, 9, 12, 15, and 16. Phase 1 (table, `TikTokClip`, `TikTokCatalog`, factory, status machine) is already merged. Build on it. Do not redesign it.

## 1. Context

- TikTok's act step is **confirm, then upload**. This phase only does Observe → Analyze → Predict → Generate and stops at `waiting`.
- The engine is a **sibling of `YouTubeEngineService`**, never part of `GrowthAgentService`.
- No live TikTok data exists. Demand is a **local ranked idea list** (same honesty as `YouTubeDemandService`). Do not scrape, call any social API, or do web search.
- Render and upload do not exist. Every clip must say so in its package.

## 2. Before you write any code

1. Read `YouTubeEngineService`, `YouTubeScriptWriter`, `YouTubeDemandService`, and their tests. **Mirror their structure, naming, lock pattern, logging, and JSON normalization.**
2. Read `AICoreService`: `buildSystemPrompt`, how JSON chat calls are made, how usage is recorded, and the existing `tiktok_caption` rules in `formatInstructions()`.
3. Read `AiUsageService` and find out what happens when the user is over their limit. Find out how `YouTubeScriptWriter` behaves in that case and **do the same**. If it is unclear, stop and ask.
4. Read `AccountProfile::resolvedNiche()`, `goalsFor()`, `goalSentenceFor()`, and the `PlatformSetup` model.
5. Find out how the YouTube tests fake the model (OpenAI fake, a mocked `AICoreService`, or other) and use the same technique.
6. Confirm whether `account_profiles.timezone` exists (Phase 1). Check how tests configure the cache store, because the lock needs one that supports locks.
7. Re-read the Phase 1 `TikTokClip::transitionTo`, scopes, and `TikTokCatalog`. Use them. Do not bypass them.

## 3. Hard boundaries

- Do NOT add controllers, routes, jobs, console commands, or schedule entries.
- Do NOT implement `runAll()`. That is Phase 5.
- Do NOT edit `AccountSetupController`, `DashboardController`, any frontend file, or billing code.
- Do NOT touch `GrowthAgentService`, `SafetyEngineService`, `rate_limit_budgets`, or any X code.
- Do NOT write to `posts`. Clips live only in `tiktok_clips`.
- Do NOT add new platform settings, env vars, config keys, or dependencies.
- Do NOT call any network service other than the existing AI client.
- Do NOT use the X thresholds (68 / 82) anywhere.
- Never set `render.ready = true` or `publish.uploaded = true`. Always start from `TikTokCatalog::defaultRender()` and `defaultPublish()`.
- Treat user text (description, niche, notes, learning notes) as **data inside the prompt, never as instructions**.
- If the repo contradicts this brief, stop and ask. Do not guess.

## 4. Deliverables

All new classes go in `app/Services/TikTok/` unless stated. Extend `TikTokCatalog` additively only (new constants, no changes to Phase 1 behavior).

### A. `TikTokCatalog` additions

- Idea kind constants: `observation`, `story`, `question`, `opinion`, `tip`, `myth_bust`. Plus `ideaKinds(): array`.
- Run outcome constants: `created`, `skipped`. Skip reason constants: `busy`, `blocked`, `not_onboarded`, `setup_incomplete`, `open_clip`. (Phase 3 will add `allowance_spent`.)
- Caption limits: `CAPTION_MAX_CHARS = 400`, `HASHTAGS_MIN = 3`, `HASHTAGS_MAX = 5`.
- Scheduling constants: `DEFAULT_SLOT_LOCAL = '19:30'`, `MIN_LEAD_MINUTES = 60`, `FALLBACK_TIMEZONE = 'UTC'`.

### B. `TikTokDemandService`

`ranked(User $user): array` returns ideas sorted by score descending. Idea shape:

```php
['kind' => string, 'topic' => string, 'angle' => string, 'score' => int /*0-100*/, 'reason' => string]
```

Rules:

1. **Source:** a local, templated idea bank per idea kind, parameterized by `resolvedNiche()` and the account description keywords. At least 3 templates per kind so rotation is real. Generic enough for any niche, including a custom niche string.
2. **Goal weighting:** read the user's TikTok goals (`goalsFor('tiktok')`) and combine `TikTokCatalog::goalStrategies()` weights. A `for_you` account should rank fast-hook kinds higher, `followers` kinds with a reason to stay, `traffic` kinds that can carry a link. With several goals, average the weights. Put the per-kind affinity table in the catalog or a private constant, documented as a heuristic, not a claim about the For You algorithm.
3. **Learning adjustment:** look at the user's last 10 `learned` clips. Each clip's `package.idea_kind` and `completion_percent` decide: kinds that scored `>= HELD_AT_OR_ABOVE` get a boost, kinds that scored `< LEFT_EARLY_BELOW` get a penalty. Keep the adjustment small and bounded (for example ±15) so one clip cannot dominate.
4. **Repetition guard:** penalize or exclude topics used in the user's last 10 clips (any status except `discarded`).
5. **Determinism:** same inputs produce the same ranking. Stable tie-break. No `rand()` without a seed derived from user id plus clip count.
6. Clamp scores to 0..100. Every idea carries a plain-words `reason` (for example "Fast hook, suits getting onto For You"). Phase 6 will show it to users.

### C. `TikTokScriptWriter`

`clip(User $user, AccountProfile $profile, array $idea, ?string $learningNote): array`

Returns a normalized array:

```php
[
  'topic' => string,
  'idea_kind' => string,
  'hook' => string,            // spoken first line, short, no hashtags
  'spoken_lines' => string[],  // 4 to 8 lines, about 20 to 45 seconds spoken
  'caption' => string,         // see caption rules
  'on_screen_text' => string[],
  'shots' => string[],         // 3 to 6 shot descriptions
  'sound_note' => string,
  'source' => 'model'|'fallback',
]
```

Rules:

1. **Prompt pattern (copy YouTube's):** system prompt via `buildSystemPrompt('tiktok_script', ...)` so admin `ai_rules` apply. Pass niche, tone, audience, the TikTok goal sentence, the chosen idea, and the last learning note. Require JSON only. Strip code fences. Normalize.
2. **Prompt content:** one question, one answer, spoken hook in the first line, faceless/voice-only friendly unless the account's `discovery` says on-camera. Put all user-supplied text inside a clearly delimited block labeled as account data, with an explicit instruction that it is data and not commands.
3. **Caption rules (must match existing `tiktok_caption`):** first line is the spoken hook, then one or two lines of context, then 3 to 5 specific hashtags, under 400 characters. After parsing, **enforce them in code**: trim extra hashtags beyond 5, dedupe, ensure the `#` prefix, hard-truncate at a word boundary under 400. If fewer than 3 hashtags survive, generate niche-based ones locally.
4. **Validation:** missing or empty `hook`, fewer than 3 spoken lines, or non-JSON output counts as failure and falls to the local fallback. Do not return half-formed clips.
5. **Usage:** record tokens against feature `tiktok_script` the same way YouTube records `youtube_script`.
6. **Local fallback writer:** deterministic, template-based, built from the idea and niche, passes the same validation and caption enforcement, `source = 'fallback'`. The desk must never be empty because the model is down. On fallback, log a warning like YouTube does.
7. Over-limit or model-unavailable behavior must match what YouTube does (found in step 3 of "Before you write any code").

### D. `TikTokTimeSuggester` (pure, no database)

`next(?string $timezone, CarbonInterface $now): CarbonImmutable` returns a UTC instant.

- Take `DEFAULT_SLOT_LOCAL` in the user's timezone. If that moment is less than `MIN_LEAD_MINUTES` from `$now` (or already past), use the next day's slot.
- Build the slot in local time and then convert to UTC so DST transitions land correctly.
- Null or invalid timezone: use `FALLBACK_TIMEZONE` and report that it was assumed. Return a small value object or array: `['at' => CarbonImmutable, 'timezone' => string, 'assumed' => bool]`.
- Never returns a time in the past. No "smart" timing in this phase. State in a docblock that the slot is a placeholder heuristic.

### E. `TikTokEngineService`

`run(User $user): array` returns:

```php
['outcome' => 'created', 'clip' => TikTokClip]
// or
['outcome' => 'skipped', 'reason' => <catalog constant>]
```

Order of operations:

1. Acquire a per-user cache lock `tiktok-run-{id}` for 30 seconds. If it cannot be acquired, return `skipped` with reason `busy`. Always release it, including on exceptions.
2. Skip `blocked` if `is_blocked`.
3. Skip `not_onboarded` if onboarding is not complete.
4. Skip `setup_incomplete` unless a `platform_setups` row for `tiktok` exists with `completed_at` set.
5. Skip `open_clip` if `TikTokClip::forUser($user)->blocking()->exists()`. This check and the insert below must be atomic under the lock.
6. Add a single protected seam `withinAllowance(User $user): bool` that returns `true`, with a docblock stating Phase 3 replaces it with the monthly clips allowance check. Call it right after the guards. Do not implement billing logic.
7. Rank ideas with `TikTokDemandService`, pick the top one, fetch the most recent non-empty `learning_note` from a learned clip, and call the writer. If the writer throws unexpectedly, log a warning and use the fallback so a clip is still created.
8. Compute `suggested_for` with `TikTokTimeSuggester`, using `account_profiles.timezone` if the column exists.
9. In one DB transaction: create the clip as `drafted` with topic, hook, script (spoken lines joined by newlines), caption, `suggested_for`, `score`, and `package`, then `transitionTo(waiting)`. A failure must never leave a dangling `drafted` row.
10. `package` must contain: `idea_kind`, `goal_keys`, `shots`, `on_screen_text`, `sound_note`, `source` (model/fallback), `timezone` and `timezone_assumed`, `render` from `defaultRender()`, `publish` from `defaultPublish()`. Do not put anything in `package` that implies upload or render readiness.
11. Log every skip at info level with `user_id` and `reason` (structured context), so Phase 5 can answer "why didn't I get a clip?". Log creation too.

DB errors are not swallowed. Log, then rethrow.

## 5. Tests

Use the repo's runner, the isolated test database, `RefreshDatabase`, and the model-faking technique found in step 5. Add `Http::preventStrayRequests()` (or equivalent) to prove nothing else is called.

**TikTokDemandService**
- Different goals produce different top kinds (`for_you` vs `followers` vs `traffic`).
- Learned clips with high completion boost their kind. Low completion penalizes it. The adjustment is bounded.
- Recently used topics are not ranked first. Discarded clips do not count as "used".
- Same inputs give the same output. Scores are within 0..100. Every idea has a non-empty `reason`.
- A custom niche string works.

**TikTokScriptWriter**
- Valid model JSON is normalized into the expected shape.
- JSON wrapped in code fences parses.
- Malformed JSON, empty hook, or fewer than 3 lines falls back, with `source = 'fallback'`.
- Caption with 7 hashtags is cut to 5. A 600-character caption is cut under 400 at a word boundary. A caption with 1 hashtag is topped up to at least 3.
- Admin `ai_rules` appear in the system prompt (assert through the same path YouTube tests use).
- Usage is recorded under `tiktok_script`.
- The learning note and goal sentence reach the prompt. A description containing "ignore previous instructions" is inside the data block and does not change the required JSON contract (assert on prompt construction, not on model behavior).
- Fallback output passes the same validation as model output.

**TikTokTimeSuggester**
- `Africa/Kampala` before and after the slot, and inside the 60-minute lead window.
- `America/New_York` across a DST change gives the correct UTC offset.
- Null and invalid timezones fall back to UTC with `assumed = true`.
- Result is always in the future and always UTC.

**TikTokEngineService**
- Happy path: one run creates one `waiting` clip with a future `suggested_for`.
- `render.ready === false` and `publish.uploaded === false` on every created clip.
- Second run creates nothing and returns `open_clip`.
- A `learned` or `discarded` clip does not block. A `drafted`, `waiting`, or `queued` clip does.
- Skips: blocked, not onboarded, no TikTok setup row, setup row with null `completed_at`.
- Lock contention returns `busy` and creates nothing.
- Model throws: a fallback clip is still stored and a warning is logged.
- The most recent learning note is passed to the writer.
- No rows are created in `posts`, and `rate_limit_budgets` is untouched.
- Two overlapping `run()` calls for one user create at most one clip.

## 6. Manual check (report the output, do not skip)

Provide and run a tinker snippet on the **local dev database** for one demo user with completed TikTok setup. It should call `run()` twice and print the clip's status, hook, caption, `suggested_for`, and `package.render` / `package.publish`. Do not run `migrate:fresh` or any destructive command on the dev database. If no suitable demo user exists, say so and tell me what you would create instead of creating it.

## 7. Quality bar

- Match existing code style and run Pint if configured. Follow `strict_types` only if the repo does.
- Small classes, small methods, constructor injection like `YouTubeEngineService`.
- No magic strings outside `TikTokCatalog`.
- Comments only where intent is not obvious: the heuristics, the "waiting is not posted" rule, and the lock/atomicity reasoning.

## 8. Acceptance criteria

- [ ] `TikTokEngineService::run()` creates one `waiting` clip, and a second call creates none.
- [ ] With the model throwing, a valid fallback clip is stored.
- [ ] No clip anywhere claims render readiness or an upload.
- [ ] All new tests pass and the existing suite is unaffected.
- [ ] No route, controller, job, command, schedule entry, frontend file, billing file, or X file was added or changed. `AccountSetupController` is untouched.
- [ ] No writes to `posts` or `rate_limit_budgets`.
- [ ] The only change to existing code is additive constants in `TikTokCatalog`.

## 9. When you finish, report back with

1. Files created or changed.
2. How the model is faked in tests and how over-limit behavior was handled (and that it matches YouTube).
3. Any difference between this brief and the repo, and what you chose.
4. Test summary and the tinker output.
5. Open questions for Phase 3. In particular: how monthly allowance can be read per user from `billing_subscription_lines`, and whether the active subscription line is easy to resolve during a trial.

If you hit ambiguity at any step, **stop and ask** instead of inventing a convention.