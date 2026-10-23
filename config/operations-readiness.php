<?php

declare(strict_types=1);

/*
 * Alias for config/operations_readiness.php. The schedule spec reads this file's
 * key, so it is derived from the one source of truth rather than copied, and the
 * list cannot drift between the two files.
 */
$source = require __DIR__.'/operations_readiness.php';

return [
    'required_scheduled_commands' => $source['required_commands'] ?? [],
];
