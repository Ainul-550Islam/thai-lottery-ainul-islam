<?php

declare(strict_types=1);

namespace App\DTOs\Wallet;

use App\Enums\ConversionFeeBearer;
use App\Enums\Currency;
use App\Exceptions\Wallet\ConversionException;
use App\Enums\WalletConversionDirection;

/**
 * The exact, itemised fee a direction charges on one amount.
 *
 * WHY THE RULE PROVENANCE TRAVELS WITH THE NUMBER
 * A charged fee must be explainable months later: which rule key, which version
 * of the public schedule, what the rate was, what it was calculated on, and who
 * bore it. Without that, an operator answering a fee complaint can only say
 * "the system calculated it" — which is not an answer. Carrying the provenance
 * here means the audit row and the ledger posting can both be traced back to
 * the published rule that produced them, and a parity test can assert that the
 * number the public fee page renders is byte-identical to the number this
 * object reports.
 *
 * `isConfigured` is what lets the engine distinguish a genuine zero-rate rule
 * from a rule that is missing or switched off: the first charges nothing on
 * purpose, the second must refuse rather than guess a rate.
 *
 * All monetary fields are exact decimal strings.
 */
final readonly class ConversionFeeBreakdownData
{
    public function __construct(
        public string $ruleKey,
        public string $ruleVersion,
        public WalletConversionDirection $direction,
        public string $rate,
        public string $percentage,
        public int $basisPoints,
        public string $feeAmount,
        public string $netAmount,
        public string $currency,
        public string $calculation,
        public ConversionFeeBearer $bearer,
        public bool $isConfigured,
        public ?string $effectiveFrom = null,
        public ?string $effectiveTo = null,
        public ?string $labelKey = null,
        public ?string $descriptionKey = null,
    ) {}

    public static function currencyOf(self $breakdown): Currency
    {
        // No fallback to the primary currency. A breakdown whose currency is not a
        // supported code is a broken price, and silently reading it as THB would let a
        // mispriced USD conversion be posted as Thai baht.
        $currency = Currency::tryFrom((string) $breakdown->currency);

        if ($currency === null) {
            throw ConversionException::failure(
                'currency_invalid',
                'The fee breakdown carries a currency this system does not support.',
                ['currency' => (string) $breakdown->currency],
            );
        }

        return $currency;
    }

    public function hasFee(): bool
    {
        return bccomp($this->feeAmount, '0', 2) === 1;
    }

    /**
     * True when this breakdown was loaded from a live, visible fee rule.
     */
    public function isEnforceable(): bool
    {
        return $this->isConfigured;
    }

    /**
     * A short human sentence for admin screens and support replies.
     */
    public function describe(): string
    {
        if (! $this->isConfigured) {
            return sprintf('No %s conversion fee rule is configured.', $this->ruleKey);
        }

        return sprintf(
            '%s%% %s conversion fee on %s %s, leaving %s %s (rule %s v%s).',
            $this->percentage,
            $this->direction->label(),
            $this->feeAmount,
            $this->currency,
            $this->netAmount,
            $this->currency,
            $this->ruleKey,
            $this->ruleVersion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_key' => $this->ruleKey,
            'rule_version' => $this->ruleVersion,
            'direction' => $this->direction->value,
            'rate' => $this->rate,
            'percentage' => $this->percentage,
            'basis_points' => $this->basisPoints,
            'fee_amount' => $this->feeAmount,
            'net_amount' => $this->netAmount,
            'currency' => $this->currency,
            'calculation' => $this->calculation,
            'fee_bearer' => $this->bearer->value,
            'is_configured' => $this->isConfigured,
            'effective_from' => $this->effectiveFrom,
            'effective_to' => $this->effectiveTo,
            'label_key' => $this->labelKey,
            'description_key' => $this->descriptionKey,
        ];
    }
}
