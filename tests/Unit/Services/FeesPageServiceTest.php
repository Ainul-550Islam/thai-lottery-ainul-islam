<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTOs\Finance\FeeCalculationResult;
use App\Enums\Currency;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalService;
use App\Services\PublicPages\FeesPageService;
use Tests\TestCase;

/**
 * FeesPageServiceTest — exhaustive unit coverage of the canonical public
 * fee catalogue (fees parity batch).
 *
 * Everything money-shaped in this suite is a decimal string compared
 * exactly. The suite also proves the service itself contains no float
 * arithmetic and no client-trusted input.
 */
final class FeesPageServiceTest extends TestCase
{
    private FeesPageService $fees;

    /** Every row the public schedule is required to expose. */
    private const REQUIRED_PUBLIC_ROWS = [
        'account_renewal',
        'account_verification',
        'referral',
        'affiliation',
        'cash_balance_transfer',
        'win_balance_transfer',
        'cash_to_win',
        'win_to_cash',
        'personal_to_agent',
        'withdrawal_bank',
        'withdrawal_skrill',
        'withdrawal_neteller',
        'withdrawal_paypal',
        'withdrawal_perfect_money',
        'cash_in_bank',
        'cash_in_skrill',
        'cash_in_neteller',
        'cash_in_paypal',
        'cash_in_perfect_money',
        'agent_to_agent',
        'agent_to_win_commission',
        'agent_to_cash_commission',
        'personal_to_agent_commission',
        'maintenance',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fees = app(FeesPageService::class);
    }

    // ------------------------------------------------------------- catalogue

    public function test_every_required_public_row_exists_and_renders(): void
    {
        $rendered = array_column($this->fees->publicFees('en'), 'key');

        foreach (self::REQUIRED_PUBLIC_ROWS as $key) {
            $this->assertContains($key, $rendered, "Required public fee row missing: {$key}");
            $this->assertIsArray((array) config('fees.categories.'.$key), "Config row missing: {$key}");
        }

        // The generic live-rule rows remain alongside the provider rows.
        $this->assertContains('withdrawal', $rendered);
        $this->assertContains('cash_in', $rendered);
    }

    public function test_every_row_declares_the_full_row_contract(): void
    {
        foreach ((array) config('fees.categories', []) as $key => $row) {
            $key = (string) $key;
            $this->assertIsArray($row);

            foreach ([
                'enabled', 'public_visible', 'calculation', 'currency',
                'effective_from', 'rule_version', 'internal', 'group',
                'description_key', 'description',
            ] as $field) {
                $this->assertArrayHasKey($field, $row, "Row {$key} missing field {$field}");
            }

            $this->assertContains($row['calculation'], ['fixed', 'percentage', 'none'], "Row {$key} has an unknown calculation type");
            $this->assertNotSame('', (string) $row['rule_version'], "Row {$key} has an empty rule version");
            $this->assertArrayHasKey((string) $row['group'], $this->fees->groups(), "Row {$key} references an unknown group");
        }
    }

    public function test_groups_cover_every_public_row_with_no_orphans_or_empty_sections(): void
    {
        $groups = $this->fees->publicFeeGroups('en');
        $this->assertNotSame([], $groups);

        $seen = [];

        foreach ($groups as $group) {
            $this->assertNotEmpty($group['rows'], 'An empty group must not render');
            $this->assertNotSame('', (string) $group['label']);

            foreach ($group['rows'] as $row) {
                $this->assertSame($group['key'], $row['group']);
                $seen[] = $row['key'];
            }
        }

        $flat = array_column($this->fees->publicFees('en'), 'key');
        $this->assertSame($flat, $seen, 'Grouped projection and flat projection must carry the same rows in the same order');
    }

    public function test_provider_rows_use_exactly_the_stable_provider_keys(): void
    {
        $stableKeys = ['bank', 'skrill', 'neteller', 'paypal', 'perfect_money'];
        $this->assertSame($stableKeys, $this->fees->providers());

        $expected = [
            'withdrawal_bank' => 'bank',
            'withdrawal_skrill' => 'skrill',
            'withdrawal_neteller' => 'neteller',
            'withdrawal_paypal' => 'paypal',
            'withdrawal_perfect_money' => 'perfect_money',
            'cash_in_bank' => 'bank',
            'cash_in_skrill' => 'skrill',
            'cash_in_neteller' => 'neteller',
            'cash_in_paypal' => 'paypal',
            'cash_in_perfect_money' => 'perfect_money',
        ];

        $byKey = [];

        foreach ($this->fees->publicFees('en') as $row) {
            $byKey[$row['key']] = $row['provider'];
        }

        foreach ($expected as $rowKey => $provider) {
            $this->assertSame($provider, $byKey[$rowKey] ?? null, "Row {$rowKey} must map to provider {$provider}");
            $this->assertTrue($this->fees->isKnownProvider($provider));
        }

        $this->assertFalse($this->fees->isKnownProvider('western_union'));
        $this->assertFalse($this->fees->isKnownProvider(''));
    }

    public function test_withdrawal_and_cash_in_are_not_collapsed_into_one_generic_row(): void
    {
        $keys = array_column($this->fees->publicFees('en'), 'key');

        foreach (['withdrawal_bank', 'withdrawal_skrill', 'withdrawal_neteller', 'withdrawal_paypal', 'withdrawal_perfect_money'] as $key) {
            $this->assertContains($key, $keys);
        }

        foreach (['cash_in_bank', 'cash_in_skrill', 'cash_in_neteller', 'cash_in_paypal', 'cash_in_perfect_money'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_paypal_rows_are_visibly_not_configured(): void
    {
        $byKey = collect($this->fees->publicFees('en'))->keyBy('key');

        $this->assertSame(FeesPageService::NOT_CONFIGURED, $byKey['withdrawal_paypal']['amount_display']);
        $this->assertSame(FeeCalculationResult::STATE_NOT_CONFIGURED, $byKey['withdrawal_paypal']['state']);
        $this->assertSame(FeesPageService::NOT_CONFIGURED, $byKey['cash_in_paypal']['amount_display']);
        $this->assertSame(FeeCalculationResult::STATE_NOT_CONFIGURED, $byKey['cash_in_paypal']['state']);
    }

    public function test_internal_and_never_public_rows_are_suppressed(): void
    {
        $keys = array_column($this->fees->publicFees('en'), 'key');

        $this->assertNotContains('internal_provider_margin', $keys);
        $this->assertFalse($this->fees->isPublicCategory('internal_provider_margin'));
        $this->assertNotContains('internal_provider_margin', $this->fees->previewCategories());

        // The three independent guards each suppress on their own.
        config(['fees.categories.internal_provider_margin.never_public' => []]);
        config(['fees.categories.internal_provider_margin.internal' => true]);
        config(['fees.categories.internal_provider_margin.public_visible' => false]);
        $this->assertFalse($this->fees->isPublicCategory('internal_provider_margin'));

        config(['fees.categories.internal_provider_margin.internal' => false]);
        $this->assertFalse($this->fees->isPublicCategory('internal_provider_margin'), 'public_visible=false must suppress alone');

        config(['fees.categories.internal_provider_margin.public_visible' => true]);
        config(['fees.categories.internal_provider_margin.enabled' => false]);
        $this->assertFalse($this->fees->isPublicCategory('internal_provider_margin'), 'enabled=false must suppress alone');
    }

    public function test_disabled_rows_are_suppressed(): void
    {
        config(['fees.categories.agent_to_agent.enabled' => false]);

        $keys = array_column($this->fees->publicFees('en'), 'key');
        $this->assertNotContains('agent_to_agent', $keys);
        $this->assertFalse($this->fees->isPublicCategory('agent_to_agent'));
    }

    // -------------------------------------------------------- effective dates

    public function test_effective_from_in_the_future_hides_the_row(): void
    {
        config(['fees.categories.referral.effective_from' => now()->addDay()->toDateString()]);

        $keys = array_column($this->fees->publicFees('en'), 'key');
        $this->assertNotContains('referral', $keys);
    }

    public function test_effective_to_is_exclusive_and_hides_the_row_after_it(): void
    {
        // Ending "yesterday" (start-of-day exclusive semantics) → not effective.
        config(['fees.categories.referral.effective_to' => now()->toDateString()]);

        $keys = array_column($this->fees->publicFees('en'), 'key');
        $this->assertNotContains('referral', $keys, 'effective_to is exclusive: today must already be past the end');

        // Ending tomorrow → still effective today.
        config(['fees.categories.referral.effective_to' => now()->addDay()->toDateString()]);
        $keys = array_column($this->fees->publicFees('en'), 'key');
        $this->assertContains('referral', $keys);
    }

    public function test_is_effective_handles_explicit_instants(): void
    {
        $row = ['effective_from' => '2026-01-01', 'effective_to' => '2026-02-01'];

        $this->assertTrue($this->fees->isEffective($row, new \DateTimeImmutable('2026-01-01')));
        $this->assertTrue($this->fees->isEffective($row, new \DateTimeImmutable('2026-01-31 23:59:59')));
        $this->assertFalse($this->fees->isEffective($row, new \DateTimeImmutable('2026-02-01')));
        $this->assertFalse($this->fees->isEffective($row, new \DateTimeImmutable('2025-12-31')));
        $this->assertTrue($this->fees->isEffective(['effective_from' => null, 'effective_to' => null]));
    }

    // -------------------------------------------------------- live-rule mirror

    public function test_execution_rows_mirror_the_live_engine_rule_at_runtime(): void
    {
        config(['finance.withdrawal.fee_percentage' => '8.00']);

        $byKey = collect($this->fees->publicFees('en'))->keyBy('key');
        $this->assertSame('8.00%', $byKey['withdrawal']['amount_display']);
        $this->assertSame('8.00%', $byKey['withdrawal_bank']['amount_display']);
        $this->assertSame('8.00', $this->fees->calculate('withdrawal', '100.00'));
        $this->assertSame('8.00', $this->fees->calculate('withdrawal_bank', '100.00'));

        // The engine's own resolver agrees exactly (DepositService pattern:
        // whole percent → fee), proving display == execution.
        $engine = app(WithdrawalService::class)
            ->resolveFee(Money::of('100.00', Currency::THB));
        $this->assertSame('8.00', $engine->toString());

        config(['finance.withdrawal.fee_percentage' => '2.50']);
        $this->assertSame('2.50', $this->fees->calculate('withdrawal', '100.00'));
        $this->assertSame('2.50%', collect($this->fees->publicFees('en'))->keyBy('key')['withdrawal']['amount_display']);
    }

    public function test_cash_in_rows_mirror_the_live_deposit_rule_at_runtime(): void
    {
        config(['finance.deposit.fee_percentage' => '1.50']);

        $byKey = collect($this->fees->publicFees('en'))->keyBy('key');
        $this->assertSame('1.50%', $byKey['cash_in']['amount_display']);
        $this->assertSame('1.50%', $byKey['cash_in_bank']['amount_display']);
        $this->assertSame('1.50', $this->fees->calculate('cash_in', '100.00'));

        $engine = app(DepositService::class)
            ->resolveFee(Money::of('100.00', Currency::THB));
        $this->assertSame('1.50', $engine->toString());
    }

    public function test_absent_live_rule_renders_not_configured_and_charges_nothing(): void
    {
        config(['finance.withdrawal.fee_percentage' => null]);

        $byKey = collect($this->fees->publicFees('en'))->keyBy('key');
        $this->assertSame(FeesPageService::NOT_CONFIGURED, $byKey['withdrawal']['amount_display']);

        // Zero-fee engine rule renders as an explicit 0.00%, not as free-by-accident.
        config(['finance.withdrawal.fee_percentage' => '0.00']);
        $this->assertSame('0.00%', collect($this->fees->publicFees('en'))->keyBy('key')['withdrawal']['amount_display']);
        $this->assertSame('0.00', $this->fees->calculate('withdrawal', '100.00'));
    }

    // ------------------------------------------------------ decimal arithmetic

    public function test_percentage_calculation_is_exact_decimal_math(): void
    {
        $cases = [
            // [rate, base, expected fee]
            ['0.0300', '100.00', '3.00'],
            ['0.0900', '100.00', '9.00'],
            ['0.0900', '150.00', '13.50'],
            ['0.0900', '33.33', '3.00'],    // 2.9997 → 3.00 half-up
            ['0.0300', '33.33', '1.00'],    // 0.9999 → 1.00 half-up
            ['0.0100', '0.05', '0.00'],     // 0.0005 → 0.00
            ['0.0150', '0.10', '0.00'],     // 0.0015 → 0.00
            ['0.0150', '0.33', '0.00'],     // 0.00495 → 0.00
            ['0.0150', '0.34', '0.01'],     // 0.0051 → 0.01
            ['0.0000', '1000000.00', '0.00'],
        ];

        foreach ($cases as [$rate, $base, $expected]) {
            config(['fees.categories.cash_balance_transfer.rate' => $rate]);
            $this->assertSame(
                $expected,
                $this->fees->calculate('cash_balance_transfer', $base),
                "{$rate} × {$base} must equal {$expected} exactly"
            );
        }
    }

    public function test_rounding_boundary_is_half_up_at_scale_two(): void
    {
        // 0.005 is the exact half-cent boundary: half-up must round it up.
        config(['fees.categories.cash_balance_transfer.rate' => '0.0500']);
        $this->assertSame('0.01', $this->fees->calculate('cash_balance_transfer', '0.10')); // 0.005 → 0.01

        // One hundredth below the boundary rounds down.
        config(['fees.categories.cash_balance_transfer.rate' => '0.0490']);
        $this->assertSame('0.00', $this->fees->calculate('cash_balance_transfer', '0.10')); // 0.0049 → 0.00
    }

    public function test_zero_and_decimal_base_amounts(): void
    {
        config(['fees.categories.cash_balance_transfer.rate' => '0.0300']);
        $this->assertSame('0.00', $this->fees->calculate('cash_balance_transfer', '0.00'));
        $this->assertSame('0.03', $this->fees->calculate('cash_balance_transfer', '1.00'));
        $this->assertSame('30.00', $this->fees->calculate('cash_balance_transfer', '1000.00'));
        $this->assertSame('0.90', $this->fees->calculate('cash_balance_transfer', '30.00'));
    }

    public function test_min_and_max_bounds_clamp_the_fee(): void
    {
        config(['fees.categories.cash_balance_transfer.rate' => '0.0300']);
        config(['fees.categories.cash_balance_transfer.min' => '2.00']);
        config(['fees.categories.cash_balance_transfer.max' => '5.00']);

        $this->assertSame('2.00', $this->fees->calculate('cash_balance_transfer', '10.00'));   // 0.30 → min
        $this->assertSame('3.00', $this->fees->calculate('cash_balance_transfer', '100.00'));  // between bounds
        $this->assertSame('5.00', $this->fees->calculate('cash_balance_transfer', '1000.00')); // 30.00 → max
    }

    public function test_percentage_fee_never_exceeds_the_base_amount(): void
    {
        config(['fees.categories.cash_balance_transfer.rate' => '2.0000']); // 200%
        $this->assertSame('10.00', $this->fees->calculate('cash_balance_transfer', '10.00'));

        config(['fees.categories.cash_balance_transfer.rate' => '99.0000']); // 9900%
        $this->assertSame('7.77', $this->fees->calculate('cash_balance_transfer', '7.77'));
    }

    public function test_percentage_fee_never_becomes_negative(): void
    {
        // A negative configured rate is a misconfiguration: it must not
        // produce a negative fee (display degrades to NOT_CONFIGURED and
        // the calculation floors at zero).
        config(['fees.categories.cash_balance_transfer.rate' => '-0.0500']);

        $this->assertSame('0.00', $this->fees->calculate('cash_balance_transfer', '100.00'));
        $this->assertSame(
            FeesPageService::NOT_CONFIGURED,
            collect($this->fees->publicFees('en'))->keyBy('key')['cash_balance_transfer']['amount_display']
        );
    }

    public function test_fixed_fee_is_a_flat_amount_independent_of_the_base(): void
    {
        $this->assertSame('3.00', $this->fees->calculate('account_renewal', '10.00'));
        $this->assertSame('3.00', $this->fees->calculate('account_renewal', '10000.00'));
        $this->assertSame('5.00', $this->fees->calculate('maintenance', '100.00'));
        $this->assertSame('1.00', $this->fees->calculate('referral', '0.50'));
    }

    public function test_unknown_or_malformed_inputs_are_rejected_by_the_calculator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fees->calculate('does_not_exist', '100.00');
    }

    public function test_calculator_rejects_a_non_numeric_base(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fees->calculate('withdrawal_skrill', 'abc');
    }

    // --------------------------------------------------------- formatting

    public function test_fixed_fee_formatting_uses_the_platform_currency(): void
    {
        $byKey = collect($this->fees->publicFees('en'))->keyBy('key');
        $currency = (string) config('fees.currency', 'THB');

        $this->assertSame('3.00 '.$currency, $byKey['account_renewal']['amount_display']);
        $this->assertSame('1.00 '.$currency, $byKey['referral']['amount_display']);
        $this->assertSame('5.00 '.$currency, $byKey['maintenance']['amount_display']);
    }

    public function test_percentage_formatting_and_zero_percent_fee(): void
    {
        $byKey = collect($this->fees->publicFees('en'))->keyBy('key');

        $this->assertSame('3.00%', $byKey['cash_balance_transfer']['amount_display']);
        $this->assertSame('2.00%', $byKey['win_balance_transfer']['amount_display']);
        $this->assertSame('8.00%', $byKey['personal_to_agent']['amount_display']);
        $this->assertSame('9.00%', $byKey['withdrawal_skrill']['amount_display']);
        $this->assertSame('0.00%', $byKey['cash_in_skrill']['amount_display'], 'A zero-percent fee renders as an explicit 0.00%, never as free-by-accident');
    }

    public function test_deterministic_output(): void
    {
        $a = $this->fees->publicFees('en');
        $b = $this->fees->publicFees('en');

        $this->assertSame($a, $b);
        $this->assertSame(
            $this->fees->calculateResult('withdrawal', '123.45', 'skrill')->toArray(),
            $this->fees->calculateResult('withdrawal', '123.45', 'skrill')->toArray()
        );
    }

    // ------------------------------------------------------ calculateResult

    public function test_calculate_result_returns_the_full_normalized_projection(): void
    {
        $result = $this->fees->calculateResult('withdrawal', '150.00', 'skrill');

        $this->assertSame('withdrawal_skrill', $result->category);
        $this->assertSame('skrill', $result->provider);
        $this->assertSame('150.00', $result->baseAmount);
        $this->assertSame('13.50', $result->feeAmount);
        $this->assertSame((string) config('fees.currency', 'THB'), $result->currency);
        $this->assertSame('percentage', $result->calculation);
        $this->assertSame((string) config('fees.categories.withdrawal_skrill.rule_version'), $result->ruleVersion);
        $this->assertSame(FeeCalculationResult::STATE_CONFIGURED, $result->state);
        $this->assertSame('9.00%', $result->feeDisplay);
        $this->assertTrue($result->isConfigured());

        $array = $result->toArray();
        $this->assertSame('13.50', $array['fee_amount']);
        $this->assertSame('skrill', $array['provider']);
        $this->assertSame('CONFIGURED', $array['state']);
    }

    public function test_calculate_result_for_a_provider_row_key_directly(): void
    {
        $result = $this->fees->calculateResult('withdrawal_neteller', '200.00');

        $this->assertSame('withdrawal_neteller', $result->category);
        $this->assertSame('neteller', $result->provider);
        $this->assertSame('18.00', $result->feeAmount);
    }

    public function test_calculate_result_for_a_fixed_category(): void
    {
        $result = $this->fees->calculateResult('account_renewal', '500.00');

        $this->assertSame('3.00', $result->feeAmount);
        $this->assertSame('fixed', $result->calculation);
        $this->assertSame(FeeCalculationResult::STATE_CONFIGURED, $result->state);
    }

    public function test_calculate_result_for_a_not_configured_category(): void
    {
        $result = $this->fees->calculateResult('withdrawal_paypal', '100.00');

        $this->assertTrue($result->isNotConfigured());
        $this->assertNull($result->feeAmount);
        $this->assertSame(FeeCalculationResult::STATE_NOT_CONFIGURED, $result->state);
        $this->assertSame(FeesPageService::NOT_CONFIGURED, $result->feeDisplay);
        $this->assertNull($result->toArray()['fee_amount']);
    }

    public function test_calculate_result_rejects_unknown_and_non_public_categories(): void
    {
        foreach (['does_not_exist', 'internal_provider_margin'] as $bad) {
            try {
                $this->fees->calculateResult($bad, '100.00');
                $this->fail("Expected rejection for category {$bad}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not configured', $e->getMessage());
            }
        }

        config(['fees.categories.referral.enabled' => false]);

        try {
            $this->fees->calculateResult('referral', '100.00');
            $this->fail('Expected rejection for a disabled category');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not configured', $e->getMessage());
        }
    }

    public function test_calculate_result_rejects_unknown_providers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fees->calculateResult('withdrawal', '100.00', 'western_union');
    }

    public function test_calculate_result_rejects_a_provider_on_a_non_family_category(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fees->calculateResult('account_renewal', '100.00', 'bank');
    }

    public function test_calculate_result_rejects_malformed_base_amounts(): void
    {
        foreach (['-50.00', '1e3', 'abc', '1.234', '1,000', '+5', ''] as $bad) {
            try {
                $this->fees->calculateResult('withdrawal_skrill', $bad);
                $this->fail("Expected rejection for base amount {$bad}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('decimal string', $e->getMessage());
            }
        }
    }

    public function test_calculate_result_accepts_zero_and_two_decimal_bases(): void
    {
        $zero = $this->fees->calculateResult('withdrawal_skrill', '0.00');
        $this->assertSame('0.00', $zero->feeAmount);

        $decimal = $this->fees->calculateResult('withdrawal_skrill', '12345.67');
        $this->assertSame('1111.11', $decimal->feeAmount); // 12345.67 × 0.09 = 1111.1103 → 1111.11
    }

    public function test_calculate_result_zero_base_is_a_valid_preview(): void
    {
        $result = $this->fees->calculateResult('cash_balance_transfer', '0.00');
        $this->assertSame('0.00', $result->feeAmount);
        $this->assertSame('0.00', $result->baseAmount);
    }

    public function test_calculate_result_min_max_boundaries(): void
    {
        config(['fees.categories.withdrawal_skrill.min' => '20.00']);
        config(['fees.categories.withdrawal_skrill.max' => '50.00']);

        // Between the bounds the percentage applies untouched.
        $this->assertSame('27.00', $this->fees->calculateResult('withdrawal_skrill', '300.00')->feeAmount);
        // Above the max the fee is capped by the row's max bound.
        $this->assertSame('50.00', $this->fees->calculateResult('withdrawal_skrill', '1000.00')->feeAmount);
        // Below the min the fee is raised to the min bound — but the
        // preserved engine invariant still wins overall: a percentage fee
        // can never exceed 100% of the base, so on a 10.00 base the
        // 20.00 minimum is itself capped at 10.00.
        $this->assertSame('10.00', $this->fees->calculateResult('withdrawal_skrill', '10.00')->feeAmount);
        // On a base at or above the minimum the clamp applies normally.
        $this->assertSame('20.00', $this->fees->calculateResult('withdrawal_skrill', '100.00')->feeAmount);
    }

    // ------------------------------------------------------- architecture

    public function test_service_contains_no_float_arithmetic(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/PublicPages/FeesPageService.php'));

        $this->assertStringNotContainsString('(float)', $src);
        $this->assertStringNotContainsString('(float) ', $src);
        $this->assertStringNotContainsString('floatval', $src);
        $this->assertStringNotContainsString('(double)', $src);
        $this->assertDoesNotMatchRegularExpression('/(?<![a-zA-Z_])round\s*\(/', $src, 'The service must round through its own bcmath half-up helper, never PHP round()');
        $this->assertStringNotContainsStringIgnoringCase('thailotto', $src);
    }

    public function test_no_fee_value_is_hardcoded_into_the_view_layer(): void
    {
        foreach ([
            'resources/views/fees/index.blade.php',
            'resources/views/components/public/fee-table.blade.php',
        ] as $view) {
            $src = (string) file_get_contents(base_path($view));
            $this->assertStringNotContainsString('{!!', $src, $view.' must use escaped output only');
            $this->assertStringNotContainsString('0.0300', $src);
            $this->assertStringNotContainsString('0.0900', $src);
            $this->assertStringNotContainsStringIgnoringCase('thailotto', $src);
        }
    }

    public function test_rule_version_is_returned_per_row(): void
    {
        foreach ($this->fees->publicFees('en') as $row) {
            $this->assertSame(
                (string) config('fees.categories.'.$row['key'].'.rule_version'),
                $row['rule_version']
            );
        }

        $this->assertSame(
            (string) config('fees.categories.withdrawal_skrill.rule_version'),
            $this->fees->calculateResult('withdrawal_skrill', '100.00')->ruleVersion
        );
    }

    public function test_preview_whitelist_matches_the_public_projection(): void
    {
        $publicKeys = array_values(array_column($this->fees->publicFees('en'), 'key'));
        $previewKeys = $this->fees->previewCategories();

        $this->assertSame($publicKeys, $previewKeys);
        $this->assertNotContains('internal_provider_margin', $previewKeys);
    }
}
