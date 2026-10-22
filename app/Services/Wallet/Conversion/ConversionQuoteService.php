<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\ConversionFeeBearer;
use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use App\Models\WalletConversionQuote;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

final class ConversionQuoteService
{
    public function __construct(
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionLimitService $limits,
        private readonly ConversionPolicyContract $policy,
    ) {}

    public function issue(ConversionRequestData $request): ConversionQuoteData
    {
        $this->policy->assertAllowed($request);

        $breakdown = $this->fees->breakdownFor(
            $request->direction,
            $request->amount,
            $request->currency->value,
        );

        $ttlSeconds = max(10, (int) config('conversions.quotes.ttl_seconds', 120));
        $issuedAt = Carbon::now()->toDateTimeImmutable();
        $expiresAt = Carbon::instance($issuedAt)->addSeconds($ttlSeconds)->toDateTimeImmutable();

        $prefix = (string) config('conversions.quotes.reference_prefix', 'WQU');
        $reference = strtoupper($prefix).'-'.strtoupper(bin2hex(random_bytes(8)));

        WalletConversionQuote::query()->create([
            'reference' => $reference,
            'user_id' => $request->userId,
            'direction' => $request->direction->value,
            'status' => WalletConversionStatus::Quoted->value,
            'currency' => $request->currency->value,
            'amount_in' => bcadd($request->amount, '0', 2),
            'fee_amount' => $breakdown->feeAmount,
            'amount_out' => $breakdown->netAmount,
            'rate' => $breakdown->rate,
            'fee_bearer' => $breakdown->bearer->value,
            'fee_rule_key' => $breakdown->ruleKey,
            'fee_rule_version' => $breakdown->ruleVersion,
            'expires_at' => Carbon::instance($expiresAt),
        ]);

        return new ConversionQuoteData(
            reference: $reference,
            direction: $request->direction,
            amountIn: bcadd($request->amount, '0', 2),
            feeAmount: $breakdown->feeAmount,
            amountOut: $breakdown->netAmount,
            rate: $breakdown->rate,
            currency: $request->currency,
            feeBearer: $breakdown->bearer,
            feeRuleKey: $breakdown->ruleKey,
            feeRuleVersion: $breakdown->ruleVersion,
            limits: $this->limits->limits($request->currency),
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            description: $breakdown->describe(),
        );
    }

    public function findByReference(string $reference): ?WalletConversionQuote
    {
        return WalletConversionQuote::query()->where('reference', $reference)->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function openForUser(int $userId): array
    {
        $rows = WalletConversionQuote::query()
            ->where('user_id', $userId)
            ->where('status', WalletConversionStatus::Quoted->value)
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $limits = $this->limits->limits();
        $result = [];

        foreach ($rows as $row) {
            $direction = $row->direction instanceof WalletConversionDirection
                ? $row->direction
                : (WalletConversionDirection::tryFrom((string) $row->direction) ?? WalletConversionDirection::CashToWin);
            $currency = Currency::tryFrom((string) $row->currency) ?? Currency::primary();
            $bearer = $row->fee_bearer instanceof ConversionFeeBearer
                ? $row->fee_bearer
                : (ConversionFeeBearer::tryFrom((string) $row->fee_bearer) ?? ConversionFeeBearer::Source);

            $issuedAt = $row->created_at !== null
                ? DateTimeImmutable::createFromInterface($row->created_at)
                : new DateTimeImmutable;
            $expiresAt = $row->expires_at !== null
                ? DateTimeImmutable::createFromInterface($row->expires_at)
                : $issuedAt;

            $dto = new ConversionQuoteData(
                reference: (string) $row->reference,
                direction: $direction,
                amountIn: bcadd((string) $row->amount_in, '0', 2),
                feeAmount: bcadd((string) $row->fee_amount, '0', 2),
                amountOut: bcadd((string) $row->amount_out, '0', 2),
                rate: (string) $row->rate,
                currency: $currency,
                feeBearer: $bearer,
                feeRuleKey: (string) $row->fee_rule_key,
                feeRuleVersion: (string) $row->fee_rule_version,
                limits: $limits,
                issuedAt: $issuedAt,
                expiresAt: $expiresAt,
            );

            $result[] = $dto->toArray();
        }

        return $result;
    }

    public function expireStale(int $batchSize = 500): int
    {
        return (int) WalletConversionQuote::query()
            ->where('status', WalletConversionStatus::Quoted->value)
            ->where('expires_at', '<=', Carbon::now())
            ->limit(max(1, $batchSize))
            ->update([
                'status' => WalletConversionStatus::Expired->value,
            ]);
    }
}
