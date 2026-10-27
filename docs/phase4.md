# Phase 4: TikTok desk (dashboard payload, setup wiring, UI)

You are a senior full-stack engineer and senior UI/UX engineer working in the Mackie17 repo (Laravel app "Xpulse", folder "XGrowth", Next.js app in `apps/web`). Your job is **Phase 4 only**: make the TikTok desk real. The user must be able to see their clip, confirm it, skip it, move its time, enter results, and see what was learned. All of it runs on the API that exists from Phases 1-3.

Read `docs/SYSTEM.md` first (source of truth when docs disagree), especially sections 2, 6, 8, and 16. Then read the Phase 1-3 prompts in `docs/prompts/` for the exact API behavior.

## 1. Context

Backend, already merged:
- `TikTokClip`, `TikTokCatalog`, `TikTokEngineService` (`run`, `confirm`, `recordResults`, `discard`, `reschedule`), `TikTokAllowanceService`, `TikTokDemandService`, `TikTokClipResource`.
- Routes: `POST /api/tiktok/run`, `/clips/{clip}/confirm`, `/results`, `/discard`, `/reschedule`.
- Error shape: `{ "error": { "code", "message" } }`. Statuses: 409 `invalid_state` / `already_learned`, 422 validation and `invalid_time`, 429 `allowance_spent` / `daily_cap_reached`, 402 `no_plan` on confirm. `run` returns 200 with `outcome: created|skipped`, `reason`, `message`.
- `account_profiles.timezone` exists and the suggester reads it. **Nothing writes it yet.** Empty means UTC and `timezone_assumed = true`.

Product truth that the UI must never contradict:
- **Nothing is rendered or uploaded today.** `confirm` queues the clip and says what is missing. `render.ready` and `uploaded` stay false until a future phase.
- The user makes and posts the video themselves for now, using the script, shots, and caption we provide. The desk must make that workable without implying we do it.
- Dashboard numbers for TikTok are a **preview** until a TikTok account is connected.

## 2. Before you write any code

1. Read `DashboardController::platform()` and the YouTube branch. Note how `pipeline`, `series`, and `ideas` are built and how `preview` is computed.
2. Read `PlatformDesk.tsx`, `TikTokBody`, and `YouTubeBody`: data fetching, loading and error handling, how "Yes, post this" calls the API, how the page refreshes after an action.
3. Read `lib/platform.ts`, `lib/profile.ts`, `AppShell.tsx`, the onboarding page, and the settings page. Find the shared API client and its error handling.
4. Read `AccountSetupController`: the TikTok completion path and how YouTube calls its engine after save.
5. Identify the UI primitives already in the repo (Button, Card, Badge, Dialog or AlertDialog, Skeleton, toast, Input, Slider, Progress). **Use those. Do not add a UI library or any dependency.** If a primitive you need is missing, build it in the same shadcn style and tell me.
6. Find the frontend test setup (Vitest, Jest, Playwright, Testing Library) and the existing lint and type-check scripts. If there is no frontend test runner, **do not add one** (see section 4D).
7. Find the billing page route and the existing Settings copy for the TikTok connection so wording stays consistent.
8. Check whether a date library is already installed. If not, use `Intl` only.

## 3. Hard boundaries

- Do NOT implement upload, render, TikTok OAuth, or touch `connected_accounts`.
- Do NOT add scheduler entries, commands, or `runAll()`. That is Phase 5.
- Do NOT rebuild `/trends` or `/analytics` for TikTok. That is Phase 6. Leave them as they are.
- Do NOT touch `GrowthAgentService`, `SafetyEngineService`, `rate_limit_budgets`, X code, or YouTube code beyond reading it.
- Do NOT write to `posts` and do NOT mirror clips into `posts`.
- **The dashboard GET must have no side effects.** It must never call `run()`, `confirm()`, or write anything.
- Never expose the raw `package`, prompts, or model details to the client.
- Never set `render.ready = true` or `uploaded = true` anywhere.
- No new dependencies. No new platform settings.
- No optimistic UI for confirm, discard, results, or reschedule. The server is the source of truth, so the UI refetches after every action.
- If the repo contradicts this brief, stop and ask. Do not guess.

## 4. Deliverables

### A. Backend

**A1. Setup wiring.** In `AccountSetupController`, when TikTok setup completes, call `TikTokEngineService::run($user)` the way YouTube does. A skip or an exception in `run()` must **never** fail or change the setup response. Catch, log a warning with `user_id`, and continue.

**A2. Timezone capture.**
- `PUT /api/account/timezone`, behind the same auth middleware as other account routes. Body: `timezone` (IANA name, validated with Laravel's `timezone:all` rule). Saves to `account_profiles.timezone`. Returns `{ "data": { "timezone": "...", "assumed": false } }`.
- Also accept an optional `timezone` in the TikTok platform setup request and save it if valid. Ignore it silently if invalid. Setup must never fail because of it.
- Add `TikTokEngineService::refreshAssumedTime(User $user): ?TikTokClip`. If the user has a `waiting` clip whose `package.timezone_assumed` is true and the profile now has a timezone, recompute `suggested_for` with `TikTokTimeSuggester`, update `package.timezone` and `timezone_assumed = false`, and return the clip. Do nothing for `queued` clips or clips that were rescheduled by the user (`rescheduled_by_user = true`). Call it after the timezone endpoint saves.

**A3. Dashboard payload.** For `GET /api/dashboard/platform/tiktok`, keep every existing key and add one `tiktok` object. It is read-only and built from existing services.

```json
"tiktok": {
  "clip": "<TikTokClipResource> | null",
  "recent": ["<TikTokClipResource>"],
  "ideas": [{ "kind": "question", "kind_label": "A question", "topic": "...", "angle": "...", "reason": "..." }],
  "pipeline": { "ideas": 5, "written": 3, "waiting": 1, "confirmed": 1, "posted": 0 },
  "allowance": { "limit": 8, "used": 3, "remaining": 5, "period_end": "ISO|null", "plan_found": true },
  "daily_cap": { "cap": 3, "used": 1, "reached": false, "available_at": "ISO|null" },
  "block": null,
  "timezone": { "value": "UTC", "assumed": true },
  "connection": { "connected": false }
}
```

Rules:
- `clip` is the single clip that blocks the engine (`blocking()` scope), or null. At most one.
- `recent` is the last 5 `learned` clips, newest first, same resource.
- `ideas` is the top 5 from `TikTokDemandService::ranked`. **No numeric score is sent to the client.** Fit is shown through `reason`. `kind_label` comes from the catalog.
- `pipeline`: `ideas` is the ranked count, `written` is clips created in the current allowance period excluding discarded, `waiting` and `confirmed` (queued) and `posted` are counts of clips in those statuses.
- `daily_cap.reached` and `available_at` are computed from the same rolling-24h rule confirm uses. Reuse the service logic so they cannot disagree. Add a small public method rather than duplicating the query.
- `block` explains why a **new** clip cannot be made, and is null otherwise (including when a current clip simply exists):
  `{ "reason": "setup_incomplete|no_plan|allowance_spent", "message": "<catalog skipMessage>", "action": "finish_setup|billing|wait|null", "renews_at": "ISO|null" }`.
- `timezone.value` falls back to `UTC` with `assumed = true`, the same as the suggester.
- Query efficiently: no N+1, allowance computed once.

### B. Frontend

**B1. Types and client.** Add typed models for the `tiktok` payload and the action responses. Add one small module (for example `lib/tiktok.ts`) with `runTikTok`, `confirmClip`, `discardClip`, `rescheduleClip`, `submitResults`, `saveTimezone`. It normalizes errors into `{ code, message, status }` and handles **both** shapes: the API's `{ error: { code, message } }` and Laravel's default (throttle 429 without a code, validation `errors`).

**B2. Split `TikTokBody`.** Do not grow one giant component. Create `components/dashboard/tiktok/`:

- `TikTokBody.tsx`: state machine and layout only.
- `PipelineStrip.tsx`: Ideas found, Written, Waiting for you, **Confirmed**, Posted. Confirmed is a new step because queued is not posted. Counts come from `tiktok.pipeline`.
- `ClipCard.tsx`: the hook, script lines, caption (with a copy button), on-screen text, shots, and sound note, in readable sections.
- `ConfirmBar.tsx`: the confirm action with the time (see B3).
- `QueuedPanel.tsx`: the honest checklist.
- `ResultsForm.tsx`, `LearnedPanel.tsx`, `IdeasList.tsx`, `BlockNotice.tsx`, `TimezoneNotice.tsx`, `ReschedulePopover.tsx`.

**B3. The states.** Render exactly one primary state from `tiktok.clip` and `tiktok.block`.

| State | Condition | What the user sees | Primary action |
|---|---|---|---|
| Not chosen / setup incomplete | existing behavior, or `block.reason = setup_incomplete` | Existing setup prompt | Finish setup |
| Blocked | `block` set, no clip | `block.message` verbatim, plus renew date if present | Billing link for `no_plan`, none for `allowance_spent` |
| Empty | no clip, no block | Heading, short explainer, IdeasList as "What we're looking at" | **Find an idea** (calls `run`) |
| Generating | `run` in flight | Skeleton of the clip card, `aria-busy`, live message | none |
| Waiting | `status = waiting` | ClipCard, suggested time, TimezoneNotice if assumed | **Yes, go ahead for {time}** |
| Confirmed | `status = queued` | QueuedPanel (checklist from `blockers`), ClipCard collapsed to a summary with expand | Skip this clip |
| Posted | `status = posted` | Posted time and link if present | Add results |
| Results | queued or posted | Collapsed "Posted it yourself? Add how it did" | Save and get next idea |
| Learned | just learned, and `recent` | LearnedPanel: the note in plain words, and the outcome of the `next` clip | none |

Results entry is offered only for `queued` and `posted` clips (not `waiting`), because a clip should be confirmed before the user posts it.

**B4. Exact copy.** Use these strings. Do not paraphrase the honesty-critical ones.

| Where | Copy |
|---|---|
| Empty heading | "No clip yet" |
| Empty body | "We'll look for an idea that fits your niche and write the clip. Nothing goes out without your yes." |
| Empty button | "Find an idea" (busy: "Writing your clip…") |
| Waiting eyebrow | "Waiting for your yes" |
| Confirm button | "Yes, go ahead for {time}" |
| Confirm helper text (always visible near the button) | "This confirms the clip. It posts once your video file is ready and your TikTok is connected." |
| Confirmed heading | "Confirmed. Not posted yet." |
| Checklist | "Video file: not ready yet" and "TikTok connection: waiting for TikTok approval" (derive from `blockers`; show a done state only when that blocker is absent) |
| Queued skip hint | "Skipping frees this clip's slot for the month." |
| Results disclosure | "Posted it yourself? Add how it did." |
| Preview banner | Keep existing, ensure it says "These numbers are a preview until your TikTok is connected." |
| Timezone notice | "Times are in UTC because we don't know your timezone yet. Use {browserTz}?" with a button "Use {browserTz}" |
| Daily cap (pre-empted) | Use `daily_cap` to disable confirm, and show "You've reached today's limit. You can confirm again at {time}." |

Never write "Posted", "Scheduled", "Uploaded", or "Live" for a clip that is not `posted`.

**B5. Interaction rules.**
- **One confirm button in the DOM.** On mobile it sits in a sticky bottom bar (`position: sticky; bottom: 0`, respecting `env(safe-area-inset-bottom)`, not overlapping any existing bottom navigation). On `md` and up it renders inline in the card. Use CSS, not two buttons.
- Secondary actions (Change time, Skip this idea) are visually separated from the confirm button with generous spacing and smaller, outlined or ghost styling. Minimum 44px touch targets.
- The confirm button disables itself on click and shows a spinner, then the desk refetches. After success, move focus to the "Confirmed" heading and announce it in an `aria-live="polite"` region.
- Skip uses the existing AlertDialog pattern, or an inline confirm if none exists. Copy: "Skip this clip? You can get a new idea right away." with "Skip clip" and "Keep it".
- **Change time:** quick picks ("Tonight 7:30 PM" if still at least 60 minutes ahead, "Tomorrow 7:30 PM") plus a native `datetime-local` input. Show the zone being used. Convert to an ISO string **with offset** before sending (use the browser's offset via `Date`/`Intl`; if the profile timezone is set and differs from the browser, say so in the helper text rather than silently converting). Constrain `min` to now+60 minutes and `max` to now+14 days. Show the API's `invalid_time` message inline.
- Results form: three fields. Views (integer), Percent watched (slider plus numeric input, kept in sync, 0 to 100), New followers (integer). Hints: "Find these in TikTok's analytics for this video." Do not name specific TikTok menu paths. Validate client-side for instant feedback, but treat server validation as authoritative and map `errors` to fields. Submit label "Save and get my next idea".
- After results, show the learning note and the `next` outcome: created ("Your next clip is ready") or skipped (the human message). Then refetch.
- IdeasList shows `kind_label`, `topic`, `angle`, and `reason`. It is informational. There is **no idea-picking endpoint yet**, so do not add a per-idea action. Remove the old "Write a clip" button that links to Compose from the TikTok desk. Compose remains available through the sidebar for standalone captions.
- TikTok sidebar "Waiting for you" should point to the desk's clip section (for example `/dashboard/tiktok#clip`) instead of `/drafts`, so the user does not land on a list that does not contain their clip. Change only this link in `AppShell`.
- Copy buttons for hook, caption, and script use the Clipboard API with a graceful fallback and a brief "Copied" state.

**B6. Error handling matrix.**

| Code or status | UI behavior |
|---|---|
| `invalid_state` / `already_learned` (409) | Refetch, toast "This clip changed. Here's where it is now." |
| `allowance_spent` (429) | Inline under the button with the API message. Disable confirm. |
| `no_plan` (402 on confirm, or `run` skip) | Inline with the message and a billing link |
| `daily_cap_reached` (429) | Inline with the API message. Disable confirm until `available_at` |
| `invalid_time` (422) | Inline in the reschedule control |
| Validation 422 | Map to form fields |
| Throttle 429 with no code | "Slow down a moment and try again." |
| 404 | Refetch, toast "That clip isn't available any more." |
| 401 | Existing auth handling |
| Network or 5xx on a mutating call | "Something went wrong. We're checking what happened." then refetch to learn the truth. Never assume failure or success |

**B7. Accessibility and responsiveness.**
- State is never conveyed by color alone (icon plus text). Visible focus rings. Logical focus order. All controls reachable by keyboard.
- Headings in order. The checklist is a real list. The slider has an accessible name and value text.
- Loading states use skeletons and `aria-busy`. Honor `prefers-reduced-motion`.
- Works at 360px wide with no horizontal scroll. Check light and dark themes if the app has both.
- Long scripts and captions wrap and never overflow the card.

### C. Fixtures for review

- Create typed fixtures for every state in B3 (including blocked variants, assumed timezone, daily cap reached, queued with both blockers, learned with a `next` result).
- If the repo has a frontend test runner, write component tests with those fixtures. If it does not, **do not add one.** Instead add a dev-only page (for example `app/(app)/dev/tiktok-states/page.tsx`) that renders each state from fixtures, guarded so it returns `notFound()` when `NODE_ENV === 'production'`. Report it so I can delete it later.

## 5. Tests

**Backend (feature tests, repo runner, isolated test DB):**
- Dashboard `tiktok`