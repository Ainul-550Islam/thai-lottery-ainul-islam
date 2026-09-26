<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Domain errors for the GLO prize claim / payment-hold lifecycle (GLO-12/14).
 */
class GloClaimException extends Exception
{
    public static function ticketNotFound(): self
    {
        return new self('Claim refused: no matching GLO ticket.', 404);
    }

    public static function notAWinner(): self
    {
        return new self('Claim refused: the ticket is not a verified winner for that draw.', 422);
    }

    public static function resultNotVerified(): self
    {
        return new self('Claim refused: the official result for this draw is not verified/published.', 422);
    }

    public static function settlementNotFinal(): self
    {
        return new self('Claim refused: draw settlement is not final.', 422);
    }

    public static function frozenTicket(): self
    {
        return new self('Claim blocked: the ticket is under an active GLO freeze (FROZEN_TICKET).', 423);
    }

    public static function paymentHoldActive(): self
    {
        return new self('Payment blocked: an active payment hold exists for this ticket.', 423);
    }

    public static function underAge(int $age, int $minimum): self
    {
        return new self(sprintf('Payment denied: claimant age %d is below the official minimum of %d.', $age, $minimum), 422);
    }

    public static function ageUnverifiable(): self
    {
        return new self('Payment denied: age cannot be verified from an authoritative date of birth.', 422);
    }

    public static function identityNotVerified(): self
    {
        return new self('Claim refused: claimant identity is not verified.', 422);
    }

    public static function claimWindowExpired(string $deadline): self
    {
        return new self('Claim refused: the two-year claim period ended at '.$deadline.'.', 422);
    }

    public static function duplicateClaim(string $existingReference): self
    {
        return new self('A claim already exists for this ticket and claimant: '.$existingReference, 409);
    }

    public static function alreadyPaid(string $claimReference): self
    {
        return new self('ALREADY_PAID: claim '.$claimReference.' has been paid.', 409);
    }

    public static function illegalTransition(string $from, string $to): self
    {
        return new self(sprintf('Illegal claim transition %s → %s is not permitted.', $from, $to), 422);
    }

    public static function notApproved(): self
    {
        return new self('Payment refused: claim is not in APPROVED state.', 422);
    }

    public static function stampDutyMissing(): self
    {
        return new self('Payment refused: stamp duty could not be calculated.', 422);
    }

    public static function unauthorized(string $action): self
    {
        return new self('Not authorized to '.$action.' a GLO prize claim.', 403);
    }

    public static function notFound(string $reference): self
    {
        return new self('Claim not found: '.$reference, 404);
    }

    public static function originalTicketRequired(): self
    {
        return new self('Claim refused: physical channels require original ticket evidence.', 422);
    }

    public static function legalConflictUnresolved(string $gate): self
    {
        return new self('Payment refused: unresolved legal conflict ('.$gate.').', 423);
    }
}
