# Xpulse

> **Grow on X when the moment is right** — watches trends in your niche, scores opportunities, drafts content in your voice, and keeps your account growing while you're offline.

**Not a scheduler. Built for opportunity.**

## Quick start

```powershell
# Terminal 1 — API
cd backend
php artisan serve

# Terminal 2 — Frontend
cd apps/web
npm run dev

# Terminal 3 — Scheduler (optional)
.\scripts\start-scheduler.ps1
```

- **App:** http://localhost:3000  
- **API:** http://127.0.0.1:8000/api/health  

See [LOCAL_DEV.md](LOCAL_DEV.md) for full setup (SQLite, Docker, PostgreSQL).

## Documentation

| File | Contents |
|------|----------|
| [**DEVELOPMENT_PLAN.md**](docs/DEVELOPMENT_PLAN.md) | **Source of truth** — phases, status, API routes, next steps |
| [**PROJECT_FINDINGS.md**](docs/PROJECT_FINDINGS.md) | Product spec, architecture, pricing, X API policy text |
| [**LOCAL_DEV.md**](LOCAL_DEV.md) | Run locally on Windows/XAMPP |
| [PROJECT_SPEC.md](docs/PROJECT_SPEC.md) | High-level overview |
| [ROADMAP.md](docs/ROADMAP.md) | Archived — superseded by DEVELOPMENT_PLAN |

## Stack

| Layer | Tech |
|-------|------|
| Frontend | Next.js 15, TypeScript, Tailwind, Shadcn UI |
| Backend | Laravel 12, PHP 8.2+, Sanctum |
| Database | SQLite (local) → PostgreSQL 16 (Docker) |
| Queue | Database (local) → Redis 7 (Docker) |
| AI / X / Billing | Final API stage (OpenAI, X OAuth, Paddle + Flutterwave) |

## Status (July 2026)

**Stage A — Product shell:** ✅ Mock MVP complete (all app pages, Growth Engine, trends, engagement, analytics, safety)

**Stage B — Infrastructure:** In progress — git initialized, mobile nav, scheduler scripts; staging + GitHub push pending

**Stage C — Final API stage:** Not started — X OAuth, OpenAI, live posting, billing

## Core idea

```
Observe → Analyze → Predict → Generate → Decide → Act → Learn
```

Posts when **opportunity score > threshold** — not on fixed schedules.

## Source

[Idea originated in ChatGPT](https://chatgpt.com/share/6a5f14b2-6e70-83ea-8c09-868d8d57c5c1) — extracted into `docs/PROJECT_FINDINGS.md` and `docs/SOURCE_CONVERSATION.md`.
