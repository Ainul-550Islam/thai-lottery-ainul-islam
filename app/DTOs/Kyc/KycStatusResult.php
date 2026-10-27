<?php

declare(strict_types=1);

namespace App\DTOs\Kyc;

/**
 * Typed answer to "what is this member's identity state?".
 *
 * The vocabulary is the platform's own public one (the six strings in
 * config('account.verification.statuses')), not any provider's. A provider that
 * answers with something else is mapped by its adapter, at the adapter, so no
 * page in this application ever has to interpret a third-party word.
 *
 * `decidedAt` and `expiresAt` are nullable because "no decision yet" is a real
 * state and must not be rendered as a date.
 */
final class KycStatusResult
{
    public const NOT_SUBMITTED = 'NOT_SUBMITTED';

    public const PENDING = 'PENDING';

    public const UNDER_REVIEW = 'UNDER_REVIEW';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const EXPIRED = 'EXPIRED';

    /** The closed set of states this type will hold. */
    private const STATES = [
        self::NOT_SUBMITTED,
        self::PENDING,
        self::UNDER_REVIEW,
        self::APPROVED,
        self::REJECTED,
        self::EXPIRED,
    ];

    private function __construct(
        public readonly string $state,
        public readonly string $provider,
        public readonly ?string $reference,
        public readonly ?string $decidedAt,
        public readonly ?string $expiresAt,
        public readonly ?string $reasonCode,
    ) {}

    public static function make(
        string $provider,
        string $state,
        ?string $reference = null,
        ?string $decidedAt = null,
        ?string $expiresAt = null,
        ?string $reasonCode = null,
    ): self {
        // An unknown word is NOT silently mapped to APPROVED and NOT silently
        // mapped to NOT_SUBMITTED either — both would be a decision the platform
        // did not make. It becomes UNDER_REVIEW, which is the only state that
        // blocks money and admits a human is still needed.
        $normalised = in_array($state, self::STATES, true) ? $state : self::UNDER_REVIEW;

        return new self($provider, $normalised, $reference, $decidedAt, $expiresAt, $reasonCode);
    }

    public function isApproved(): bool
    {
        return $this->state === self::APPROVED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->state, [self::APPROVED, self::REJECTED, self::EXPIRED], true);
    }

    /**
     * @return array{state: string, provider: string, reference: string|null, decided_at: string|null, expires_at: string|null, reason_code: string|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'provider' => $this->provider,
            'reference' => $this->reference,
            'decided_at' => $this->decidedAt,
            'expires_at' => $this->expiresAt,
            'reason_code' => $this->reasonCode,
        ];
    }
}
