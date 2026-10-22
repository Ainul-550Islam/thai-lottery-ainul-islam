<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Form honeypot
|--------------------------------------------------------------------------
|
| `mode` is `log` (observe only) or `enforce` (reject suspicious submissions).
| Enforcement is an explicit operator decision; the default observes.
| `ttl_seconds` is how long a rendered form stays valid (1 to 3600).
| `max_forms` bounds the forms kept per session (1 to 50).
*/

return [
    'enabled' => (bool) env('HONEYPOT_ENABLED', true),
    'mode' => env('HONEYPOT_MODE', 'log'),
    'ttl_seconds' => (int) env('HONEYPOT_TTL_SECONDS', 600),
    'max_forms' => (int) env('HONEYPOT_MAX_FORMS', 20),
];
