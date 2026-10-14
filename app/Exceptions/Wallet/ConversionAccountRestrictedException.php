<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletConversionRejectionReason;
use InvalidArgumentException;
use Throwable;

/**
 * The member's account or wallet is not in a state that may convert.
 *
 * WHY SUSPENSION, SELF-EXCLUSION AND A LOCKED WALLET SHARE ONE TYPE
 * All three mean the same thing to the engine: this member must not move money
 * right now, and no amount of retrying will change that. What differs is the
 * remedy — a suspended account needs support, a self-excluded member needs to
 * wait out the period (that is the point of the protection), and a locked
 * wallet needs the lock reason investigated. The reason travels with the
 * exception so the UI can say the right sentence, while the engine keeps one
 * refusal path that always fires BEFORE any wallet lock is taken.
 *
 * A self-exclusion refusal is deliberately indistinguishable from a suspension
 * in the HTTP response shape: telling a member precisely which control stopped
 * them, in a latency-observable way, is the kind of detail that helps someone
 * probe the system. The distinction lives in the log and in support tooling,
 * not in a public error message.
 */
final class ConversionAccountRestrictedException extends ConversionException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        private readonly WalletConversionRejectionReason $reason,
        private readonly int $userId,
        private readonly ?string $accountStatus,
        private readonly ?int $walletId = null,
        private readonly ?string $lockReason = null,
        array $context = [],
        ?Throwable $previous = null,
    ) {
        if (! self::isRestrictionReason($reason)) {
            throw new InvalidArgumentException(
                sprintf('[%s] is not a conversion account-restriction reason.', $reason->value),
            );
        }

        parent::__construct(
            self::describe($reason),
            $reason->value,
            array_merge([
                'reason' => $reason->value,
                'user_id' => $userId,
                'account_status' => $accountStatus,
                'wallet_id' => $walletId,
                'lock_reason' => $lockReason,
            ], $context),
            $previous,
        );
    }

    /**
     * The account is suspended, banned or otherwise not in good standing.
     */
    public static function forAccountStatus(
        int $userId,
        string $accountStatus,
        ?Throwable $previous = null,
    ): self {
        return new self(
            WalletConversionRejectionReason::AccountSuspended,
            $userId,
            $accountStatus,
            previous: $previous,
        );
    }

    /**
     * A self-exclusion period is active. This is a protection, not a penalty.
     */
    public static function forSelfExclusion(
        int $userId,
        ?string $until = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            WalletConversionRejectionReason::SelfExclusionActive,
            $userId,
            'self_excluded',
            context: ['self_exclusion_until' => $until],
            previous: $previous,
        );
    }

    /**
     * One of the two wallets involved carries a lock (a hold, a compliance
     * freeze, an AML review).
     */
    public static function forWalletLock(
        int $userId,
        int $walletId,
        ?string $lockReason = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            WalletConversionRejectionReason::WalletLocked,
            $userId,
            null,
            $walletId,
            $lockReason,
            previous: $previous,
        );
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function accountStatus(): ?string
    {
        return $this->accountStatus;
    }

    public function walletId(): ?int
    {
        return $this->walletId;
    }

    public function lockReason(): ?string
    {
        return $this->lockReason;
    }

    /**
     * True when the restriction is a member-chosen protection. The UI must not
     * offer a "contact support to lift it" shortcut for these: circumventing a
     * self-exclusion on request is exactly what the control exists to prevent.
     */
    public function isSelfImposed(): bool
    {
        return $this->reason === WalletConversionRejectionReason::SelfExclusionActive;
    }

    public function rejectionReason(): ?WalletConversionRejectionReason
    {
        return $this->reason;
    }

    private static function isRestrictionReason(WalletConversionRejectionReason $reason): bool
    {
        return in_array($reason, [
            WalletConversionRejectionReason::AccountSuspended,
            WalletConversionRejectionReason::SelfExclusionActive,
            WalletConversionRejectionReason::WalletLocked,
        ], true);
    }

    private static function describe(WalletConversionRejectionReason $reason): string
    {
        return match ($reason) {
            WalletConversionRejectionReason::AccountSuspended => 'This account is not permitted to convert wallet balances at the moment.',
            WalletConversionRejectionReason::SelfExclusionActive => 'A self-exclusion period is active, so wallet conversions are unavailable.',
            WalletConversionRejectionReason::WalletLocked => 'A wallet involved in this conversion is locked, so the conversion cannot proceed.',
            default => 'This account is not permitted to convert wallet balances.',
        };
    }
}
