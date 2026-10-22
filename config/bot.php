<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bot detection
|--------------------------------------------------------------------------
|
| Signal-based, deterministic bot assessment for the public and auth
| surfaces. Every score is a weighted sum of named signals against two
| thresholds; there is no heuristic black box, because a decision nobody
| can reproduce is a decision nobody can appeal or test.
|
| DEFAULT POSTURE IS OBSERVATION. `mode` stays `log` and HTTP observation
| stays OFF until an operator explicitly opts in with production traffic
| in view. Observation failures are recorded as "unavailable", never as a
| fabricated high score and never as a block.
|
*/

return [
    // Master switch. False = the engine answers an empty Allow without
    // resolving the database, the cache, or any signal store.
    'enabled' => env('BOT_ENABLED', true),

    // log = assess and record only. enforce = assessed blocks may refuse.
    'mode' => env('BOT_MODE', 'log'),

    // HTTP observation middleware. Deliberately OPT-IN (default false): a
    // deployment gets bot telemetry only when an operator asked for it.
    'http_observation' => [
        'enabled' => env('BOT_HTTP_OBSERVATION_ENABLED', false),
    ],

    // Score thresholds (BotScoreCalculator enforces challenge < block).
    'thresholds' => [
        'challenge' => 40,
        'block' => 80,
    ],

    // Registered signals. A signal answers only when 'enabled' is true;
    // 'weight' is its share of the 0-100 score when it fires at full
    // strength, and its own 'mode' decides whether it may enforce.
    'signals' => [
        'user_agent' => [
            'enabled' => env('BOT_SIGNAL_USER_AGENT_ENABLED', true),
            'mode' => 'log',
            'weight' => 15,
        ],
        'header_consistency' => [
            'enabled' => env('BOT_SIGNAL_HEADER_CONSISTENCY_ENABLED', true),
            'mode' => 'log',
            'weight' => 20,
        ],
        'form_timing' => [
            'enabled' => env('BOT_SIGNAL_FORM_TIMING_ENABLED', true),
            'mode' => 'log',
            'weight' => 25,
        ],
        'request_rate' => [
            'enabled' => env('BOT_SIGNAL_REQUEST_RATE_ENABLED', true),
            'mode' => 'log',
            'weight' => 35,
        ],
        'device_reuse' => [
            'enabled' => env('BOT_SIGNAL_DEVICE_REUSE_ENABLED', true),
            'mode' => 'log',
            'weight' => 30,
        ],
        // B1 FIX — TWO SIGNALS EXISTED, WERE BROKEN, AND WERE NEVER LISTED.
        //
        // FailedAuthenticationSignal and RequestVelocitySignal were written
        // against an older BotSignal contract (getKey/getWeight/evaluate) that
        // no longer matches the interface (score(): SignalResult). Because both
        // are concrete classes, PHP rejects them at CLASS LOAD with a fatal,
        // not an exception — the only reason they never took the site down is
        // that nothing ever loaded them, and nothing loaded them because they
        // were absent from this map.
        //
        // Both are now ported onto the current contract and registered here.
        // Both default to ENABLED = FALSE on purpose.
        //
        // Enabling a signal changes the score every request receives, and the
        // thresholds below (challenge 40 / block 80) were tuned against the
        // five signals that were actually running. Turning on two more, neither
        // of which has ever executed against real traffic, risks challenging or
        // blocking legitimate members — and a shared NAT or a mobile carrier
        // puts hundreds of real members behind one IP, which is exactly what
        // these two measure. Ship them on in 'log' mode first, read the scores
        // for a week, then decide.
        'failed_auth' => [
            'enabled' => env('BOT_SIGNAL_FAILED_AUTH_ENABLED', false),
            // Weak, shared-IP evidence: informs, never blocks on its own.
            'mode' => 'log',
            'weight' => 20,
        ],
        'request_velocity' => [
            'enabled' => env('BOT_SIGNAL_REQUEST_VELOCITY_ENABLED', false),
            'mode' => 'log',
            'weight' => 15,
        ],
    ],

    // Verified search-engine crawlers are identified by more than a
    // user-agent string (reverse DNS / published ranges). A UA-only claim
    // must never bypass assessment on its own.
    'search_bots' => [
        'allow_user_agent_only_bypass' => false,
    ],

    // Form-timing signal: a form submitted faster than a human can fill it
    // is evidence, not proof — hence a single signal among several.
    'form_timing' => [
        'field' => '_bot_timing',
        'min_submit_seconds' => 2,
        'ttl_seconds' => 600,
        'max_tokens_per_session' => 10,
    ],

    // Request-rate signal (per device fingerprint).
    'request_rate' => [
        'max_requests' => 60,
        'window_seconds' => 60,
    ],

    // Device-reuse signal: one fingerprint behind too many accounts.
    'device_reuse' => [
        'max_accounts' => 3,
        'lookback_days' => 30,
    ],

    // HMAC key for device fingerprints. Falls back to the app key inside
    // DeviceFingerprintService; a dedicated key lets fingerprints be
    // rotated without invalidating sessions.
    'fingerprint' => [
        'key' => env('BOT_FINGERPRINT_KEY'),
    ],

    // Security-event recording of non-Allow assessments.
    'audit' => [
        'enabled' => env('BOT_AUDIT_ENABLED', true),
        'min_score' => 1,
    ],
];
