<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trend catalog refresh schedule
    |--------------------------------------------------------------------------
    |
    | X API trend fetches are expensive — refresh only a few times per day.
    | Times are server local time (app timezone).
    |
    */
    'fetch_times' => array_filter(array_map('trim', explode(',', env(
        'TREND_FETCH_TIMES',
        '06:00,14:00,22:00'
    )))),

];
