<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain errors for GLO L6/N3 sales, result import, reconciliation and the
 * stress harness. Messages never embed secrets, credentials or raw upstream
 * documents.
 */
class GloSalesException extends RuntimeException
{
    public static function seatAlreadySeated(string $seatKey): self
    {
        return new self('Sales seat already seated: '.$seatKey, 409);
    }

    public static function seatConflict(string $seatKey, string $gate): self
    {
        return new self('Sales conflict gated ('.$gate.') for seat '.$seatKey, 409);
    }

    public static function seatNotSeated(string $seatKey): self
    {
        return new self('Sales seat not found: '.$seatKey, 404);
    }

    public static function overCapacity(int $requested, int $capacity): self
    {
        return new self(sprintf('Requested %d exceeds capacity %d.', $requested, $capacity), 422);
    }

    public static function invalidUnits(string $reason): self
    {
        return new self('Invalid sales units: '.$reason, 422);
    }

    public static function poolNotComputable(string $reason): self
    {
        return new self('Pool not computable: '.$reason, 422);
    }

    public static function notConfigured(string $endpoint): self
    {
        return new self('NOT_CONFIGURED: official source unreachable or disabled ('.$endpoint.').', 503);
    }

    public static function importFailed(string $reason): self
    {
        return new self('Result import failed: '.$reason, 422);
    }

    public static function fixtureMissing(string $path): self
    {
        return new self('Fixture missing: '.$path, 404);
    }

    public static function reconciliationBlocked(string $gate): self
    {
        return new self('Reconciliation blocked by conflict gate '.$gate, 409);
    }

    public static function unauthorized(string $action): self
    {
        return new self('Not authorized to '.$action.' GLO sales data.', 403);
    }

    public static function invalidConfiguration(string $key, string $reason): self
    {
        return new self('Invalid configuration ['.$key.']: '.$reason, 500);
    }
}
