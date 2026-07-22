<?php

return [

    'web_search' => [
        'enabled' => env('OPENAI_WEB_SEARCH_ENABLED', true),
        'cost_per_search_usd' => 0.01,
        'estimated_search_input_tokens' => 8000,
    ],

    'growth_brain' => [
        'enabled' => env('OPENAI_GROWTH_BRAIN_ENABLED', true),
        // Rule-based prefilter before AI — keeps prompts small.
        'prefilter_trend_limit' => 5,
        // Skip AI re-evaluation if last run was within this many minutes (scheduler).
        'min_minutes_between_evaluations' => (int) env('OPENAI_GROWTH_EVAL_COOLDOWN', 30),
        'max_output_tokens' => 400,
    ],

    'defaults' => [
        'daily_ai_generations' => 30,
        'daily_web_searches' => 5,
    ],

];
