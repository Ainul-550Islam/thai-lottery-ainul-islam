<?php

declare(strict_types=1);

namespace App\Contracts\Wallet;

use App\DTOs\Wallet\ConversionFeeBreakdownData;
use App\Enums\WalletConversionDirection;

/**
 * Resolves the published conversion fee for a direction and an amount.
 *
 * WHY THIS IS AN INTERFACE AND NOT A PRIVATE HELPER
 * The public fee page, the quote endpoint, the execution service, the audit
 * writer and the parity test all need "what does this conversion cost?" — and
 * they must all get the SAME answer. The only way to make that structural
 * rather than aspirational is to make the fee a resolved dependency with one
 * implementation, so a second, subtly different percentage calculation cannot
 * be introduced later without deleting this contract.
 *
 * The single source of truth is config/fees.php (`cash_to_win`, `win_to_cash`),
 * which is the same table the member-facing fee page renders. An
 * implementation MUST NOT carry its own rate: if the rule is missing or
 * switched off, it reports `isConfigured = false` and the engine refuses,
 * rather than inventing a default.
 */
interface ConversionFeeProviderContract
{
    /**
     * The exact fee a direction charges on one amount.
     *
     * @param  string  $amount  an exact non-negative decimal string, e.g. "1000.00"
     * @param  string  $currency  the ISO code the wallets are denominated in
     */
    public function breakdownFor(
        WalletConversionDirection $direction,
        string $amount,
        string $currency,
    ): ConversionFeeBreakdownData;

    /**
     * Whether a live, enabled fee rule exists for this direction.
     *
     * False means "refuse the conversion", not "charge zero".
     */
    public function isConfigured(WalletConversionDirection $direction): bool;

    /**
     * The rate the public fee schedule publishes for this direction, as a
     * percentage string, or null when no rule is configured. Used by the parity
     * test to prove the page and the engine agree.
     */
    public function publishedPercentage(WalletConversionDirection $direction): ?string;
}
