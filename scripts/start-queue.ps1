# Run all pending queue jobs (database or redis driver)
Set-Location "$PSScriptRoot\..\backend"
Write-Host "Mackie17 queue worker"
php artisan queue:work --sleep=3 --tries=3
