# Render queue

Short jobs stay on the database queue. `schedule:run` drains that queue for at most 50 seconds. A motion render does not fit that drain.

`RenderClipJob` uses the Redis connection `renders`:

- queue name `renders`
- `retry_after` 900, which must stay above the 600 second job timeout
- `uniqueFor` 900, keyed by clip id
- tries 2, backoff 30 then 120 seconds
- `afterCommit`

`REDIS_QUEUE_RETRY_AFTER` on the default `redis` connection is a different setting. Do not lower `renders.retry_after` below 900.

If Redis cannot accept the job, `run()` and `confirm()` leave the clip status unchanged and the render is marked failed with "Our video maker is busy. Try again."

## Local sync

`TIKTOK_RENDER_SYNC=true` forces the `renders` connection to `sync` when `APP_ENV` is not `production`. `tiktok:run`, `POST /api/tiktok/run`, and setup completion then render the MP4 before the request returns. The request holds for the whole encode, often a couple of minutes. Opening the dashboard still starts nothing. `/dev/tiktok-states` shows layout only and will not play a generated file.

If the flag is true in production, it is ignored and one warning is logged: `TIKTOK_RENDER_SYNC ignored in production`. Production uses the Redis worker below.

Set `TIKTOK_FFMPEG` and `TIKTOK_FFPROBE` to the full binary path when they are not on `PATH`.

## Worker

One or two processes. Restart them on deploy with `php artisan queue:restart`.

```
php artisan queue:work redis --queue=renders,default --max-time=3600 --tries=2 --sleep=1
```

Supervisor sample (`/etc/supervisor/conf.d/mackie17-renders.conf`):

```
[program:mackie17-renders]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/mackie17/backend/artisan queue:work redis --queue=renders,default --max-time=3600 --tries=2 --sleep=1
directory=/var/www/mackie17/backend
autostart=true
autorestart=true
stopwaitsecs=700
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/mackie17-renders.log
```

`stopwaitsecs` stays above the job timeout so a deploy does not kill a render mid-file. Run `php artisan queue:restart` after the new code is in place, then let Supervisor start the new processes.

ffmpeg is the Ubuntu apt package (`ffmpeg`), not the snap build.
