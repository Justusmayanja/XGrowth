TikTok engine: phased implementation plan

The checklist to mark off is [TIKTOK_PLAN.md](TIKTOK_PLAN.md). This file keeps the decisions that plan is built on.

I’m treating this as a sibling of the YouTube module with a stricter publish rule. Each phase ships something testable and leaves the product honest, so we never show a state that claims more than the code does.

Decisions to lock before Phase 1
Clip vs. caption storage. Clips live in tiktok_clips. I recommend not mirroring captions into posts in v1. Mirroring creates two sources of truth for one thing. Compose can keep writing standalone tiktok_caption drafts, and we revisit once the desk is stable.
Timezone. SYSTEM.md doesn’t mention a stored user timezone. A “suggested time” without one is wrong for half our users, and Kampala vs. US users is exactly where this breaks. Either store timezone on account_profiles (captured at onboarding from the browser) or store UTC and always render client-side. I’d do both.
When the allowance is consumed. Count on confirm → queued (matches the doc) and release it if the clip is discarded before posting.
Stuck-pipeline problem. The open-item guard blocks new clips while one is waiting, queued, or unlearned. Since upload can’t exist yet, every clip would sit in queued and block the engine forever. We need escape hatches: discard, reschedule, and allowing results from waiting, queued, or posted. These go beyond the route list in section 17, so I’m flagging them as additions.
Phase 1: Data layer and catalog

Goal: the schema and vocabulary exist. No behavior yet.

Backend

Migration tiktok_clips: the columns from section 16, plus timezone_hint, discarded_at, and an index on (user_id, status).
TikTokClip model with casts (package as array, timestamps) and scopes: open() (waiting/queued), unlearned(), forUser().
TikTokCatalog: statuses and labels, goal→strategy map (for_you→fast hook, followers→reason to stay, traffic→link-carrying, promote_business, monetize), and learning-band constants.
Factory and a model test for the status transitions.

Exit criteria: migration runs on the 5433 Postgres, factory creates a valid clip, and the transition helper rejects illegal jumps (e.g. waiting → posted).

Phase 2: Engine core (generation only)

Goal: run($user) produces one waiting clip with a suggested time. Nothing posts and nothing is scheduled yet.

Backend

TikTokDemandService::ranked(): local niche ideas weighted by goal, then adjusted by past learning_note outcomes (same boost/penalty pattern as YouTube). No X thresholds.
TikTokScriptWriter::clip(): JSON with hook, spoken_lines, caption (reusing the tiktok_caption rules: spoken hook first, 3-5 hashtags, under 400 characters), on_screen_text, shots, sound_note. Feeds in niche, tone, audience, goal sentence, and the last learning note. Records usage under feature tiktok_script and goes through buildSystemPrompt so admin ai_rules apply. Include a local fallback so the desk is never empty when the model is down.
TikTokEngineService::run():
per-user cache lock tiktok-run-{id}, 30s
refuse if the user is blocked, not onboarded, or has no completed TikTok setup
refuse if an open or unlearned clip exists
build the package with render: {provider: pending, ready: false} and publish: {mode: confirm, uploaded: false}
pick suggested_for (start simple: next evening slot in the user’s timezone, never in the past)
save as waiting

Exit criteria: a feature test with the model faked shows that one run creates one clip, a second run creates nothing, and with the model throwing, a fallback clip is still stored.

Phase 3: Confirm, results, learning, and caps

Goal: the full loop works through the API.

Backend

TikTokController behind auth:sanctum + customer:
POST /api/tiktok/run
POST /api/tiktok/clips/{clip}/confirm → queued. It sets confirmed_at and queued_at and records an honest publish.note (“Waiting for the video file and your TikTok connection”). It never uploads.
POST /api/tiktok/clips/{clip}/results (views, completion %, follows) → writes the learning note, sets learned, then calls run() again
Additions: POST .../discard and POST .../reschedule
Policy checks: a clip must belong to the user, and confirm only from waiting.
Allowance: count on queue against billing_subscription_lines.monthly_allowance where platform=tiktok and allowance_unit=clips. Refuse run() when the month is spent, with a clear reason. Never touch rate_limit_budgets.
Daily cap: a new platform setting, tiktok_daily_clip_cap, enforced at confirm so a burst can’t spend the month in an hour.
Learning note bands: ≥50 held, <30 left early, otherwise just recorded.

Exit criteria: feature tests cover confirm-from-wrong-state, someone else’s clip (403/404), allowance exhausted, daily cap hit, and the results → learned → next clip chain.

Phase 4: Desk UI

This is where most of the UX risk lives. The desk can’t look more capable than the engine is.

Wiring

DashboardController::platform('tiktok') returns ideas, pipeline, and the current clip from the engine, the way YouTube does.
AccountSetupController: TikTok completion calls TikTokEngineService::run($user).

TikTokBody states

State	What the user sees	Primary action
No clip yet / generating	Skeleton plus “Looking for an idea that fits your niche”	none
waiting	Clip card: hook large, script lines, caption, on-screen text, shot list, suggested time in their timezone	Yes, post at 7:30 PM
queued	“Confirmed. Not posted yet.” with a visible checklist: ☐ video file, ☐ TikTok connection	Results (once available), Discard
posted	Posted time, link	Add results
Results form	Views, % watched, follows	Save and get next idea
learned	The learning note in plain words, plus what the next clip will lean on	none

UX principles I’d hold to

Honest copy. After confirming, never say “Posted.” Say “Confirmed. We’ll post once your TikTok is connected.” The same applies to the dashboard’s preview-metrics banner, which stays labelled as a preview.
Confirm is a deliberate action. The button states the exact time. Secondary actions (Change time, Skip this idea) sit visually apart so a thumb on mobile doesn’t hit the wrong one.
Mobile first. TikTok users will mostly arrive on a phone. The clip card is single-column with a sticky bottom confirm bar.
Results entry is low friction. Three numeric inputs, with completion as a slider plus numeric field, and hints on where to find each number in TikTok.
Accessibility. Status is never conveyed by color alone, the confirm has a loading and disabled state, and errors (allowance spent, daily cap) are inline with a next step.
Rename the “Write a clip” idea button flow so it hands off to the engine’s idea, not just to Compose.

Exit criteria: every state in the table renders from fixtures, and a manual walkthrough covers happy path, allowance exhausted, and discard.

Phase 5: Scheduling and operations
routes/console.php: tiktok-engine hourly, withoutOverlapping, calling runAll() (excludes blocked, un-onboarded, and incomplete-setup users).
php artisan tiktok:run, matching youtube:run.
Admin Operations: show TikTok clips per status and recent failures, and add a “run engine” action.
Log every skipped run with a reason (open clip, allowance spent, blocked) so support can answer “why didn’t I get a clip?”

Exit criteria: the scheduler dry-run lists the job, and runAll() over a seeded set of mixed users touches only the eligible ones.

Phase 6: Ideas and results screens

/trends and /analytics are still X screens with renamed sidebar labels. For TikTok:

Ideas shows the ranked list from TikTokDemandService with the reason each ranks (e.g. “fast hook, good for For You”).
What landed shows the clip history with views, completion, follows, and the learning note per clip.

This is lower risk and can run in parallel with Phase 5.

Phase 7: Connection, render, upload (externally blocked)

Gated on TikTok developer approval, so I’d keep it separate and not let it hold the earlier phases.

TikTok OAuth beside the X methods in ConnectedAccountController, plus status and disconnect endpoints.
A render step (FFmpeg or a video model) that flips render.ready only when a real file exists.
Upload on queued + connected + rendered, setting posted and platform_post_id. On failure, the clip stays unsent with the reason recorded.
Preview metrics are replaced by real ones.
Phase 8: Hardening

Concurrency test on double-clicked confirm (idempotent), allowance rollback on discard, doc cleanup (DEVELOPMENT_PLAN.md TikTok line, the section 16-19 status in this file), and a compliance review of the “no automated likes/follows/comments” boundaries.

Suggested order

1 → 2 → 3 is the backend spine, then 4 (UI), then 5 and 6 in parallel, with 7 whenever TikTok approval lands. Phases 1-5 give a fully working, honest product without a TikTok connection, which is the point.