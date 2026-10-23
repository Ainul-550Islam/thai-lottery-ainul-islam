<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bot detection
|--------------------------------------------------------------------------
|
| Every signal ships in `log` mode. In log mode the score is recorded and an
| assessment is written, but no request is challenged or blocked. Moving a signal
| to `enforce` is a deliberate operator decision made after reviewing the logged
| assessments for false positives.
|
| Environment booleans are parsed with FILTER_VALIDATE_BOOLEAN. A plain (bool)
| cast would read the string "false" as true and silently enable the flag.
*/

$booleanEnv = static fn (string $key, bool $default): bool => filter_var(
    env($key, $default ? 'true' : 'false'),
    FILTER_VALIDATE_BOOLEAN,
);

return [

    'enabled' => $booleanEnv('BOT_ENABLED', true),

    'mode' => env('BOT_MODE', 'log'),

    'thresholds' => [
        // A score at or above `challenge` is assessed as BotDecision::Challenge.
        'challenge' => (int) env('BOT_CHALLENGE_THRESHOLD', 40),
        // A score at or above `block` is assessed as BotDecision::Block.
        'block' => (int) env('BOT_BLOCK_THRESHOLD', 80),
    ],

    // Key for keyed device fingerprints. Required before any signal that uses
    // fingerprints can be enforced. The value must be at least 32 bytes, or
    // base64:-prefixed.
    'fingerprint' => [
        'key' => env('BOT_FINGERPRINT_KEY'),
    ],

    'search_bots' => [
        // A bare, non-browser user agent must never bypass detection on its own.
        'allow_user_agent_only_bypass' => false,
    ],

    'audit' => [
        'enabled' => $booleanEnv('BOT_AUDIT_ENABLED', true),
        // Only assessments at or above this score are written to the security log.
        'min_score' => (int) env('BOT_AUDIT_MIN_SCORE', 40),
    ],

    // Records HTTP-level observations for each request. Off by default because it
    // adds a write per request.
    'http_observation' => [
        'enabled' => $booleanEnv('BOT_HTTP_OBSERVATION_ENABLED', false),
    ],

    'form_timing' => [
        'field' => env('BOT_FORM_TIMING_FIELD', '_bot_timing'),
        'min_submit_seconds' => (int) env('BOT_FORM_MIN_SUBMIT_SECONDS', 2),
        'ttl_seconds' => (int) env('BOT_FORM_TTL_SECONDS', 600),
        'max_tokens_per_session' => (int) env('BOT_FORM_MAX_TOKENS_PER_SESSION', 10),
    ],

    'device_reuse' => [
        'max_accounts' => (int) env('BOT_DEVICE_MAX_ACCOUNTS', 3),
        'lookback_days' => (int) env('BOT_DEVICE_LOOKBACK_DAYS', 30),
    ],

    'request_rate' => [
        'window_seconds' => (int) env('BOT_RATE_WINDOW_SECONDS', 60),
        'max_requests' => (int) env('BOT_RATE_MAX_REQUESTS', 60),
    ],

    'signals' => [
        'user_agent' => ['enabled' => true, 'mode' => 'log', 'weight' => 15],
        'header_consistency' => ['enabled' => true, 'mode' => 'log', 'weight' => 20],
        'form_timing' => ['enabled' => true, 'mode' => 'log', 'weight' => 25],
        'request_rate' => ['enabled' => true, 'mode' => 'log', 'weight' => 35],
        'device_reuse' => ['enabled' => true, 'mode' => 'log', 'weight' => 30],
    ],
];
