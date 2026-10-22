<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Enums\WalletConversionDirection;
use App\Exceptions\Wallet\ConversionException;
use App\Models\WalletConversion;
use App\Services\PublicPages\FeesPageService;
use PHPUnit\Framework\Attributes\Test;

/**
 * DISPLAY == EXECUTION: the fee the page shows is the fee the engine charges.
 *
 * This is the single most important test in the feature. Every other guarantee —
 * balances, the ledger, the audit trail — is about what happens after a member
 * agrees to a price. This one is about whether the price they were shown is the
 * price they paid.
 *
 * HOW THE TWO SIDES ARE COMPUTED, AND WHY THEY CANNOT DRIFT
 * The public fee page resolves the row's `execution` path through
 * FeesPageService::resolveRate; the engine resolves the same path through
 * ConversionFeeCalculator::effectiveRate. Both read
 * `fees.conversion.<direction>_percentage` as a whole percent and divide by 100
 * with bcdiv. The test does not hard-code "1%" anywhere as the expected value: it
 * computes both sides from the live configuration and compares them, so changing
 * the rate in config keeps this test meaningful instead of making it fail.
 *
 * WHY A ONE-THB FEE ON A ONE-HUNDRED CONVERSION IS ALSO ASSERTED LITERALLY
 * The parity comparison proves the two sides agree; it cannot prove they agree
 * on the RIGHT number. The literal assertion pins the published tariff itself,
 * so a config change that silently moved both sides to 5% — agreeing perfectly
 * with each other — would still be caught.
 */
class ConversionFeeParityTest extends ConversionTestCase
{
    #[Test]
    public function the_public_page_and_the_engine_publish_the_same_rate_for_both_directions(): void
    {
        $page = app(FeesPageService::class);

        foreach (WalletConversionDirection::cases() as $direction) {
            $row = config($direction->feeConfigPath());
            $this->assertIsArray($row, 'The fee row for '.$direction->value.' must exist.');
            $this->assertNotNull($row['execution'] ?? null, 'The row must name the live rule it mirrors.');

            $pageRate = $page->resolveRate($row);
            $engineRate = $this->calculator()->breakdownFor($direction, '100.00', 'THB')->rate;

            $this->assertNotNull($pageRate, 'The page must resolve a rate for '.$direction->value.'.');
            $this->assertSame(
                0,
                bccomp($pageRate, $engineRate, 4),
                sprintf(
                    'The page shows %s and the engine would charge %s for %s.',
                    (string) $pageRate,
                    $engineRate,
                    $direction->value,
                ),
            );
        }
    }

    #[Test]
    public function one_percent_means_one_thb_on_a_hundred_thb_conversion(): void
    {
        $breakdown = $this->calculator()->breakdownFor(
            WalletConversionDirection::CashToWin,
            '100.00',
            'THB',
        );

        $this->assertTrue($breakdown->isConfigured, 'The published rule must be live.');
        $this->assertSame('1.00', $breakdown->feeAmount, 'A 1% fee on 100.00 is 1.00.');
        $this->assertSame('99.00', $breakdown->netAmount, 'The member receives 99.00.');
        $this->assertSame('0.0100', $breakdown->rate);
        $this->assertSame('1.00', $breakdown->percentage);
    }

    #[Test]
    public function the_engine_charges_exactly_what_the_breakdown_promised(): void
    {
        $member = $this->makeFundedMember();

        $breakdown = $this->calculator()->breakdownFor(
            WalletConversionDirection::CashToWin,
            '250.00',
            'THB',
        );

        $result = $this->convert($member['user'], '250.00');

        $this->assertSame($breakdown->feeAmount, $result->feeAmount, 'The charged fee must equal the quoted fee.');
        $this->assertSame($breakdown->netAmount, $result->amountOut, 'The delivered amount must equal the quote.');
    }

    #[Test]
    public function the_rule_version_is_recorded_so_a_later_price_change_cannot_rewrite_history(): void
    {
        $member = $this->makeFundedMember();
        $result = $this->convert($member['user'], '100.00');

        $conversion = WalletConversion::query()->where('reference', $result->reference)->firstOrFail();

        $this->assertSame(
            (string) config('fees.categories.cash_to_win.rule_version'),
            (string) $conversion->fee_rule_version,
            'The movement must carry the version of the rule that priced it.',
        );
    }

    #[Test]
    public function an_unreadable_live_value_falls_back_to_the_published_row_not_to_zero(): void
    {
        // The live path resolves to nothing — a typo in config, a key that moved.
        // The row still carries its published rate, so the engine must charge
        // THAT. Silently charging nothing would be worse than charging the wrong
        // documented number, because the member would keep the fee and the
        // platform would never see a failure.
        config(['fees.conversion.cash_to_win_percentage' => null]);

        $breakdown = $this->calculator()->breakdownFor(
            WalletConversionDirection::CashToWin,
            '100.00',
            'THB',
        );

        $row = config(WalletConversionDirection::CashToWin->feeConfigPath());

        $this->assertTrue($breakdown->isConfigured);
        $this->assertSame(
            0,
            bccomp((string) $row['rate'], $breakdown->rate, 4),
            'The fallback must be the rate published beside the row.',
        );
        $this->assertSame('1.00', $breakdown->feeAmount);
    }

    #[Test]
    public function a_row_with_no_price_at_all_is_refused_rather_than_treated_as_free(): void
    {
        // Both the live path and the published rate are gone: there is no price.
        // The breakdown must say so explicitly, and the engine must refuse the
        // conversion with a named error instead of moving 100.00 for a 0.00 fee.
        config([
            'fees.categories.cash_to_win.execution' => null,
            'fees.categories.cash_to_win.rate' => null,
        ]);

        $breakdown = $this->calculator()->breakdownFor(
            WalletConversionDirection::CashToWin,
            '100.00',
            'THB',
        );

        $this->assertFalse($breakdown->isConfigured, 'No price must mean unconfigured, never zero.');

        $member = $this->makeFundedMember();

        try {
            $this->convert($member['user'], '100.00');
            $this->fail('A conversion with no published price must not execute.');
        } catch (ConversionException $exception) {
            // The refusal code is the engine's own: with no live rule the
            // direction is, from the member's point of view, unavailable.
            $this->assertSame('feature_disabled', (string) $exception->errorCode());
        }

        $this->assertSame('1000.00', $this->balanceOf($member['cash']), 'No money may move when the price is unknown.');
        $this->assertSame('0.00', $this->balanceOf($member['win']));
    }
}
