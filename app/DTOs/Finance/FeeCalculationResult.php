<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

/**
 * Immutable, normalized result of one server-side fee calculation.
 *
 * Produced exclusively by FeesPageService::calculateResult(). Every monetary
 * member is a decimal STRING produced by bcmath — the DTO has no float
 * anywhere, and no constructor path exists that would accept one.
 *
 * STATE CONTRACT
 * - state CONFIGURED: the fee rule is fully specified; feeAmount is the
 *   exact decimal fee for the given base amount.
 * - state NOT_CONFIGURED: the public fee row exists and is visible, but its
 *   percentage/amount has deliberately not been specified by the operator.
 *   feeAmount is null — an unspecified percentage is a state, never a
 *   guessed number.
 *
 * Nothing in this DTO is client-derived: the caller supplies only the
 * category, an optional provider key and the base amount. The rule, the
 * rate, the currency and the rule version are resolved server-side from
 * configuration, and the base amount itself is validated before it reaches
 * arithmetic.
 */
final readonly class FeeCalculationResult
{
    public const STATE_CONFIGURED = 'CONFIGURED';

    public const STATE_NOT_CONFIGURED = 'NOT_CONFIGURED';

    /**
     * @param  string  $category  stable public fee-category key (whitelisted)
     * @param  string|null  $provider  stable provider key when the row is provider-specific
     * @param  string  $baseAmount  exact decimal-string base the fee was computed against
     * @param  string|null  $feeAmount  exact decimal-string fee; null when NOT_CONFIGURED
     * @param  string  $currency  ISO-4217 code of the fee row
     * @param  string  $calculation  'fixed' | 'percentage' | 'none'
     * @param  string  $ruleVersion  version of the fee rule that produced this result
     * @param  string  $state  self::STATE_* constant
     * @param  string  $feeDisplay  human-readable display form (e.g. "3.00 THB", "9.00%")
     * @param  string|null  $effectiveFrom  inclusive first effective day of the rule
     * @param  string|null  $effectiveTo  exclusive last effective day of the rule
     */
    public function __construct(
        public string $category,
        public ?string $provider,
        public string $baseAmount,
        public ?string $feeAmount,
        public string $currency,
        public string $calculation,
        public string $ruleVersion,
        public string $state,
        public string $feeDisplay,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
    ) {}

    public function isConfigured(): bool
    {
        return $this->state === self::STATE_CONFIGURED;
    }

    public function isNotConfigured(): bool
    {
        return $this->state === self::STATE_NOT_CONFIGURED;
    }

    /**
     * Safe public projection: every field is server-derived, decimal-string
     * money, and whitelisted — nothing internal rides along.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'provider' => $this->provider,
            'base_amount' => $this->baseAmount,
            'fee_amount' => $this->feeAmount,
            'currency' => $this->currency,
            'calculation' => $this->calculation,
            'rule_version' => $this->ruleVersion,
            'state' => $this->state,
            'fee_display' => $this->feeDisplay,
            'effective_from' => $this->effectiveFrom,
            'effective_to' => $this->effectiveTo,
        ];
    }
}
