<?php

declare(strict_types=1);

namespace App\Contracts\Wallet;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\DTOs\Wallet\ConversionResultData;
use App\Enums\WalletConversionDirection;
use App\Exceptions\Wallet\ConversionAccountRestrictedException;
use App\Exceptions\Wallet\ConversionDisabledException;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionIdempotencyConflictException;
use App\Exceptions\Wallet\ConversionInsufficientBalanceException;
use App\Exceptions\Wallet\ConversionKycRequiredException;
use App\Exceptions\Wallet\ConversionLimitExceededException;
use App\Exceptions\Wallet\ConversionQuoteExpiredException;
use App\Models\WalletConversion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The one and only entry point for moving money between the Cash (primary) and
 * Win wallets.
 *
 * WHY THIS INTERFACE EXISTS
 * Four callers need this capability — the member web portal, the member API,
 * the admin panel and the console — and a fifth is guaranteed to appear (a
 * partner integration or a mobile client). Binding them to one interface means
 * every one of them inherits the same policy gate, the same quote arithmetic,
 * the same idempotency behaviour and the same ledger postings. A caller that
 * reimplemented "just the transfer" would bypass all four, so callers are given
 * no alternative: the container resolves this contract and nothing else.
 *
 * CONTRACT GUARANTEES
 *  - quote() never moves money and never writes a ledger row.
 *  - convert() moves money exactly once per idempotency key, or not at all.
 *  - convert() refuses — with a typed exception, before any wallet lock — when
 *    policy denies the request, so a refusal can never leave a partial state.
 *  - convert() always returns post-movement balances captured inside the same
 *    transaction that moved the money.
 *  - reverse() never mutates a completed conversion; it posts the opposite
 *    entries and links a new row.
 *  - Every method is safe to call inside an ambient transaction; none of them
 *    opens an outer transaction of its own.
 */
interface WalletConversionServiceContract
{
    /**
     * Price a conversion without moving anything.
     *
     * The returned quote is persisted with an expiry so the exact numbers a
     * member saw can be produced again, and so an execution against a stale
     * reference is refused with QuoteExpired rather than silently re-priced.
     *
     * @throws ConversionException when the request cannot be priced
     */
    public function quote(ConversionRequestData $request): ConversionQuoteData;

    /**
     * Execute a conversion.
     *
     * When the request carries an idempotency key that was already used for an
     * identical request, the earlier result is replayed with `replayed = true`
     * and no second movement occurs. When the same key was used for a
     * DIFFERENT request, nothing moves and an
     * ConversionIdempotencyConflictException is thrown.
     *
     * @throws ConversionDisabledException when the feature or direction is off
     * @throws ConversionInsufficientBalanceException when the source wallet cannot cover the amount
     * @throws ConversionLimitExceededException when a configured envelope is breached
     * @throws ConversionKycRequiredException when identity verification is missing
     * @throws ConversionAccountRestrictedException when the member is suspended or self-excluded
     * @throws ConversionQuoteExpiredException when the supplied quote is stale
     * @throws ConversionIdempotencyConflictException when the key was reused for a different request
     * @throws ConversionException for any other refusal
     */
    public function convert(ConversionRequestData $request): ConversionResultData;

    /**
     * Reverse a completed conversion by posting the opposite entries.
     *
     * Reversal is an operator capability. It is refused outside the configured
     * window, without a reason, or when the original movement is not in a
     * reversible state. The original conversion row is never modified.
     *
     * @throws ConversionException when the conversion cannot be reversed
     */
    public function reverse(
        int $conversionId,
        ConversionAuditContextData $context,
        string $reason,
        ?string $idempotencyKey = null,
    ): ConversionResultData;

    /**
     * Read one conversion by its public reference.
     *
     * Implementations must scope to the owner unless the caller is an operator
     * channel; a member must never be able to read another member's movement by
     * guessing a reference.
     */
    public function findForUser(string $reference, int $userId): ?WalletConversion;

    /**
     * A page of conversion history, always scoped to one owner.
     */
    public function history(ConversionHistoryFilterData $filter): LengthAwarePaginator;

    /**
     * Whether conversions are available at all, or for one specific direction.
     */
    public function isEnabled(?WalletConversionDirection $direction = null): bool;
}
