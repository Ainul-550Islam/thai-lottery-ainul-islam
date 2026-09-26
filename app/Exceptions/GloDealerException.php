<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain errors for GLO-15..18 dealer / sales-point / saved-ticket /
 * public-result surfaces. Messages never embed PII, credentials or
 * private legal detail.
 */
class GloDealerException extends RuntimeException
{
    public static function dealerNotFound(): self
    {
        return new self('Dealer profile not found.', 404);
    }

    public static function notActive(string $action): self
    {
        return new self('Dealer is not active; cannot '.$action.'.', 403);
    }

    public static function notDealer(): self
    {
        return new self('No dealer profile for the authenticated user.', 403);
    }

    public static function forbidden(string $action): self
    {
        return new self('Not authorized to '.$action.'.', 403);
    }

    public static function cannotSelfApprove(): self
    {
        return new self('A dealer may not approve its own change request.', 403);
    }

    public static function openRequestExists(string $type): self
    {
        return new self('An open '.$type.' request already exists.', 409);
    }

    public static function requestNotFound(): self
    {
        return new self('Change request not found.', 404);
    }

    public static function illegalTransition(string $from, string $to): self
    {
        return new self('Illegal change-request transition '.$from.' → '.$to.'.', 422);
    }

    public static function invalidRequestedValue(string $reason): self
    {
        return new self('Invalid requested value: '.$reason, 422);
    }

    public static function salesPointNotFound(): self
    {
        return new self('Sales point not found.', 404);
    }

    public static function duplicateLocationUpdate(string $date): self
    {
        return new self('A sales location for '.$date.' is already recorded.', 409);
    }

    public static function invalidCoordinates(string $reason): self
    {
        return new self('Invalid coordinates: '.$reason, 422);
    }

    public static function invalidDate(string $reason): self
    {
        return new self('Invalid date: '.$reason, 422);
    }

    public static function ticketNotFound(): self
    {
        return new self('Ticket not found.', 404);
    }

    public static function ticketNotOwned(): self
    {
        return new self('Ticket does not belong to this user.', 403);
    }

    public static function ticketNotEligible(string $reason): self
    {
        return new self('Ticket not eligible to save: '.$reason, 422);
    }

    public static function drawFinalized(): self
    {
        return new self('Draw is finalized; saving is no longer allowed.', 422);
    }

    public static function alreadySaved(): self
    {
        return new self('Ticket is already saved.', 409);
    }

    public static function notSaved(): self
    {
        return new self('Ticket is not currently saved.', 409);
    }

    public static function limitReached(int $max): self
    {
        return new self('Saved-ticket limit reached ('.$max.').', 422);
    }

    public static function invalidResultInput(string $reason): self
    {
        return new self('Invalid result query: '.$reason, 422);
    }

    public static function resultNotVerified(): self
    {
        return new self('Result is not verified for public display.', 422);
    }

    public static function dataMatrixUnsupported(string $reason): self
    {
        return new self('Data Matrix check unavailable: '.$reason, 503);
    }

    public static function historyWindowExceeded(string $reason): self
    {
        return new self('Requested history is outside the configured window: '.$reason, 422);
    }

    public static function liveDrawNotConfigured(): self
    {
        return new self('Live draw / replay provider is NOT_CONFIGURED.', 503);
    }
}
