# GrowthApp — Product Specification

**Version:** 0.1 (draft — pending original ChatGPT conversation paste)  
**Codename:** GrowthApp  
**Platform:** X (Twitter)  
**Source:** [ChatGPT share](https://chatgpt.com/share/6a5f14b2-6e70-83ea-8c09-868d8d57c5c1)

---

## 1. Executive Summary

GrowthApp is a **high-performance X account growth platform** combining:

- A **Chrome extension** for in-feed speed (replies, drafts, opportunity detection)
- A **web dashboard** for analytics, content planning, scheduling, and account health
- An **AI engine** trained on the user's voice, niche, and historical performance
- A **safety layer** that respects X limits and keeps accounts out of spam/shadowban territory

**Differentiator:** World-class *system* performance (latency, reliability) *and* *growth* performance (measurable follower/engagement lift grounded in the user's own data).

---

## 2. Problem Statement

Growing on X today is:

| Pain | Why it matters |
|------|----------------|
| Time-consuming | Replying and posting consistently takes 1–3 hours/day |
| Guesswork | Most tools use generic benchmarks, not *your* audience |
| Fragmented | Scheduling, analytics, AI writing, and engagement live in separate apps |
| Risky | Aggressive automation gets accounts restricted or banned |
| Slow tools | Tab-switching and laggy UIs kill momentum in the feed |

---

## 3. Target Users

### Primary
- Indie hackers & SaaS founders building in public
- Creators, coaches, consultants monetizing via audience
- Solo operators who need compound growth without a social team

### Secondary
- Small agencies managing 3–10 client accounts
- Community managers for dev tools / AI products

### Non-goals (v1)
- Enterprise multi-brand command centers (Phase 4+)
- Fully unsupervised bot farms (violates X ToS and our safety principles)

---

## 4. Product Pillars

### Pillar A — Engagement Intelligence
Find the right conversations *before* they peak.

- Smart feed scanner: surface high-momentum posts in user's niche
- Opportunity score per tweet (recency, author reach, reply velocity, topic fit)
- Daily growth plan: who to engage, what threads to join, optimal windows
- Competitor/creator intel: what's working in the niche (formats, hooks, timing)

### Pillar B — AI Content & Replies (Voice-Matched)
Write faster without sounding generic.

- In-feed reply drafts (4+ angles per tweet) — user reviews before posting
- Original tweet & thread writer from rough notes
- Voice profile: tone adjectives, sample posts, banned phrases, niche keywords
- Engagement prediction range per draft based on *user's last 60–90 days* of data
- Remix/proven-format engine: adapt high-performing structures to new ideas

### Pillar C — Scheduling & Publishing
Post at the exact right second.

- Schedule to the second (not "±5 minutes")
- Queue with drag-and-drop calendar
- Best-time recommendations from personal analytics
- One-click "Post on X" from dashboard (OAuth, user-initiated only)
- Evergreen recycling for top performers (Phase 2+)

### Pillar D — Analytics & Growth Tracking
Measure what actually moves the needle.

- Follower delta: 24h / 7d / 30d / 1y with who followed/unfollowed
- Tweet-level: impressions, likes, RTs, replies, profile clicks, engagement rate
- Weekly recap & growth narrative (AI-generated insights)
- Funnel: impressions → profile visits → follows → DMs/leads (manual tag v1)
- A/B hooks and format mix over time

### Pillar E — Safety & Compliance
Grow sustainably.

- Rate-limit budget per account (likes, replies, follows, DMs)
- Human-in-the-loop default: AI suggests, human approves
- Activity pattern randomization (no robotic intervals)
- Shadowban/risk alerts before thresholds
- Audit log of every action taken via the product
- Clear ToS: no spam, no impersonation, no coordinated inauthentic behavior

---

## 5. Core User Flows

### Flow 1 — First-Time Setup (~15 min)
1. Sign up → connect X via OAuth 2.0
2. Define niche, audience, voice (adjectives + 3 sample posts)
3. Install Chrome extension
4. Background job: ingest last 60 days of tweets + public metrics
5. Dashboard shows baseline analytics + first daily growth plan

### Flow 2 — Daily Engagement (5–15 min)
1. Open X → extension highlights high-opportunity tweets in feed
2. User picks tweet → enters rough take → gets 4 reply drafts
3. Edit → post manually (or approve scheduled reply if enabled)
4. Streak/goals update; safety meter shows remaining daily budget

### Flow 3 — Weekly Content Batch (<30 min)
1. Enter 3 rough topics in dashboard
2. AI returns 5 tweets + 2 thread outlines with predicted engagement ranges
3. User edits → schedules across the week at recommended times
4. Posts fire via OAuth at scheduled time

### Flow 4 — Growth Review (weekly)
1. Dashboard recap: net followers, top tweets, best hours, unfollowers
2. AI suggests 3 experiments for next week (format, hook, posting time)
3. User accepts/adjusts → feeds back into next week's plan

---

## 6. Feature Matrix (MVP → Full)

| Feature | MVP | v1.0 | v2.0 |
|---------|-----|------|------|
| X OAuth connect | ✓ | ✓ | ✓ |
| Chrome extension (reply assist) | ✓ | ✓ | ✓ |
| Voice profile | ✓ | ✓ | ✓ |
| Personal analytics ingest | ✓ | ✓ | ✓ |
| Reply draft generation | ✓ | ✓ | ✓ |
| Tweet scheduling | — | ✓ | ✓ |
| Engagement prediction | basic | ✓ | ✓ |
| Follower tracking | — | ✓ | ✓ |
| Smart feed scanner | — | ✓ | ✓ |
| Competitor intel | — | — | ✓ |
| Multi-account (3+) | — | — | ✓ |
| Team/agency seats | — | — | ✓ |
| API for power users | — | — | ✓ |

---

## 7. Technical Architecture

### High-Level

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│ Chrome Extension│────▶│  API Gateway     │────▶│  Core Services  │
│ (MV3, React)    │     │  (REST + WS)     │     │  (Node/Bun)     │
└─────────────────┘     └──────────────────┘     └────────┬────────┘
                                                          │
         ┌────────────────────────────────────────────────┼────────────────────┐
         ▼                        ▼                       ▼                    ▼
   ┌───────────┐           ┌────────────┐          ┌────────────┐       ┌────────────┐
   │ PostgreSQL│           │ Redis      │          │ Job Queue  │       │ LLM Router │
   │ + Timescale│          │ cache/RL   │          │ (BullMQ)   │       │ (multi)    │
   └───────────┘           └────────────┘          └────────────┘       └────────────┘
```

### Stack (proposed — confirm against original chat)

| Layer | Choice | Why |
|-------|--------|-----|
| Monorepo | Turborepo + pnpm | Fast builds, shared types |
| Web app | Next.js 15 (App Router) | SSR dashboard, API routes |
| Extension | Chrome MV3 + React + Vite | In-feed speed, HMR |
| API | Bun or Node 22 + Hono/Fastify | Throughput, low latency |
| DB | PostgreSQL 16 + TimescaleDB | Relational + time-series metrics |
| Cache | Redis 7 | Sessions, rate limits, hot analytics |
| Queue | BullMQ | Scheduling, ingest, AI jobs |
| Auth | Clerk or Auth.js + X OAuth | Fast auth, X token storage encrypted |
| AI | Pluggable (OpenAI, Anthropic, local) | Cost/latency routing |
| Observability | OpenTelemetry + Grafana | p99 tracking from day 1 |
| Hosting | Vercel (web) + Fly.io/Railway (API/workers) | Global edge + long-running jobs |

### Performance Targets (world-class bar)

| Metric | Target |
|--------|--------|
| Extension inject UI | < 50ms after tweet visible |
| Reply draft API (p95) | < 800ms |
| Dashboard page TTFB | < 200ms |
| Analytics query (30d) | < 300ms (cached) |
| Scheduler fire accuracy | ± 2 seconds |
| Uptime | 99.9% |
| Data durability | Zero loss on scheduled posts |

### X Integration Strategy

1. **Official X API v2** for: post, schedule (where supported), read metrics, follower lists
2. **Extension (DOM)** for: in-feed UX, draft injection, opportunity highlighting — *no auto-actions without explicit user click*
3. **Never** store X passwords; OAuth tokens encrypted at rest (AES-256-GCM, KMS-backed)

---

## 8. Data Model (sketch)

```
User
  └── XAccount (oauth tokens, handle, niche, voice_profile)
        ├── Tweet (content, metrics, predicted vs actual)
        ├── ScheduledPost
        ├── EngagementEvent (reply, like — user-initiated)
        ├── FollowerSnapshot (daily)
        ├── DailyGrowthPlan
        └── RateLimitBudget (rolling windows)
```

---

## 9. Monetization (proposed)

| Tier | Price | Includes |
|------|-------|----------|
| Free | $0 | 1 account, 10 AI drafts/day, basic analytics |
| Pro | ~$29–49/mo | 1 account, unlimited drafts, scheduling, full analytics |
| Growth | ~$97/mo | 3 accounts, competitor intel, priority AI |
| Agency | custom | 10+ accounts, team seats, API |

*Adjust after validating against original ChatGPT spec.*

---

## 10. Success Metrics

### Product / Growth KPIs
- D7 retention > 40%
- Avg daily engagement actions per active user > 5
- Median follower growth +15% in 30 days (self-reported cohort)
- NPS > 50 at v1.0

### Engineering KPIs
- p99 API latency within targets (see §7)
- Error rate < 0.1% on post/schedule paths
- Zero security incidents on token storage

---

## 11. Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| X API policy changes | Extension-first UX; abstract X client behind adapter |
| Account bans from automation | Human-in-the-loop default; strict rate budgets |
| AI cost at scale | Cache embeddings; smaller models for classification; batch jobs |
| Competitors (Hypefury, Tweet Hunter, XGrowth) | Win on in-feed speed + personal prediction + safety |

---

## 12. Open Questions (needs original chat)

1. Full automation allowed or co-pilot only?
2. Chrome-only or also Firefox/Edge?
3. Mobile app in scope?
4. Specific niches to optimize for first (AI, SaaS, crypto, etc.)?
5. Self-hosted / open-source component?

---

*Last updated: 2026-07-21 — Update after `SOURCE_CONVERSATION.md` is filled.*
