<?php

declare(strict_types=1);

namespace App\DTOs\Wallet;

use App\Enums\WalletConversionRejectionReason;

/**
 * The verdict of the policy guard on one conversion request.
 *
 * WHY "WHY" IS PART OF THE ANSWER
 * A service that only returns a boolean forces every caller to invent its own
 * explanation, and those inventions drift: the API says "not allowed", the UI
 * says "insufficient balance", and the audit row says nothing at all. This
 * object carries the decision AND the individual checks that produced it, so
 * the API error code, the member-facing message and the operations dashboard
 * can all read the same verdict instead of re-deriving it.
 *
 * A denial ALWAYS names exactly one primary reason — the first failing check in
 * a fixed evaluation order — because a client needs one actionable code, while
 * `checks` retains the full picture for diagnostics. The order is fixed in the
 * guard, not here, so two callers can never disagree about which failure is
 * "the" failure.
 */
final readonly class ConversionPolicyDecisionData
{
    /**
     * @param  array<string, bool|string>  $checks
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public bool $allowed,
        public ?WalletConversionRejectionReason $reason,
        public array $checks = [],
        public array $context = [],
        public ?string $message = null,
    ) {}

    /**
     * @param  array<string, bool|string>  $checks
     * @param  array<string, scalar|null>  $context
     */
    public static function allow(array $checks = [], array $context = []): self
    {
        return new self(
            allowed: true,
            reason: null,
            checks: $checks,
            context: $context,
            message: null,
        );
    }

    /**
     * @param  array<string, bool|string>  $checks
     * @param  array<string, scalar|null>  $context
     */
    public static function deny(
        WalletConversionRejectionReason $reason,
        array $checks = [],
        array $context = [],
        ?string $message = null,
    ): self {
        return new self(
            allowed: false,
            reason: $reason,
            checks: $checks,
            context: $context,
            message: $message,
        );
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isDenied(): bool
    {
        return ! $this->allowed;
    }

    /**
     * The human message, falling back to the enum's own label so a denial can
     * never reach a surface with an empty explanation.
     */
    public function reasonLabel(): string
    {
        return $this->message ?? $this->reason?->label() ?? 'The conversion was refused.';
    }

    /**
     * The HTTP status the API layer must use, or 200 when allowed.
     */
    public function httpStatus(): int
    {
        return $this->reason?->httpStatus() ?? 200;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason?->value,
            'message' => $this->reasonLabel(),
            'checks' => $this->checks,
            'context' => $this->context,
        ];
    }
}
