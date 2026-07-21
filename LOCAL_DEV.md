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

### PostgreSQL + Redis (when Docker is installed)
```powershell
docker compose up -d
```

Update `backend/.env`:
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=xpulse
DB_USERNAME=xpulse
DB_PASSWORD=xpulse
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis
```

Then:
```powershell
cd backend
composer require predis/predis
php artisan migrate:fresh --seed
php artisan db:seed --class=MockTrendSeeder
```

Optional queue worker (with Redis):
```powershell
.\scripts\start-queue.ps1
```

---

## Useful commands

```powershell
cd backend
php artisan xpulse:seed-trends      # Seed mock trends
php artisan xpulse:evaluate         # Run growth evaluation for all users
php artisan schedule:list           # View scheduled tasks
```

---

## First-time user flow

1. Register at `/register`
2. Complete 6-step onboarding
3. Dashboard, Content Studio, Growth Engine, Engagement, Trends, Safety Center

---

## Deferred (final API stage)

- X OAuth + live X data
- OpenAI / live content pipeline
- Paddle + Flutterwave billing
