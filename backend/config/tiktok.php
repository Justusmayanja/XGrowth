<?php

return [

    // Local development only. Production ignores this and keeps the Redis render worker.
    'render_sync' => filter_var(env('TIKTOK_RENDER_SYNC', false), FILTER_VALIDATE_BOOLEAN),

];
