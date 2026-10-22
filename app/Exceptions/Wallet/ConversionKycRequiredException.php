<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletConversionRejectionReason;
use Throwable;

/**
 * The member has not completed identity verification.
 *
 * WHY CONVERSION IS GATED ON KYC AT ALL
 * A conversion lane is the cheapest way an unverified account can move value:
 * deposit into Cash, convert into Win, and the money is now indistinguishable
 * from winnings at the withdrawal gate. Gating the lane on verification closes
 * that path and keeps the withdrawal-side KYC check from being the only one
 * standing. The gate is configurable (conversions.gates.require_kyc) so an
 * operator can relax it deliberately — but the default is closed.
 *
 * The exception carries the member's current verification status and the
 * document types still outstanding when the service knows them, because the
 * difference between "you have not started" and "your document was rejected"
 * is the difference between a two-minute task and a support ticket.
 */
final class ConversionKycRequiredException extends ConversionException
{
    public const ERROR_CODE = 'kyc_not_verified';

    /**
     * @param  array<int, string>  $outstandingDocuments
     */
    public function __construct(
        private readonly int $userId,
        private readonly ?string $verificationStatus,
        private readonly array $outstandingDocuments = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Identity verification must be completed before wallet balances can be converted.',
            self::ERROR_CODE,
            [
                'user_id' => $userId,
                'verification_status' => $verificationStatus,
                'outstanding_documents' => $outstandingDocuments,
            ],
            $previous,
        );
    }

    /**
     * @param  array<int, string>  $outstandingDocuments
     */
    public static function forUser(
        int $userId,
        ?string $verificationStatus = null,
        array $outstandingDocuments = [],
        ?Throwable $previous = null,
    ): self {
        return new self($userId, $verificationStatus, $outstandingDocuments, $previous);
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function verificationStatus(): ?string
    {
        return $this->verificationStatus;
    }

    /**
     * @return array<int, string>
     */
    public function outstandingDocuments(): array
    {
        return $this->outstandingDocuments;
    }

    /**
     * True when verification was submitted and refused, rather than never
     * started. Changes the UI copy from "verify your account" to "your
     * document was rejected — resubmit".
     */
    public function wasRejected(): bool
    {
        return in_array($this->verificationStatus, ['rejected', 'failed'], true);
    }

    /**
     * True when a review is already pending, in which case the member must wait
     * rather than submit again.
     */
    public function isPendingReview(): bool
    {
        return in_array($this->verificationStatus, ['pending', 'in_review', 'submitted'], true);
    }

    /**
     * The route the member should be sent to in order to clear this refusal.
     */
    public function remedyRoute(): string
    {
        return 'account.verification';
    }

    public function rejectionReason(): ?WalletConversionRejectionReason
    {
        return WalletConversionRejectionReason::KycNotVerified;
    }
}
