<?php

return [
    'version' => env('APP_VERSION', '0.1.0'),
    'demo_mode' => (bool) env('NASAQ_DEMO_MODE', true),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'ai_service' => [
        'url' => env('AI_SERVICE_URL', 'http://localhost:8001'),
        'token' => env('AI_SERVICE_TOKEN'),
    ],
    'internal_callback_token' => env('INTERNAL_CALLBACK_TOKEN'),
    'health' => [
        'check_dependencies' => (bool) env('HEALTH_CHECK_DEPENDENCIES', true),
    ],
];
