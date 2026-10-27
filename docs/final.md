# Phase 5 (final): Scheduler, ops, TikTok screens, hardening, and visual polish

You are a senior full-stack engineer and senior UI/UX engineer working in the Mackie17 repo (Laravel app "Xpulse", Next.js app in `apps/web`). Phases 1-4 are merged: data layer, engine, confirm/results/discard/reschedule API, allowance and daily cap, and the TikTok desk with timezone capture. This is the **last buildable phase**. It finishes the product without TikTok OAuth, render, or upload (those are blocked on TikTok approval and are out of scope).

Read `docs/SYSTEM.md` first, then the Phase 1-4 prompts in `docs/prompts/`.

## 0. Prerequisite: run the real thing

Phases 3 and 4 were never verified in a browser. Before any code:

1. Bring up the stack: `docker compose up -d`, API (`php artisan serve`), web (`npm run dev`). Postgres is on host port 5433.
2. Open `/dashboard/tiktok` at 360px and at desktop width, in light and dark if both exist. Capture screenshots of what is there now.
3. Walk the loop once: find idea → change time → confirm → add results → next clip. **Fix any bugs you find first**, and list them in your report.
4. If the stack cannot run, say so plainly, do the work using the dev page `/dev/tiktok-states` and tests, and do not claim anything was verified visually.

Never run `migrate:fresh` or any destructive command on the dev database. Do not create users. Add a demo billing line only if setup exists and the line does not.

## 1. Hard boundaries

- No TikTok OAuth, render, upload, or `connected_accounts` changes. Never set `render.ready` or `uploaded` true.
- No writes to `posts`, `rate_limit_budgets`, or X/YouTube logic. `/dashboard/x` and `/dashboard/youtube` look and behave exactly as before.
- Shared UI primitives may only change in backward-compatible ways. Prefer TikTok-local styling.
- No new dependencies, no new platform settings, no new database tables.
- Keep every honesty-critical string from Phase 4 section B4 exactly as written. Polish must not change what the UI claims.
- Nothing posts without an explicit confirm. No automated likes, follows, comments, or DMs.
- If the repo contradicts this brief, stop and ask.

## 2. Part A: Scheduler and commands (backend)

1. `TikTokEngineService::runAll(): array`
   - Eligible users: not blocked, onboarding complete, completed TikTok `platform_setups` row. Query them in chunks.
   - Per user, call `run()` inside try/catch. One user's failure is logged with `user_id` and never stops the loop.
   - Guards must run **before** any model call, so users with an open clip or no allowance cost nothing.
   - Return a summary: counts of `created` and `skipped` per reason, and `failed`.
2. `routes/console.php`: schedule `tiktok-engine` hourly with `withoutOverlapping`, matching the YouTube entry's style (name, overlap window, in-process like the others, not dependent on a queue worker).
3. `php artisan tiktok:run` mirrors `youtube:run`. Optional `--user=ID` for one user. Prints the summary table.
4. `TikTokEngineService::diagnose(User $user): array` is a **read-only** check that returns the reason a new clip would not be made right now (same guards, same catalog `skipMessage`), or `can_run: true`. It must not call the model or write anything.

## 3. Part B: Admin operations

In the existing Admin → Operations area, add a TikTok section:
- Clip counts per status, and clips created in the last 24h and 7d.
- "Needs attention": clips `waiting` for more than 7 days, or `queued` for more than 14 days, with user email and age. No automatic discards.
- A per-user lookup that shows `diagnose()` output ("why didn't this user get a clip?").
- A "Run engine now" button that calls `runAll()` and shows the summary.
- Admin routes follow the existing `/api/admin/*` pattern and `admin` middleware. Match the existing Operations UI style.

## 4. Part C: Ideas and What landed screens (TikTok only)

`/trends` and `/analytics` still show X content under TikTok labels. For `platform = tiktok` only, render TikTok versions and leave X and YouTube behavior untouched.

Backend, read-only, user-scoped, behind the same middleware as other TikTok routes:
- `GET /api/tiktok/ideas`: top 10 from `TikTokDemandService::ranked`, shape as in the desk (`kind`, `kind_label`, `topic`, `angle`, `reason`). **No numeric scores.**
- `GET /api/tiktok/clips?status=learned&page=N`: paginated clip history using `TikTokClipResource`, newest first, 10 per page. Default is all non-discarded statuses.
- Another user's data never appears. The GET endpoints have no side effects.

Frontend:
- **Ideas** (`/trends` on TikTok): ranked list with kind chip, topic, angle, and the plain-words reason. Header explains: "These are ideas that fit your niche and goals. The next clip uses the top one." No per-idea action (there is still no idea-picking endpoint). Include goals as small chips so the user sees why the ranking looks the way it does.
- **What landed** (`/analytics` on TikTok): summary row (clips learned, average % watched, total views, total follows) computed from the history, then a clip list showing hook, kind, views, % watched (small bar plus number), follows, and the learning note. Honest empty state: "No results yet. After you post a clip and add how it did, it shows up here." State that numbers are entered by the user, not pulled from TikTok.
- Sidebar labels already exist. Do not rename them.

## 5. Part D: Hardening

1. **Stale suggested time.** If a `waiting` clip's `suggested_for` has passed, the desk shows a notice ("This time has passed. Pick a new one.") and opens the Change time control. The confirm label becomes "Yes, go ahead" without a time. Backend confirm still works, and the time is not required to be future for queueing.
2. **Idempotency and concurrency.** Add or confirm tests for: double confirm, confirm racing discard, results racing discard, `run` racing `run`, and `runAll` overlapping a manual run for one user. Exactly one outcome each, no double stamps, no duplicate clips.
3. **Allowance edge cases.** Test period rollover (a clip queued last period does not count this period), yearly slices, trial expiry mid-clip (queued clips stay, new runs return `no_plan`), and a canceled plan before and after its period end.
4. **Blocked mid-flight.** A user blocked after a clip is waiting: `confirm` and `run` are rejected by middleware, and `runAll` skips them.
5. **Prompt-injection regression.** A test that a description containing instructions stays inside the data block of the script prompt.
6. **Privacy.** Audit that no TikTok API response or log line contains the raw `package`, prompts, or another user's data. Report what you checked.
7. **Compliance checklist.** Add a short section to `docs/SYSTEM.md` mapping each section 12 boundary to the code or test that enforces it.
8. **Docs.** Update `docs/SYSTEM.md` so sections 4, 8, 13, 16, 17, 18, and 19 match reality (TikTok now has an engine, clip table, routes, schedule entry, command, and desk; OAuth, render, and upload are still pending). Add a one-line note to `docs/DEVELOPMENT_PLAN.md` that its Phase 6 TikTok line is stale. Do not rewrite the stale docs.

## 6. Part E: Visual refinement (TikTok desk, Ideas, What landed)

Goal: neat, calm, and clearly part of Mackie17. Look at the existing X desk for the baseline: dark surfaces, 1px subtle borders, rounded cards, small muted labels above larger values, a header with title, one-line subtitle, and actions on the right. Reuse the existing design tokens and Tailwind conventions. Do not invent a new palette or add fonts.

Specifics:
- **Hierarchy.** The current clip is the hero: one large card, the hook as the biggest text on the page, everything else clearly secondary. Pipeline strip is compact and sits above it.
- **Layout.** Desktop: clip card (about two thirds) beside a right column with allowance, daily cap, timezone, and ideas. Mobile: single column, clip first, supporting cards below. Equal-height cards in a row. A consistent spacing scale (pick one and use it everywhere). Consistent radius and border treatment with the X desk.
- **Clip card.** Sections with small uppercase or muted labels (Hook, Script, Caption, On screen, Shots, Sound). Script as numbered lines. Caption in a subtle inset block with the hashtags visually distinct. Shots as a tidy list. Long content collapses behind "Show full script" rather than making a tall wall of text. Copy buttons are icon buttons with tooltips and a "Copied" state.
- **Status.** One status chip per state with an icon and text (waiting, confirmed, posted, results in), never color alone. Confirmed uses a calm neutral or success tone, never a "live" look.
- **Pipeline strip.** A horizontal stepper with the current step emphasized, counts under labels, and a scrollable or wrapped layout at 360px with no horizontal page scroll.
- **Allowance and cap.** Small meters (progress bars with numbers and text) and the renew date in muted text.
- **Checklist (queued).** Clean list with an icon per item (pending vs done), and the explanatory line beneath.
- **Empty and blocked states.** An icon from the existing icon set, a heading, one sentence, one action. No large illustrations.
- **Sticky confirm bar (mobile).** Elevated, blurred or solid background consistent with the theme, safe-area padding, no overlap with content (add bottom padding to the page so the last card is reachable).
- **Motion.** Subtle only: short fades and a skeleton shimmer. All of it disabled under `prefers-reduced-motion`.
- **Polish checklist.** Consistent type scale, no orphan single-word wraps in headings, no layout shift when data loads (skeletons match final sizes), focus rings visible on dark and light, contrast at least 4.5:1 for body text and 3:1 for UI, 44px touch targets.

After the pass, capture before and after screenshots at 360px, 768px, and 1280px, in dark and light if both exist, for: empty, waiting, confirmed, learned, blocked, and the Ideas and What landed pages. Update `/dev/tiktok-states` so every state is still reviewable.

## 7. Tests and checks

- Backend: `runAll` (eligibility, per-user isolation, summary, guards before model call, blocked and un-onboarded skipped), `tiktok:run` and `--user`, scheduler entry exists with `withoutOverlapping`, `diagnose` has no side effects, admin endpoints require admin, the new GET endpoints (scoping, pagination, no scores, no side effects), and the hardening cases in Part D. Use the same model-faking technique and prevent stray HTTP.
- Frontend: no test runner exists, so do not add one. Run `tsc --noEmit`, the linter, and `next build`, and keep the dev states page current.
- Existing suite must stay green.

## 8. Acceptance criteria

- [ ] Hourly `tiktok-engine` is scheduled, `tiktok:run` works, and `runAll` isolates failures and costs nothing for users who cannot get a clip.
- [ ] Admin can see TikTok counts, stuck clips, the reason a user has no clip, and run the engine.
- [ ] TikTok Ideas and What landed screens show real data, with no numeric scores, and X/YouTube versions are unchanged.
- [ ] All hardening tests pass. `SYSTEM.md` matches the code and maps each boundary to enforcement.
- [ ] The desk looks consistent with the X desk, works at 360px, and no honesty-critical copy changed.
- [ ] Real browser walkthrough done with screenshots, or an explicit statement that the stack could not run.
- [ ] No OAuth, render, upload, `posts`, `rate_limit_budgets`, X, or YouTube changes. No new dependencies, settings, or tables.

## 9. When you finish, report back with

1. Files created or changed.
2. Bugs found in the Phase 3-4 walkthrough and how you fixed them.
3. Differences between this brief and the repo, and what you chose.
4. Test, type-check, lint, and build results.
5. Before and after screenshots, or the reason they could not be taken.
6. What remains for the externally blocked phase (TikTok OAuth, render, upload) and the exact seams where it plugs in.

If you hit ambiguity at any step, stop and ask instead of inventing a convention.