<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionQuoteStatus;
use App\Exceptions\Wallet\ConversionException;
use App\Models\WalletConversionQuote;
use App\ValueObjects\ConversionLimits;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConversionQuoteService
{
    public function __construct(
        private readonly ConversionPolicyContract $policy,
        private readonly ConversionFeeCalculator $fees,
        private readonly Config $config,
    ) {}

    public function quote(ConversionRequestData $request): ConversionQuoteData
    {
        $this->policy->assertAllowed($request);
        $amount = bcadd($request->amount, '0', 2);
        $fee = $this->fees->breakdownFor($request->direction, $amount, $request->currency->value);
        if (! $fee->isEnforceable()) {
            throw ConversionException::failure('feature_disabled', 'The published conversion fee rule is unavailable, so the conversion lane is disabled.');
        }
        if (bccomp($fee->netAmount, '0', 2) <= 0) {
            throw ConversionException::failure('conversion_amount_too_small_after_fee', 'The amount must be greater than the fee.');
        }
        $now = CarbonImmutable::now();
        $expires = $now->addSeconds(max(1, (int) $this->config->get('conversions.quotes.ttl_seconds', 120)));
        $reference = 'WQ-'.strtoupper(Str::random(24));
        $limits = ConversionLimits::fromConfig((array) $this->config->get('conversions.limits', []), $request->currency);

        return DB::transaction(function () use ($request, $amount, $fee, $now, $expires, $reference, $limits): ConversionQuoteData {
            WalletConversionQuote::query()->create([
                'reference' => $reference,
                'user_id' => $request->userId,
                'direction' => $request->direction,
                'status' => WalletConversionQuoteStatus::Quoted,
                'currency' => $request->currency,
                'amount_in' => $amount,
                'fee_amount' => $fee->feeAmount,
                'amount_out' => $fee->netAmount,
                'fee_rule_key' => $fee->ruleKey,
                'fee_rule_version' => $fee->ruleVersion,
                'fee_rate' => $fee->rate,
                'fee_bearer' => $fee->bearer,
                'channel' => $request->channel->value,
                'request_fingerprint' => hash('sha256', implode('|', [$request->userId, $request->direction->value, $amount, $request->currency->value])),
                'limits' => $limits->toArray(),
                'issued_at' => $now,
                'expires_at' => $expires,
            ]);
            return new ConversionQuoteData(
                reference: $reference,
                direction: $request->direction,
                amountIn: $amount,
                feeAmount: $fee->feeAmount,
                amountOut: $fee->netAmount,
                rate: $fee->rate,
                currency: $request->currency,
                feeBearer: $fee->bearer,
                feeRuleKey: $fee->ruleKey,
                feeRuleVersion: $fee->ruleVersion,
                limits: $limits,
                issuedAt: $now->toDateTimeImmutable(),
                expiresAt: $expires->toDateTimeImmutable(),
                description: $fee->describe(),
            );
        });
    }

    public function findValidForExecution(ConversionRequestData $request): WalletConversionQuote
    {
        if (! $request->hasQuote()) {
            throw ConversionException::failure('conversion_quote_required', 'A valid conversion quote is required.');
        }
        $quote = WalletConversionQuote::query()->where('reference', $request->quoteReference)->where('user_id', $request->userId)->lockForUpdate()->first();
        if (! $quote || $quote->status !== WalletConversionQuoteStatus::Quoted || $quote->expires_at->isPast()) {
            throw ConversionException::failure('conversion_quote_expired', 'The conversion quote is missing, consumed, or expired.');
        }
        if ($quote->direction !== $request->direction || bccomp((string) $quote->amount_in, $request->amount, 2) !== 0 || $quote->currency !== $request->currency) {
            throw ConversionException::failure('conversion_quote_mismatch', 'The quote does not match the requested conversion.');
        }
        return $quote;
    }

    public function consume(WalletConversionQuote $quote, int $conversionId): void
    {
        $quote->status = WalletConversionQuoteStatus::Consumed;
        $quote->consumed_at = now();
        $quote->conversion_id = $conversionId;
        $quote->save();
    }
}
