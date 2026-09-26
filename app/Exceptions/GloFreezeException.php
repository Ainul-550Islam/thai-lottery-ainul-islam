<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Domain errors for the GLO ticket freeze / seizure state machine (GLO-11).
 * Messages never embed identity secrets, raw evidence or bank credentials.
 */
class GloFreezeException extends Exception
{
    public static function invalidTicket(string $reason): self
    {
        return new self('Freeze refused: ticket invalid — '.$reason, 422);
    }

    public static function missingEvidence(): self
    {
        return new self('Freeze refused: official evidence reference, authority and case reference are required before a final freeze.', 422);
    }

    public static function wrongDraw(int $expected, int $provided): self
    {
        return new self(sprintf('Freeze refused: ticket belongs to draw %d, not draw %d.', $expected, $provided), 422);
    }

    public static function illegalTransition(string $from, string $to): self
    {
        return new self(sprintf('Illegal freeze transition %s → %s is not permitted.', $from, $to), 422);
    }

    public static function unauthorized(string $action): self
    {
        return new self('Not authorized to '.$action.' a GLO freeze case.', 403);
    }

    public static function notFound(string $reference): self
    {
        return new self('Freeze case not found: '.$reference, 404);
    }

    public static function fingerprintConflict(string $fingerprint): self
    {
        return new self('An equivalent freeze request already exists (fingerprint '.$fingerprint.').', 409);
    }

    public static function concurrentModification(): self
    {
        return new self('Freeze case was modified concurrently; re-read and retry.', 409);
    }
}
