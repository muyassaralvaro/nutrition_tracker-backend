<?php

return [
    'default' => 'nine_router',
    'nutrition_enabled' => env('NUTRITION_AI_ENABLED', false),
    'daily_attempt_limit' => 50,

    'providers' => [
        'nine_router' => [
            'driver' => 'openai-compatible',
            'url' => env('NUTRITION_AI_URL'),
            'key' => env('NUTRITION_AI_KEY'),
            'models' => [
                'text' => ['default' => env('NUTRITION_AI_MODEL')],
            ],
        ],
    ],
];
