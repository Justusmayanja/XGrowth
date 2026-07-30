# Finish PostgreSQL + Redis setup after Docker Desktop is running.
# Run from repo root: .\scripts\setup-postgres.ps1

$ErrorActionPreference = "Stop"
$repoRoot = Split-Path $PSScriptRoot -Parent
Set-Location $repoRoot

Write-Host "Checking Docker..." -ForegroundColor Cyan
docker info | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Host "Docker is not ready. Start Docker Desktop, wait until it says 'Engine running', then rerun this script." -ForegroundColor Red
    exit 1
}

Write-Host "Starting PostgreSQL + Redis..." -ForegroundColor Cyan
docker compose up -d

Write-Host "Waiting for PostgreSQL health check..." -ForegroundColor Cyan
$ready = $false
for ($i = 1; $i -le 30; $i++) {
    $status = docker inspect --format='{{.State.Health.Status}}' xpulse-postgres 2>$null
    if ($status -eq "healthy") {
        $ready = $true
        break
    }
    Start-Sleep -Seconds 2
}

if (-not $ready) {
    Write-Host "PostgreSQL container did not become healthy in time. Check: docker compose ps" -ForegroundColor Yellow
}

Set-Location "$repoRoot\backend"

Write-Host "Running migrations..." -ForegroundColor Cyan
php artisan config:clear
php artisan migrate --force

Write-Host "Seeding mock trends..." -ForegroundColor Cyan
php artisan db:seed --class=MockTrendSeeder --force

Write-Host "Verifying connection..." -ForegroundColor Cyan
php artisan db:show

Write-Host ""
Write-Host "Done. PostgreSQL is ready." -ForegroundColor Green
Write-Host "Restart php artisan serve if it was already running."
