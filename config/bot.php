<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Bot Detection Operating Mode
    |--------------------------------------------------------------------------
    | 'log_only'  : Evaluates signals, logs telemetry, does not block clients.
    | 'challenge' : Issues CAPTCHA challenge on medium/high risk scores.
    | 'enforce'   : Rejects requests exceeding block threshold.
    */
    'mode' => env('BOT_DETECTION_MODE', 'log_only'),

    'policy_version' => '1.0.0',

    'challenge_threshold' => (int) env('BOT_SCORE_CHALLENGE_THRESHOLD', 45),

    'block_threshold' => (int) env('BOT_SCORE_BLOCK_THRESHOLD', 80),

    'fingerprint_hmac_key' => env('BOT_FINGERPRINT_HMAC_KEY'),

];
