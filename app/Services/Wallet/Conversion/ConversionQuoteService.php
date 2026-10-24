<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionQuoteExpiredException;
use App\Exceptions\Wallet\ConversionQuoteNotFoundException;
use App\Models\User;
use App\Models\WalletConversionQuote;
use App\Repositories\Wallet\WalletConversionQuoteRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ConversionQuoteService
{
    private readonly Repository $config;

    public function __construct(
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionLimitService $limits,
        private readonly ConversionPolicyContract $policy,
        private readonly WalletConversionQuoteRepository $quotes,
        ?Repository $config = null,
    ) {
        $this->config = $config ?? app('config');
    }

    public function quote(ConversionRequestData $request): ConversionQuoteData
    {
        return DB::transaction(function () use ($request): ConversionQuoteData {
            User::query()->whereKey($request->userId)->lockForUpdate()->first();

            $this->policy->assertAllowed($request);

            $issuedAt = Carbon::now()->copy()->startOfSecond();
            $ttlSeconds = max(1, min(3600, (int) $this->config->get('conversions.quotes.ttl_seconds', 120)));
            $expiresAt = $issuedAt->copy()->addSeconds($ttlSeconds);

            $maxOpen = (int) $this->config->get('conversions.quotes.max_open_per_user', 5);
            $this->quotes->assertOpenSlotAvailableForExecution($request->userId, $maxOpen, $issuedAt);

            $amountIn = bcadd($request->amount, '0', 2);
            $breakdown = $this->fees->breakdownFor(
                $request->direction,
                $amountIn,
                $request->currency->value,
            );
            $limits = $this->limits->limits($request->currency);

            $prefix = (string) $this->config->get('conversions.quotes.reference_prefix', 'WQU');
            $reference = strtoupper($prefix).'-'.strtoupper(bin2hex(random_bytes(8)));

            $this->quotes->create([
                'reference' => $reference,
                'user_id' => $request->userId,
                'direction' => $request->direction->value,
                'currency' => $request->currency->value,
                'amount_in' => $amountIn,
                'fee_amount' => $breakdown->feeAmount,
                'amount_out' => $breakdown->netAmount,
                'rate' => $breakdown->rate,
                'fee_bearer' => $breakdown->bearer->value,
                'fee_rule_key' => $breakdown->ruleKey,
                'fee_rule_version' => $breakdown->ruleVersion,
                'limits' => $limits->toArray(),
                'status' => 'open',
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'description' => $breakdown->describe(),
            ]);

            return new ConversionQuoteData(
                reference: $reference,
                direction: $request->direction,
                amountIn: $amountIn,
                feeAmount: $breakdown->feeAmount,
                amountOut: $breakdown->netAmount,
                rate: $breakdown->rate,
                currency: $request->currency,
                feeBearer: $breakdown->bearer,
                feeRuleKey: $breakdown->ruleKey,
                feeRuleVersion: $breakdown->ruleVersion,
                limits: $limits,
                issuedAt: $issuedAt->toDateTimeImmutable(),
                expiresAt: $expiresAt->toDateTimeImmutable(),
                description: $breakdown->describe(),
            );
        });
    }

    public function issue(ConversionRequestData $request): ConversionQuoteData
    {
        return $this->quote($request);
    }

    public function assertUsable(ConversionRequestData $request): ?WalletConversionQuote
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw ConversionException::failure(
                'transaction_required',
                'Quote validation requires an active SQL transaction.',
            );
        }

        $requiresQuote = (bool) $this->config->get('conversions.quotes.require_for_execution', true)
            && (bool) $this->config->get('conversions.directions.'.$request->direction->value.'.requires_quote', true);

        $reference = $request->quoteReference !== null ? trim($request->quoteReference) : '';
        if ($reference === '') {
            if ($requiresQuote) {
                throw ConversionException::failure('quote_not_found', 'Quote could not be found.');
            }

            return null;
        }

        $quote = $this->quotes->findByReferenceForUpdate($reference);
        if (! $quote instanceof WalletConversionQuote || (int) $quote->user_id !== $request->userId) {
            throw ConversionException::failure('quote_not_found', 'Quote could not be found.');
        }

        if (! $quote->isOpen()) {
            throw ConversionQuoteExpiredException::forReference(
                (string) $quote->reference,
                $quote->expires_at ?? Carbon::now()->subSecond(),
            );
        }

        $amountIn = bcadd($request->amount, '0', 2);
        if ($quote->direction !== $request->direction
            || (string) $quote->currency !== $request->currency->value
            || bccomp((string) $quote->amount_in, $amountIn, 2) !== 0) {
            throw ConversionQuoteExpiredException::forReference(
                (string) $quote->reference,
                $quote->expires_at ?? Carbon::now(),
            );
        }

        $breakdown = $this->fees->breakdownFor(
            $request->direction,
            $amountIn,
            $request->currency->value,
        );

        if (! $breakdown->isConfigured) {
            throw ConversionException::failure(
                'fee_rule_invalid',
                'No valid, effective conversion fee is configured.',
            );
        }

        if ((string) $quote->fee_rule_version !== $breakdown->ruleVersion) {
            throw ConversionQuoteExpiredException::forSupersededRule(
                (string) $quote->reference,
                $quote->expires_at ?? Carbon::now(),
                (string) $quote->fee_rule_version,
                $breakdown->ruleVersion,
            );
        }

        if (bccomp((string) $quote->rate, $breakdown->rate, 4) !== 0
            || bccomp((string) $quote->fee_amount, $breakdown->feeAmount, 2) !== 0
            || bccomp((string) $quote->amount_out, $breakdown->netAmount, 2) !== 0
            || $quote->fee_bearer !== $breakdown->bearer) {
            throw ConversionQuoteExpiredException::forReference(
                (string) $quote->reference,
                $quote->expires_at ?? Carbon::now(),
            );
        }

        return $quote;
    }

    public function consume(WalletConversionQuote $quote, int $conversionId): WalletConversionQuote
    {
        return $this->quotes->consume($quote, $conversionId);
    }

    public function findByReference(string $reference): ?WalletConversionQuote
    {
        return $this->quotes->findByReference($reference);
    }

    /**
     * @return Collection<int, WalletConversionQuote>
     */
    public function openForUser(int $userId): Collection
    {
        return $this->quotes->openForUser($userId);
    }

    public function expireStale(?Carbon $now = null): int
    {
        return $this->quotes->expireStale($now);
    }
}
