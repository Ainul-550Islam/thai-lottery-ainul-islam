<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('BOT_ENABLED', true),
    'mode' => env('BOT_MODE', 'log'),
    'allow_user_agent_only_bypass' => false,
    'thresholds' => ['challenge' => 40, 'block' => 80],
    'signals' => [
        'user_agent' => ['enabled' => true, 'mode' => 'log', 'weight' => 50],
        'form_timing' => ['enabled' => true, 'mode' => 'log', 'weight' => 0],
        'header_consistency' => ['enabled' => true, 'mode' => 'log', 'weight' => 10],
        'request_rate' => ['enabled' => true, 'mode' => 'log', 'weight' => 20],
        'device_reuse' => ['enabled' => true, 'mode' => 'log', 'weight' => 20],
    ],
    'http_observation' => ['enabled' => (bool) env('BOT_HTTP_OBSERVATION_ENABLED', false)],
    'form_timing' => ['minimum_seconds' => 2, 'maximum_age_seconds' => 7200, 'max_forms' => 20],
    'request_rate' => ['window_seconds' => 60, 'max_requests' => 60],
    'device_reuse' => ['max_accounts' => 3, 'lookback_days' => 30],
    'fingerprint' => ['enabled' => true, 'key' => env('BOT_FINGERPRINT_KEY')],
    'search_bots' => ['enabled' => false, 'allow_user_agent_only_bypass' => false],
];
