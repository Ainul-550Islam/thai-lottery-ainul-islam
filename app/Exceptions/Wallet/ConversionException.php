<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletConversionRejectionReason;
use App\Exceptions\FinancialException;

/**
 * Base failure type for the Cash <-> Win conversion domain.
 *
 * Every refusal the conversion engine makes derives from this class, which
 * itself derives from App\Exceptions\FinancialException — the same root the
 * wallet, ledger, idempotency and reversal services throw. A caller that only
 * cares "a financial operation failed" can catch FinancialException; a caller
 * that needs to branch on the specific conversion failure catches this class or
 * one of its subclasses; a caller that needs the stable machine code reads
 * errorCode().
 *
 * THE ERROR CODE IS THE CONTRACT
 * The human message may be reworded, translated or attached to a support case
 * at any time. The error code may not change, because API clients, alerting
 * rules and the refusal dashboard key on it. Wherever a refusal corresponds to
 * a case of WalletConversionRejectionReason, the code is that case's backing
 * value — so `rejectionReason()` resolves it back to the enum without a second
 * lookup table that could drift.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * It runs no queries, touches no models and builds no HTTP response. It is safe
 * to throw from inside a transaction that is about to roll back, and it must
 * stay that way: an exception that read the database could observe a different
 * state from the one that caused the failure.
 */
class ConversionException extends FinancialException
{
    public const ERROR_CODE = 'wallet_conversion_failed';

    /**
     * Build a failure with an explicit stable code.
     *
     * This is the escape hatch used for refusals that have no dedicated
     * subclass (for example `currency_mismatch` raised by the wallet resolver).
     * It always returns `static`, so a subclass calling it produces the
     * subclass rather than the base type.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function failure(
        string $errorCode,
        string $message,
        array $context = [],
        ?\Throwable $previous = null,
    ): static {
        return new static($message, $errorCode, $context, $previous);
    }

    /**
     * Refuse unless the condition holds. Keeps the call site to one line where
     * a refusal is a guard rather than the main flow.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function refuseUnless(
        bool $condition,
        string $errorCode,
        string $message,
        array $context = [],
    ): void {
        if (! $condition) {
            throw static::failure($errorCode, $message, $context);
        }
    }

    /**
     * The refusal reason this failure corresponds to, when it maps onto one.
     *
     * Resolution is by error code because the codes ARE the enum backing
     * values; a failure whose code has no enum counterpart (a programming
     * error such as `conversion_same_wallet`, or a malformed request) returns
     * null rather than being forced into a category it does not belong to.
     */
    public function rejectionReason(): ?WalletConversionRejectionReason
    {
        $code = $this->errorCode();

        return $code === null ? null : WalletConversionRejectionReason::tryFrom($code);
    }

    /**
     * The HTTP status the API layer must use for this failure. Unknown codes
     * fall back to 422 — an unclassified refusal is still a refusal, never a
     * 500, because a 500 would page an operator for a member's mistake.
     */
    public function httpStatus(): int
    {
        return $this->rejectionReason()?->httpStatus() ?? 422;
    }

    /**
     * True when the member can resolve this themselves (top up, verify, wait).
     */
    public function isUserCorrectable(): bool
    {
        return $this->rejectionReason()?->isUserCorrectable() ?? false;
    }

    /**
     * The translation key for the member-facing explanation, when one exists.
     */
    public function messageKey(): ?string
    {
        return $this->rejectionReason()?->messageKey();
    }

    /**
     * The API error envelope. Deliberately flat and free of internal
     * identifiers beyond the safe diagnostic context the throw site supplied.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'status' => 'CONVERSION_REFUSED',
            'code' => $this->errorCode() ?? self::ERROR_CODE,
            'message' => $this->getMessage(),
            'user_correctable' => $this->isUserCorrectable(),
            'context' => $this->context(),
        ];
    }
}
