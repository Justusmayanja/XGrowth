# ⚠️ Deprecated — See DEVELOPMENT_PLAN.md

> **This roadmap is outdated.** It described an early **GrowthApp** vision: Chrome extension-first MVP, Turborepo monorepo, Drizzle/Prisma, BullMQ, and Stripe billing.

**The product is now [Xpulse](DEVELOPMENT_PLAN.md)** — a web-first event-driven Growth Engine built with **Laravel 12 + Next.js 15**.

---

## Use these docs instead

| Document | Purpose |
|----------|---------|
| **[DEVELOPMENT_PLAN.md](DEVELOPMENT_PLAN.md)** | **Source of truth** — phases, checkboxes, API routes, current status |
| **[PROJECT_FINDINGS.md](PROJECT_FINDINGS.md)** | Product spec, features, billing model (Paddle + Flutterwave) |
| **[LOCAL_DEV.md](../LOCAL_DEV.md)** | How to run locally |
| **[PROJECT_SPEC.md](PROJECT_SPEC.md)** | High-level product overview |

---

## Why this file remains

Kept for historical reference only. Do not use milestone dates or build order from below.

---

# GrowthApp — Development Roadmap *(archived)*

**Goal:** Ship a production-grade X Account Growth Tool that leads the market in **growth outcomes** and **system performance**.

**Timeline estimate:** ~6–9 months to v1.0 production (solo/small team); parallel tracks where noted.

---

## Phase 0 — Foundation & Alignment (Week 1–2) ✅ IN PROGRESS

**Objective:** Lock spec, repo, and performance baseline before writing product code.

### Deliverables
- [x] Project docs (`README`, `PROJECT_SPEC`, `ROADMAP`)
- [ ] Paste & reconcile original ChatGPT conversation → `SOURCE_CONVERSATION.md`
- [ ] Finalize automation stance (co-pilot vs hybrid) with stakeholder
- [ ] Register X Developer App (OAuth 2.0, scopes documented)
- [ ] Choose monorepo stack sign-off
- [ ] Define performance SLO document (`docs/SLO.md`)

### Exit Criteria
- Signed-off feature list for MVP
- X Developer credentials in secure vault (not in repo)
- Empty repo scaffold ready for Phase 1

---

## Phase 1 — MVP Core (Week 3–8)

**Objective:** One user can connect X, get AI reply drafts in-feed, and see basic analytics.

### 1.1 Infrastructure
- [ ] Turborepo monorepo: `apps/web`, `apps/extension`, `packages/shared`, `packages/ui`
- [ ] PostgreSQL schema v1 + migrations (Drizzle or Prisma)
- [ ] Redis for sessions + rate limit counters
- [ ] CI: lint, typecheck, unit tests on every PR
- [ ] Staging environment + preview deploys

### 1.2 Auth & X Connect
- [ ] User signup/login (email or OAuth)
- [ ] X OAuth 2.0 PKCE flow
- [ ] Encrypted token storage + refresh job
- [ ] Account settings: niche, voice adjectives, sample posts

### 1.3 Data Ingest
- [ ] Background job: fetch last 60 days of user's tweets + public metrics
- [ ] Store baseline aggregates (avg likes, RTs, replies, best hours)
- [ ] Idempotent ingest with progress UI

### 1.4 AI Reply Engine (v0)
- [ ] Prompt pipeline: context (tweet + user voice + niche) → 4 draft replies
- [ ] Streaming response to extension
- [ ] Token/cost logging per request
- [ ] Content moderation filter on outputs

### 1.5 Chrome Extension (v0)
- [ ] MV3 manifest, content script on `x.com`
- [ ] "GrowthApp" button under tweets → side panel with drafts
- [ ] User edits draft → copies to reply box (manual post)
- [ ] Extension ↔ API auth (secure token handoff)
- [ ] Performance: inject UI < 50ms (measure with Performance API)

### 1.6 Web Dashboard (v0)
- [ ] Onboarding wizard
- [ ] Baseline stats card (followers, avg engagement, posts/week)
- [ ] Connected account status + re-auth flow
- [ ] Usage meter (drafts used today)

### MVP Exit Criteria
- End-to-end demo: sign up → connect X → ingest → draft reply in extension → see stats on dashboard
- p95 draft latency < 1.5s (optimize in Phase 2)
- 5 internal alpha testers daily active for 1 week

---

## Phase 2 — Growth Engine v1 (Week 9–14)

**Objective:** Scheduling, predictions, daily plans, and follower tracking.

### 2.1 Scheduling System
- [ ] Create/edit/delete scheduled posts
- [ ] BullMQ worker fires at exact timestamp via X API
- [ ] Retry + dead-letter queue on X API failures
- [ ] User notification on post success/failure
- [ ] Scheduler accuracy ± 2 seconds (load test 1000 posts)

### 2.2 Engagement Prediction
- [ ] Model v1: user's historical averages × text-shape multipliers (hooks, length, numbers, format)
- [ ] Show predicted range on each draft
- [ ] Backtest on user's last 30 posts; display accuracy metric internally

### 2.3 Tweet & Thread Writer
- [ ] Dashboard: rough idea → 3 tweet variants + 1 thread outline
- [ ] Schedule directly from writer
- [ ] Thread composer with character/segment validation

### 2.4 Follower Tracking
- [ ] Daily snapshot job (follower IDs)
- [ ] Diff: new followers / unfollowers list
- [ ] 7d/30d net growth charts (TimescaleDB)

### 2.5 Daily Growth Plan
- [ ] Generate daily checklist: 5 accounts to engage, 3 thread targets, best post window
- [ ] Push to dashboard + optional extension badge

### 2.6 Safety Layer v1
- [ ] Per-account rate budget (configurable caps)
- [ ] Warning UI at 80% daily budget
- [ ] Audit log table for all API actions

### Exit Criteria
- Users scheduling 3+ posts/week without failures
- Prediction within 30% of actual on 60% of drafts (internal benchmark)
- Zero missed scheduled posts in 7-day soak test

---

## Phase 3 — Intelligence & Speed (Week 15–20)

**Objective:** Smart feed, competitor intel, and world-class latency.

### 3.1 Smart Feed Scanner
- [ ] Extension: highlight high-opportunity tweets in home + list feeds
- [ ] Opportunity score algorithm (recency, velocity, author reach, topic match)
- [ ] User feedback loop (thumbs up/down) to tune ranking

### 3.2 Performance Hardening
- [ ] API p95 < 200ms for cached reads
- [ ] Reply draft p95 < 800ms (prompt caching, model routing, edge)
- [ ] CDN for dashboard static assets
- [ ] DB query optimization + read replicas if needed
- [ ] Load test: 500 concurrent draft requests

### 3.3 Competitor / Creator Intel
- [ ] Track 5 competitor handles per user
- [ ] Top tweets, format mix, posting heatmap
- [ ] "Steal this structure" remix into user's voice

### 3.4 Weekly AI Recap
- [ ] Email/in-app: growth summary + 3 experiments for next week
- [ ] Export PDF for agency clients (optional)

### 3.5 Billing & Plans
- [ ] Stripe subscriptions (Free / Pro / Growth)
- [ ] Feature gates + usage metering
- [ ] 7-day trial flow

### Exit Criteria
- Public beta launch (50–100 users)
- Core Web Vitals green on dashboard
- Support docs + privacy policy + ToS published

---

## Phase 4 — Scale & Moat (Week 21–28)

**Objective:** Multi-account, team features, API, and operational excellence.

### 4.1 Multi-Account & Teams
- [ ] Up to 3–10 X accounts per workspace
- [ ] Role-based access (owner, editor, viewer)
- [ ] Agency dashboard: client switcher

### 4.2 Public API
- [ ] REST API for drafts, schedule, analytics (API keys)
- [ ] Rate limits per tier
- [ ] OpenAPI spec + developer docs

### 4.3 Advanced Analytics
- [ ] Funnel: impression → profile click → follow
- [ ] Cohort retention of *your* followers
- [ ] Export CSV / webhook integrations (Zapier)

### 4.4 Evergreen & Viral Engine
- [ ] Auto-surface top historical posts for repost/recycle
- [ ] "Viral engine": generate variations of proven winners

### 4.5 Reliability & Compliance
- [ ] 99.9% uptime SLO with status page
- [ ] GDPR: data export + deletion
- [ ] SOC2-ready logging (if targeting agencies)

### Exit Criteria
- v1.0 production launch
- Paying customers with < 2% monthly churn (early signal)
- Documented runbooks for on-call

---

## Phase 5 — World-Class Polish (Ongoing)

**Objective:** Sustain leadership in performance and growth outcomes.

- [ ] Firefox + Edge extension ports
- [ ] Mobile companion app (read-only analytics + approve drafts)
- [ ] Fine-tuned voice model per user (LoRA / persistent embedding store)
- [ ] Real-time collaborative drafting for teams
- [ ] A/B test framework for hooks and post times
- [ ] Community templates marketplace
- [ ] Localization (top 5 languages)

---

## Milestone Summary

| Milestone | Target | Key Proof |
|-----------|--------|-----------|
| **M0** Spec locked | Week 2 | MVP feature sign-off |
| **M1** MVP | Week 8 | Alpha users drafting in-feed |
| **M2** v1 engine | Week 14 | Scheduling + predictions live |
| **M3** Public beta | Week 20 | 100 users, billing on |
| **M4** v1.0 GA | Week 28 | Paid growth + 99.9% uptime |
| **M5** Market leader | Month 12+ | Best-in-class latency + retention benchmarks |

---

*Archived. For current milestones see [DEVELOPMENT_PLAN.md](DEVELOPMENT_PLAN.md).*
