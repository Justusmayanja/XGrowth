<?php

/**
 * Platform prices are the shelf price for one platform on its own.
 * Minimums are private floors. A multi-platform cart keeps the most
 * expensive line at full price and takes discount_percent off every
 * other line, stopping at that line's minimum.
 *
 * Checkout uses BILLING_GATEWAY. Add that gateway's keys and the
 * webhook URL, and payments can run without further code changes.
 */
return [

    'gateway' => env('BILLING_GATEWAY', 'paddle'),

    'discount_percent' => 15,

    'yearly_months_charged' => 10,

    'webhook_tolerance_seconds' => 300,

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    'paddle' => [
        'api_key' => env('PADDLE_API_KEY'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        // sandbox until you set PADDLE_ENVIRONMENT=live
        'environment' => env('PADDLE_ENVIRONMENT', 'sandbox'),
        // Optional. Leave empty to create the product on each checkout.
        'product_id' => env('PADDLE_PRODUCT_ID'),
    ],

    'flutterwave' => [
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET'),
    ],

    'platforms' => [
        'x' => [
            'name' => 'X',
            'summary' => 'Posts when the moment is strong enough to travel.',
            'tiers' => [
                'starter' => [
                    'name' => 'Starter',
                    'list_cents' => 3200,
                    'minimum_cents' => 2500,
                    'accounts' => 1,
                    'allowance' => 40,
                    'allowance_unit' => 'posts',
                    'features' => [
                        '1 X account',
                        '40 posts included each month',
                        'Drafts wait for your yes',
                        'Basic analytics',
                    ],
                ],
                'pro' => [
                    'name' => 'Pro',
                    'list_cents' => 4900,
                    'minimum_cents' => 4000,
                    'accounts' => 3,
                    'allowance' => 150,
                    'allowance_unit' => 'posts',
                    'features' => [
                        '3 X accounts',
                        '150 posts included each month',
                        'Autopilot when the moment is right',
                        'Competitor tracking',
                    ],
                ],
                'agency' => [
                    'name' => 'Agency',
                    'list_cents' => 9900,
                    'minimum_cents' => 8000,
                    'accounts' => 10,
                    'allowance' => 600,
                    'allowance_unit' => 'posts',
                    'features' => [
                        '10 X accounts',
                        '600 posts included each month',
                        '5 seats and client workspaces',
                        'Reporting across clients',
                    ],
                ],
            ],
        ],
        'tiktok' => [
            'name' => 'TikTok',
            'summary' => 'Makes the clip, suggests a time, and waits for your yes.',
            'tiers' => [
                'starter' => [
                    'name' => 'Starter',
                    'list_cents' => 4900,
                    'minimum_cents' => 4000,
                    'accounts' => 1,
                    'allowance' => 8,
                    'allowance_unit' => 'clips',
                    'features' => [
                        '1 TikTok account',
                        '8 clips included each month',
                        'Suggests a time and waits for your yes',
                        'Basic analytics',
                    ],
                ],
                'pro' => [
                    'name' => 'Pro',
                    'list_cents' => 7900,
                    'minimum_cents' => 6500,
                    'accounts' => 3,
                    'allowance' => 24,
                    'allowance_unit' => 'clips',
                    'features' => [
                        '3 TikTok accounts',
                        '24 clips included each month',
                        'Suggests a time and waits for your yes',
                        'Competitor tracking',
                    ],
                ],
                'agency' => [
                    'name' => 'Agency',
                    'list_cents' => 14900,
                    'minimum_cents' => 12000,
                    'accounts' => 10,
                    'allowance' => 80,
                    'allowance_unit' => 'clips',
                    'features' => [
                        '10 TikTok accounts',
                        '80 clips included each month',
                        '5 seats and client workspaces',
                        'Reporting across clients',
                    ],
                ],
            ],
        ],
        'youtube' => [
            'name' => 'YouTube',
            'summary' => 'Makes the video, then posts it or waits for you.',
            'tiers' => [
                'starter' => [
                    'name' => 'Starter',
                    'list_cents' => 6900,
                    'minimum_cents' => 5500,
                    'accounts' => 1,
                    'allowance' => 4,
                    'allowance_unit' => 'videos',
                    'features' => [
                        '1 YouTube channel',
                        '4 videos included each month',
                        'Post on its own, or wait for you',
                        'Basic analytics',
                    ],
                ],
                'pro' => [
                    'name' => 'Pro',
                    'list_cents' => 10900,
                    'minimum_cents' => 9000,
                    'accounts' => 3,
                    'allowance' => 12,
                    'allowance_unit' => 'videos',
                    'features' => [
                        '3 YouTube channels',
                        '12 videos included each month',
                        'Post on its own, or wait for you',
                        'Competitor tracking',
                    ],
                ],
                'agency' => [
                    'name' => 'Agency',
                    'list_cents' => 19900,
                    'minimum_cents' => 16000,
                    'accounts' => 10,
                    'allowance' => 40,
                    'allowance_unit' => 'videos',
                    'features' => [
                        '10 YouTube channels',
                        '40 videos included each month',
                        '5 seats and client workspaces',
                        'Reporting across clients',
                    ],
                ],
            ],
        ],
    ],

];
