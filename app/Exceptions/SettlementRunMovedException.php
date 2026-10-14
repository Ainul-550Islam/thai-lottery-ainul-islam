<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Another worker advanced the run's cursor while this chunk was in flight.
 *
 * The chunk rolls back whole, so no money moved. It is deliberately NOT a
 * reason to abort the run: the run is healthy, it is simply being driven by
 * someone else right now. The queued job treats it as transient and retries.
 */
final class SettlementRunMovedException extends RuntimeException {}
