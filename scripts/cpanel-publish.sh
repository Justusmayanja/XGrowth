#!/bin/bash
set -euo pipefail

STARTUP="${1:-apps/web/passenger.cjs}"
APP="/home/ytfucmimat/xpulse/backend"
PHP="/usr/local/bin/php"

mkdir -p \
  "$APP/storage/framework/cache/data" \
  "$APP/storage/framework/sessions" \
  "$APP/storage/framework/views" \
  "$APP/storage/logs" \
  "$APP/storage/app/public" \
  "$APP/bootstrap/cache"

chmod -R u+rwx "$APP/storage" "$APP/bootstrap/cache"

if [[ ! -f "$APP/.env" ]]; then
  echo "Missing $APP/.env" >&2
  exit 1
fi

rm -f \
  /home/ytfucmimat/mackie17.com/api/probe-ini.php \
  /home/ytfucmimat/mackie17.com/index.html \
  /home/ytfucmimat/mackie17.com/probe.txt \
  /home/ytfucmimat/public_html/probe.txt

cd "$APP"
"$PHP" artisan package:discover --ansi
"$PHP" artisan migrate --force
"$PHP" artisan storage:link || true
"$PHP" artisan config:cache

if cloudlinux-selector get --json --interpreter nodejs | grep -q 'xpulse/web'; then
  cloudlinux-selector set --json --interpreter nodejs \
    --app-root xpulse/web \
    --startup-file "$STARTUP" \
    --app-mode production
  cloudlinux-selector restart --json --interpreter nodejs --app-root xpulse/web
else
  cloudlinux-selector create --json --interpreter nodejs \
    --app-root xpulse/web \
    --app-uri / \
    --domain mackie17.com \
    --version 22 \
    --app-mode production \
    --startup-file "$STARTUP"
fi

CRON_LINE="* * * * * $PHP $APP/artisan schedule:run >> $APP/storage/logs/scheduler.log 2>&1"
(
  crontab -l 2>/dev/null | grep -v 'xpulse/backend/artisan schedule:run' || true
  echo "$CRON_LINE"
) | crontab -
