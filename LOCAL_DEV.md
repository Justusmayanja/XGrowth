# Run Xpulse locally

## Prerequisites

| Tool | Status |
|------|--------|
| PHP 8.2+ + Composer (XAMPP) | Required |
| Node.js 20+ | Required |
| Docker Desktop | Optional (PostgreSQL + Redis) |

---

## Quick start (3 terminals)

**Terminal 1 — API**
```powershell
cd backend
php artisan serve
```

**Terminal 2 — Frontend**
```powershell
cd apps/web
npm run dev
```

**Terminal 3 — Scheduler** (trends + growth evaluation, no Docker needed)
```powershell
.\scripts\start-scheduler.ps1
```

App: **http://localhost:3000**  
API: **http://127.0.0.1:8000/api/health**

---

## Database options

### SQLite (default, works now)
Already configured in `backend/.env` — no setup needed.

### PostgreSQL + Redis (recommended)

**One-time machine setup** (if Docker was just installed):
1. Install WSL2 if prompted: `wsl --install --no-distribution` (Admin PowerShell; reboot if asked)
2. Open **Docker Desktop** and wait until the engine is running

**Start services:**
```powershell
docker compose up -d
```

`backend/.env` should use PostgreSQL + Redis (see `.env.example`). Then finish setup:
```powershell
.\scripts\setup-postgres.ps1
```

Or manually:
```powershell
cd backend
php artisan migrate --force
php artisan db:seed --class=MockTrendSeeder --force
```

Optional queue worker (with Redis):
```powershell
.\scripts\start-queue.ps1
```

### SQLite (fallback, no Docker)
Set `DB_CONNECTION=sqlite` in `backend/.env` and use `QUEUE_CONNECTION=database` / `CACHE_STORE=database`.

---

## Useful commands

```powershell
cd backend
php artisan xpulse:make-admin your@email.com   # Grant admin access
php artisan xpulse:seed-trends      # Seed mock trends
php artisan xpulse:evaluate         # Run growth evaluation for all users
php artisan schedule:list           # View scheduled tasks
```

---

## First-time user flow

1. Register at `/register`
2. Complete 6-step onboarding
3. Dashboard, Content Studio, Growth Engine, Engagement, Trends, Safety Center
4. Admin dashboard at `/admin` (requires `php artisan xpulse:make-admin your@email.com`)

---

## Deferred (final API stage)

- X OAuth + live X data
- OpenAI / live content pipeline
- Paddle + Flutterwave billing
