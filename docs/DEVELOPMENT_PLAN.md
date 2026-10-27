# Xpulse — Complete Development Plan

**From zero to production-ready Growth Engine for X**

> **Last synced:** July 2026 · **Product name:** Xpulse (repo folder may still read `GrowthApp`)

This plan follows every decision in [`PROJECT_FINDINGS.md`](PROJECT_FINDINGS.md). For historical context see [`SOURCE_CONVERSATION.md`](SOURCE_CONVERSATION.md).

> **`ROADMAP.md` is deprecated.** It described an older extension-first, Turborepo vision. This document is the **single source of truth** for implementation status and next steps.

---

## Current Status (Snapshot)

**Strategy:** Build the full product shell + mock logic first. **X OAuth, OpenAI, live posting, and billing** are the **final API stage** — do not block UI work on them.

| Phase | Progress | Summary |
|-------|----------|---------|
| **0 — Setup** | ~75% | Auth, schema, local dev done; X Developer app, git, Docker runtime pending |
| **1 — MVP** | ~50% | Onboarding, Content Studio, Dashboard, drafts — mock data; no publish/OpenAI |
| **2 — Growth Engine** | ~45% | Mock trends, scoring, decisions, Safety UI; no live autopilot |
| **3 — Engagement + Analytics** | ~25% | UI shells with mock feeds/charts; no learning loop |
| **4 — SaaS** | ~5% | Legal pages only |
| **5 — GA** | 0% | Not started |
| **6 — Expand** | 0% | Correctly deferred |

**Phase exit criteria today:** A user can **register → onboard → use every app page** with preview metrics. Real X connect and live posting are **not** met yet.

---

## Stack Summary

| Layer | Planned | **Actual (local dev)** |
|-------|---------|------------------------|
| Frontend | Next.js 15 + TS + Tailwind + Shadcn | ✅ Next.js 15, Shadcn (base-nova), Sonner |
| Backend | Laravel 11 + PHP 8.3 | ✅ Laravel **12** + PHP **8.2+** (XAMPP) |
| Database | PostgreSQL 16 | ✅ **SQLite** locally; `docker-compose.yml` ready for PostgreSQL |
| Cache / Queue | Redis 7 + Laravel Queues | ✅ Database queue locally; Redis when Docker enabled |
| AI | OpenAI API | ⏳ **Deferred** — template/mock generation now |
| Auth | Sanctum + X OAuth 2.0 | ✅ Sanctum; ⏳ X OAuth deferred |
| Payments | Paddle + Flutterwave | ⏳ Not started |
| Hosting | Hetzner/DO + Vercel | ⏳ Not deployed |

---

## Repository Structure (As Built)

```
GrowthApp/                          # repo folder name (rename optional)
├── apps/
│   └── web/                        # Next.js dashboard (Xpulse UI)
├── backend/                        # Laravel 12 API
│   ├── app/
│   │   ├── Http/Controllers/Api/   # 10 controllers
│   │   ├── Services/
│   │   │   ├── TrendEngine/        # TrendEngineService, OpportunityScorerService
│   │   │   ├── GrowthAgent/        # GrowthAgentService, SafetyEngineService
│   │   │   ├── AICore/             # AICoreService (stub)
│   │   │   └── XApi/               # XApiService (stub)
│   │   └── Jobs/                   # FetchGlobalTrends, EvaluateGrowth*
│   ├── database/migrations/
│   └── routes/api.php
├── scripts/
│   ├── start-scheduler.ps1
│   └── start-queue.ps1
├── docs/
│   ├── PROJECT_FINDINGS.md         # product spec (source of truth for features)
│   ├── DEVELOPMENT_PLAN.md         # this file
│   ├── ROADMAP.md                  # deprecated — see note above
│   └── SOURCE_CONVERSATION.md
├── docker-compose.yml              # PostgreSQL 16 + Redis 7
├── LOCAL_DEV.md
└── README.md                       # TODO: add root README
```

---

## Build Strategy: Mock First, APIs Last

| Stage | What ships | Status |
|-------|------------|--------|
| **A — Product shell** | All pages, onboarding, mock engines, preview metrics | ✅ ~done |
| **B — Infrastructure** | Git, Docker PostgreSQL/Redis, staging deploy | ⏳ partial |
| **C — Final API stage** | X OAuth, X read/write, OpenAI, billing webhooks | ⏳ not started |

---

## Phase 0 — Setup & Accounts (Days 1–5)

**Goal:** Environment ready, accounts registered, repo scaffolded.

### Day 1: Accounts & Keys
- [ ] Register **X Developer Account** — use policy-safe description from `PROJECT_FINDINGS.md` §10.3
- [ ] Create **OpenAI** API key with usage limits
- [ ] Register **Paddle** seller account — verify Uganda payouts
- [ ] Register **Flutterwave** for African payments
- [ ] Buy domain (Xpulse — after name finalized)
- [ ] Create GitHub repo and push initial commit

### Day 2: Local Environment
- [x] Install PHP 8.2+, Composer, Node 20+ — documented in `LOCAL_DEV.md`
- [~] Install Docker — **not on dev machine yet**; `docker-compose.yml` ready
- [x] `docker-compose.yml` — PostgreSQL 16 + Redis 7 with healthchecks
- [x] Laravel backend scaffolded at `backend/`
- [x] Next.js app at `apps/web/` (TypeScript, Tailwind, App Router)
- [x] Shadcn UI installed (Button, Card, Badge, Select, Progress, etc.)
- [x] Laravel `.env.example` — DB, Redis, X OAuth, OpenAI placeholders

### Day 3: Auth Foundation
- [x] Laravel Sanctum — API tokens, `auth:sanctum` middleware
- [x] User registration/login (email + password)
- [x] Next.js login/register pages
- [x] CORS for `localhost:3000` (Bearer token auth; `statefulApi()` removed)

### Day 4: X OAuth *(final API stage)*
- [ ] Implement X OAuth 2.0 PKCE flow in Laravel
- [x] `connected_accounts` migration + model (encrypted token casts)
- [ ] Encrypt/store access + refresh tokens (model ready; no flow)
- [~] "Connect X Account" in Settings — **placeholder UI** ("coming soon")
- [ ] Test: connect one X account end-to-end

### Day 5: Database Schema v1
- [x] `users` (+ `subscription_plan` column for future billing)
- [x] `connected_accounts`
- [x] `account_profiles` (niche, tone, goal, audience, mode, automation_level)
- [x] `posts`
- [x] `ai_decisions`
- [x] `rate_limit_budgets`
- [x] `trends` *(Phase 2 scope — added early)*

**Phase 0 exit (original):** User can sign up, log in, connect X via OAuth.  
**Phase 0 exit (current):** ✅ Sign up + log in + full schema. ❌ X OAuth.

---

## Phase 1 — MVP: Content + Basic Dashboard (Weeks 2–5)

**Goal:** User sets niche, generates content, sees basic stats.

### Onboarding Wizard (Next.js)
- [x] 6-step wizard — goal, niche, description, audience, voice, setup (mode + automation)
- [x] Store in `account_profiles` via `POST /api/account/profile`
- [x] Redirect to dashboard on complete; triggers growth evaluation
- [ ] AI Niche Discovery optional flow ("Not sure")

### Content Studio (Next.js + Laravel)
- [x] `POST /api/content/generate` — returns 3 profile-aware variants *(mock templates, not OpenAI)*
- [x] Placeholder virality scores on variants
- [x] Content Studio UI — inputs + 3 editable options
- [x] Save Draft → `POST /api/content/drafts`
- [ ] Publish via X API *(deferred)*

### Drafts
- [x] List, edit, delete drafts — `/drafts` + CRUD API

### X Posting *(final API stage)*
- [~] `XApiService` — stub only
- [ ] `POST /api/posts/publish`
- [ ] Store published post with X timestamp
- [ ] Log publish decision in `ai_decisions`

### Basic Analytics Ingest *(final API stage)*
- [ ] Queue job: fetch last 60 days of tweets + public metrics
- [ ] Store aggregates: avg likes, RTs, replies, best posting hours
- [~] Dashboard stat cards — **preview/mock metrics** when X not connected

### Dashboard v1 (Next.js)
- [x] Landing page `/` — Hero, Features, Pricing, FAQ, CTA
- [x] Legal pages — `/about`, `/privacy`, `/terms`
- [x] Follower count + delta *(mock)*
- [x] Impressions + change percent *(mock)*
- [x] Engagement rate + Growth score *(mock)*
- [x] Top opportunity banner from trend scoring
- [x] Trend opportunities list
- [~] Connected account status — count shown; connect flow unavailable

### MVP Polish
- [x] Loading states, Sonner toasts, error handling (core pages)
- [~] Rate limit tracking — `SafetyEngineService` + table; not incremented on real actions
- [ ] Staging deploy (Hetzner + Vercel)
- [ ] Alpha testers on real X accounts

**Phase 1 exit (original):** Sign up → onboard → generate → publish → live stats.  
**Phase 1 exit (current):** ✅ Sign up → onboard → generate (mock) → drafts. ❌ Publish / live stats.

---

## Phase 2 — Trend Engine + Growth Engine Core (Weeks 6–10)

**Goal:** Event-driven autonomous posting with safety.

### Global Trend Engine
- [x] `FetchGlobalTrendsJob` + scheduler every **5 min** (`routes/console.php`)
- [x] `xpulse:seed-trends` artisan command + `MockTrendSeeder`
- [~] Trend fetch — **mock catalog** in `TrendEngineService` (no X API yet)
- [x] `trends` table with score, velocity, growth_rate, niche_tags
- [x] One global fetch — not per-user
- [x] Trend Explorer UI — `/trends`

### Opportunity Engine
- [x] `OpportunityScorerService` — niche match, growth, competition, mode weights
- [x] 0–100 opportunity score
- [x] User matching by `account_profiles.niche`
- [x] Dashboard + Growth Engine opportunity widgets

### Growth Engine v1
- [x] `GrowthAgentService` — POST / ENGAGE / WAIT decisions
- [x] `EvaluateGrowthOpportunitiesJob` + `EvaluateAllGrowthOpportunitiesJob`
- [x] Scheduler evaluates all users every **10 min** (inline when no Redis)
- [x] Onboarding completion triggers immediate evaluation
- [x] Decisions logged to `ai_decisions`
- [ ] Dynamic posting at publish time *(deferred — no X API)*
- [ ] NewsBrain / ConversationBrain / GrowthBrain class structure

### AI Core Pipeline *(final API stage)*
- [~] `AICoreService` — stub with 2 hardcoded event templates
- [ ] Full OpenAI prompt templates (tweet, thread, poll, quote, reply)
- [ ] Event templates: GOAL, TRANSFER, RED_CARD, BREAKING
- [ ] Context injection: niche + tone + trend + performance history

### Safety Engine
- [x] `SafetyEngineService` — daily action budgets per user
- [x] Safety can block POST decisions when limits reached
- [x] Safety Center UI — `/safety` with score + limit progress bars
- [ ] Replies/hour, follows/day on real actions
- [ ] Duplicate text similarity checks
- [ ] Randomized delays (3–42 min between actions)
- [ ] New account warmup rules
- [x] `SafetyResetJob` daily cleanup — scheduler at 00:05 + `xpulse:reset-safety`

### Growth Engine UI
- [x] Sidebar: **Growth Engine** (`/growth-agent`)
- [x] Live decision feed — `GET /api/growth-agent/feed`
- [x] Settings — mode, automation level (0–3), max silence
- [x] Manual "Run evaluation" button
- [ ] Explicit autopilot consent flow
- [ ] Live autopilot execution *(requires X API + OpenAI)*

**Phase 2 exit (original):** Autonomous posting with safety overrides.  
**Phase 2 exit (current):** ✅ Mock trend loop + decision logging + Safety UI. ❌ Live posting.

---

## Phase 3 — Engagement + Learning + Analytics (Weeks 11–14)

**Goal:** Intelligent engagement, learning loop, full analytics.

### Engagement Center
- [x] Engagement Center UI — `/engagement`
- [~] Viral tweet feed — **mock posts** tailored to user niche
- [~] Reply generator — 3 **template** drafts via `POST /api/engagement/reply-drafts`
- [x] Save reply to drafts from Engagement page
- [ ] Smart Engagement Mode (live discovery)
- [ ] Limited auto-reply (user-enabled + safety)
- [ ] Track replies sent, profile visits, followers gained *(stats hardcoded to 0)*

### Analytics Engine + Learning
- [x] Analytics Center UI — `/analytics` with growth charts, top posts, insights
- [~] `GET /api/analytics/growth` — **30-day mock series** + profile-aware insights
- [ ] `AnalyticsEngine/` service (metrics collection)
- [ ] Post-action job: collect metrics at 1h, 24h
- [ ] Predicted vs actual virality comparison
- [ ] Learning DB — formats/hooks/times per account
- [ ] Weekly recap email/in-app notification

### Conversation Mode + Content Mix
- [ ] Conversation Brain when news score is low
- [ ] Content mix % (questions, polls, banter)
- [ ] Max silence proactive content rule *(field stored; not enforced in agent loop)*

### Audience Insights + Competitor Intel
- [~] Generic timing/content insights in mock analytics response
- [ ] Activity heatmap from real data
- [ ] Competitor Intelligence — track 5 handles
- [ ] Revenue Potential Score on Content Studio

**Phase 3 exit (original):** Full growth loop with learning.  
**Phase 3 exit (current):** ✅ UI shells. ❌ Learning loop / live engagement.

---

## Phase 4 — SaaS Billing + Production Hardening (Weeks 15–18)

**Goal:** Paying customers, production reliability.

### Billing
- [ ] Paddle integration — Free, Starter $12, Creator $29, Pro $59, Agency $149
- [ ] Flutterwave for African users
- [ ] Webhook handlers: `subscription.created`, `subscription.cancelled`
- [ ] Feature gates per plan (`subscription_plan` column exists on `users`)
- [ ] 7-day free trial flow
- [ ] Billing page in Settings

### Performance & Scale Prep
- [ ] Global trend cache in Redis (TTL 2–5 min)
- [~] Queue workers — `scripts/start-queue.ps1`; separate queues not configured
- [ ] API response caching for dashboard reads
- [ ] Load test: 100 concurrent AI requests

### Security Audit
- [~] Routes scoped by `user_id` on owned resources
- [~] OAuth token encryption — model ready; no tokens yet
- [ ] API rate limiting middleware
- [x] CSRF fix for Bearer auth (removed `statefulApi()`)
- [ ] Audit log for all AI actions
- [x] Privacy policy + Terms published (web pages)

### Public Beta Launch
- [ ] Production deploy + monitoring (Sentry, etc.)
- [ ] Status page
- [ ] Help center
- [ ] 50–100 beta users

**Phase 4 exit:** Not started (except legal pages).

---

## Phase 5 — Scale & Moat (Weeks 19–24)

- [ ] Multiple X accounts per workspace (plan-gated)
- [ ] Team roles: owner, editor, viewer
- [ ] Agency dashboard: client switcher
- [ ] Separate worker servers, load balancer, read replicas
- [ ] 99.9% uptime SLO, GDPR export/deletion, affiliate program
- [ ] v1.0 GA launch

**Phase 5 exit:** Not started.

---

## Phase 6 — Future Modules (Month 7+)

Do NOT start until X module has paying users and proven growth results.

| Module | Status |
|--------|--------|
| TikTok | Not started |
| YouTube | Not started |

The Phase 6 TikTok line above is stale: the engine, clip desk, and hourly schedule now exist, and this checklist was not rewritten.
| Cross-platform trend linking | Not started |

---

## Build Order Checklist (Updated)

```
✅ Stage A — Product shell (mock)
  1. [x] Laravel + Next.js scaffold
  2. [x] Auth (Sanctum) + login/register
  3. [x] Database migrations v1 + trends
  4. [x] 6-step onboarding wizard
  5. [x] Content Studio + drafts CRUD (mock generation)
  6. [x] Dashboard + landing + legal pages (preview metrics)
  7. [x] Mock Trend Engine + Trend Explorer
  8. [x] Opportunity Scorer + Growth Engine + decision feed
  9. [x] Safety Engine + Safety Center UI
  10. [x] Engagement Center + Analytics Center (mock)
  11. [x] Scheduler scripts + LOCAL_DEV.md

⬜ Stage B — Infrastructure
  12. [~] Git init + GitHub repo — repo initialized; push pending
  13. [x] Root README.md
  14. [ ] Docker Desktop + switch to PostgreSQL/Redis
  15. [ ] Staging deploy (Hetzner + Vercel)
  16. [~] UI polish (mobile nav, empty states, cross-links) — mobile nav + dashboard/growth empty states done

⬜ Stage C — Final API stage
  17. [ ] X Developer app + OAuth PKCE connect flow
  18. [ ] Tweet ingest job (60-day history)
  19. [ ] OpenAI content + reply pipeline
  20. [ ] X publish endpoint + live posting
  21. [ ] Replace mock dashboard/analytics with live X data
  22. [ ] Analytics collect job + learning loop
  23. [ ] Safety enforcement on real actions
  24. [ ] Paddle + Flutterwave billing + feature gates
  25. [ ] Public beta launch
```

---

## Laravel Scheduler (As Implemented)

Local dev — run in a separate terminal:
```powershell
.\scripts\start-scheduler.ps1
# or: cd backend && php artisan schedule:work
```

Production crontab:
```bash
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

| Job / Task | Frequency | Status | Notes |
|------------|-----------|--------|-------|
| Trend fetch (inline or `FetchGlobalTrendsJob`) | Every 5 min | ✅ | Mock trends when no X API |
| Growth evaluation (inline or `EvaluateAllGrowthOpportunitiesJob`) | Every 10 min | ✅ | Redis path when `QUEUE_CONNECTION=redis` |
| `AnalyticsCollectJob` | Every 15 min | ❌ | Not implemented |
| `FollowerSnapshotJob` | Daily | ❌ | Not implemented |
| `SafetyResetJob` | Daily 00:05 | ✅ | Cleans expired rate-limit rows |
| `TokenRefreshJob` | Every 30 min | ❌ | Requires X OAuth |

Artisan commands:
```bash
php artisan xpulse:seed-trends
php artisan xpulse:evaluate
php artisan xpulse:reset-safety
php artisan schedule:list
```

---

## Queue Workers

When using Redis (`QUEUE_CONNECTION=redis`):
```powershell
.\scripts\start-queue.ps1
```

Planned separate queues (not configured yet):
```bash
php artisan queue:work redis --queue=trends --tries=3
php artisan queue:work redis --queue=ai --tries=3
php artisan queue:work redis --queue=posting --tries=5
php artisan queue:work redis --queue=analytics --tries=3
```

---

## API Endpoints

### Implemented

| Method | Endpoint | Purpose | Notes |
|--------|----------|---------|-------|
| GET | `/api/health` | Health check | Returns Xpulse API v0.1.0 |
| POST | `/api/auth/register` | Sign up | |
| POST | `/api/auth/login` | Login | |
| POST | `/api/auth/logout` | Logout | Sanctum |
| GET | `/api/auth/me` | Current user + profile | |
| GET/POST | `/api/account/profile` | Onboarding / profile | POST sets `onboarding_completed` |
| GET | `/api/dashboard/stats` | Dashboard cards | Preview metrics when X not connected |
| GET | `/api/dashboard/opportunities` | Top 5 opportunities | |
| GET | `/api/trends` | Trend Explorer | Auto-seeds mock trends if empty |
| POST | `/api/content/generate` | Content Studio | Mock variants |
| POST | `/api/content/drafts` | Save draft | |
| PUT | `/api/content/drafts/{post}` | Update draft | |
| DELETE | `/api/content/drafts/{post}` | Delete draft | |
| GET | `/api/content/posts` | List posts | |
| GET | `/api/growth-agent/settings` | Growth Engine settings | |
| PUT | `/api/growth-agent/settings` | Update settings | |
| POST | `/api/growth-agent/evaluate` | Manual evaluation | |
| GET | `/api/growth-agent/feed` | Decision log | |
| GET | `/api/safety/status` | Safety Center | |
| GET | `/api/engagement/feed` | Engagement feed | Mock |
| POST | `/api/engagement/reply-drafts` | Reply options | Mock |
| GET | `/api/analytics/growth` | Analytics charts | Mock |
| GET | `/api/connected-accounts` | List accounts | |
| GET | `/api/connected-accounts/x/status` | X connect status | Placeholder |

### Planned (final API stage)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/auth/x/redirect` | Start X OAuth |
| GET | `/api/auth/x/callback` | Complete X OAuth |
| POST | `/api/posts/publish` | Publish tweet via X API |
| POST | `/api/webhooks/paddle` | Billing webhook |
| POST | `/api/webhooks/flutterwave` | African billing webhook |

---

## Frontend Routes (As Built)

| Route | Page | Status |
|-------|------|--------|
| `/` | Landing | ✅ |
| `/login`, `/register` | Auth | ✅ |
| `/onboarding` | 6-step wizard | ✅ |
| `/dashboard` | Growth overview | ✅ mock metrics |
| `/content-studio` | Content generation | ✅ mock |
| `/drafts` | Draft library | ✅ |
| `/growth-agent` | Growth Engine | ✅ |
| `/engagement` | Engagement Center | ✅ mock |
| `/trends` | Trend Explorer | ✅ |
| `/analytics` | Analytics Center | ✅ mock |
| `/safety` | Safety Center | ✅ |
| `/settings` | Profile + X connect placeholder | ✅ |
| `/about`, `/privacy`, `/terms` | Legal | ✅ |

---

## Testing Strategy

| Type | Status | What to test |
|------|--------|--------------|
| Unit | ❌ | OpportunityScorer, SafetyEngine limits, content mix logic |
| Integration | ❌ | X OAuth, OpenAI generation, X publish |
| E2E | ❌ | Full onboarding → generate → publish → dashboard |
| Manual | ✅ | Register → onboard → all pages (mock flow) |

---

## Performance Targets (Must Hit Before GA)

| Metric | Target | Current |
|--------|--------|---------|
| Breaking news pipeline | 5–20 seconds | N/A (no live pipeline) |
| AI tweet generation p95 | < 1.5 seconds | N/A (mock is instant) |
| Dashboard load | < 2 seconds | ✅ locally |
| Trend Explorer load | < 300ms (cached) | ✅ locally (SQLite) |
| Uptime | 99.9% | N/A (not deployed) |
| Zero missed autopilot posts | 100% | N/A (no autopilot) |

---

## Timeline Summary

| Phase | Duration | Deliverable | Status |
|-------|----------|-------------|--------|
| 0 — Setup | Days 1–5 | Auth + schema | ~75% |
| 1 — MVP | Weeks 2–5 | Content Studio + Dashboard | ~50% (mock) |
| 2 — Growth Engine | Weeks 6–10 | Event-driven posting | ~45% (mock) |
| 3 — Engagement | Weeks 11–14 | Replies, learning, analytics | ~25% (UI) |
| 4 — SaaS | Weeks 15–18 | Billing + public beta | ~5% |
| 5 — GA | Weeks 19–24 | Multi-account, scale, v1.0 | 0% |
| 6 — Expand | Month 7+ | TikTok, YouTube | 0% |

**Mock product demo:** ✅ Ready now.  
**MVP with live X:** Requires Stage C (X OAuth + ingest + publish).  
**Total to v1.0:** ~6 months solo, full-time (unchanged).

---

## Next Action Items (Priority Order)

1. **Git init + GitHub repo** — protect current work
2. **Root README.md** — project overview + link to `LOCAL_DEV.md`
3. **Apply for X Developer account** — approval can take weeks; start early
4. **UI polish pass** — mobile sidebar, empty states, cross-page links
5. **Install Docker** → PostgreSQL + Redis → `migrate:fresh --seed`
6. **Final API stage** — X OAuth → read-only analytics → OpenAI → publish → billing

---

*This plan is binding for all implementation work. Update this file when phase status changes. Do not deviate from the stack or architecture without updating `PROJECT_FINDINGS.md` first.*
