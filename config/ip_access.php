<?php

declare(strict_types=1);

$rawBlockedRanges = env('IP_ACCESS_BLOCKED_RANGES', '');

return [
    'enabled' => filter_var(env('IP_ACCESS_ENABLED', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
    'mode' => env('IP_ACCESS_MODE', 'log'),
    'database_enabled' => filter_var(env('IP_ACCESS_DATABASE_ENABLED', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
    'management_enabled' => filter_var(env('IP_ACCESS_MANAGEMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
    'blocked_ranges' => is_string($rawBlockedRanges) && trim($rawBlockedRanges) !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $rawBlockedRanges)), static fn (string $entry): bool => $entry !== ''))
        : [],
];
