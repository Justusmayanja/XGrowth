# Start Xpulse background scheduler (trends + growth evaluation)
# Run in a separate terminal alongside `php artisan serve`
Set-Location "$PSScriptRoot\..\backend"
Write-Host "Xpulse scheduler - refreshes trends every 5 min, evaluates users every 10 min"
php artisan schedule:work
