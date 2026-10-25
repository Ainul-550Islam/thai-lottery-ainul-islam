<?php

declare(strict_types=1);

namespace Tests\Unit\Lottery;

use App\Enums\BetType;
use App\Services\Lottery\PayoutIllustrationService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The illustration is arithmetic, and this suite pins the arithmetic down.
 *
 * What it proves:
 *  - the rate is the PUBLISHED one (900 / 45 / 90 from config lottery.markets),
 *    never a number this service invented;
 *  - stake × rate is exact at 2 decimals, and the half-up step rounds the way
 *    the finance engine rounds money;
 *  - limits are refused, not clamped;
 *  - a bet type with no single published rate answers NOT_CONFIGURED for that
 *    bet type only — it never illustrates an unsanctioned figure;
 *  - the same input always produces the same output, and no probability figure
 *    exists anywhere in the result.
 */
final class PayoutIllustrationServiceTest extends TestCase
{
    private PayoutIllustrationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payout_illustration.enabled' => true,
            'payout_illustration.min_stake' => '1.00',
            'payout_illustration.max_stake' => '100000.00',
            'payout_illustration.currency' => 'THB',
            'payout_illustration.rounding.scale' => 2,
        ]);

        $this->service = app(PayoutIllustrationService::class);
    }

    public function test_a_published_rate_times_a_stake_is_exact_decimal_arithmetic(): void
    {
        $result = $this->service->illustrate('3d', '10.00', 'en');

        $this->assertSame(PayoutIllustrationService::STATE_READY, $result['state']);
        $this->assertSame('3d', $result['bet_type']);
        $this->assertSame('10.00', $result['stake']);
        // config('lottery.markets.3d_direct.payout_multiplier') === 900
        $this->assertSame('900.0000', $result['multiplier']);
        $this->assertSame('9000.00', $result['gross_return_if_matched']);
        $this->assertSame('9000.00', $result['net_if_matched']);
        $this->assertSame('THB', $result['currency']);
    }

    public function test_a_fractional_stake_stays_exact_without_float_drift(): void
    {
        // 123.45 × 45 (Tod) = 5555.25 exactly. A float engine drifts here.
        $result = $this->service->illustrate('tod', '123.45', 'en');

        $this->assertSame('45.0000', $result['multiplier']);
        $this->assertSame('5555.25', $result['gross_return_if_matched']);
    }

    public function test_two_d_markets_that_agree_produce_one_rate(): void
    {
        $result = $this->service->illustrate('2d', '20.00', 'en');

        $this->assertSame(PayoutIllustrationService::STATE_READY, $result['state']);
        $this->assertSame('90.0000', $result['multiplier']);
        $this->assertSame('1800.00', $result['gross_return_if_matched']);
        $this->assertSame(
            ['2d_top', '2d_bottom'],
            array_column($result['basis']['markets'], 'market'),
        );
    }

    public function test_rounding_is_half_up_and_never_uses_binary_floats(): void
    {
        // 2.675 is the classic float trap: PHP round(2.675, 2) is 2.67 because
        // the literal is stored as 2.67499999... Half-up on the decimal string is
        // 2.68, which is what money must do.
        $this->assertSame('2.68', PayoutIllustrationService::roundHalfUp('2.675'));
        $this->assertSame('1.01', PayoutIllustrationService::roundHalfUp('1.005'));
        $this->assertSame('1.00', PayoutIllustrationService::roundHalfUp('1.004'));
        $this->assertSame('0.10', PayoutIllustrationService::roundHalfUp('0.099'));
        $this->assertSame('4', PayoutIllustrationService::roundHalfUp('3.5', 0));
        // Half-up in magnitude for a negative value.
        $this->assertSame('-1.01', PayoutIllustrationService::roundHalfUp('-1.005'));
    }

    public function test_the_service_source_contains_no_float_arithmetic_or_randomness(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/Lottery/PayoutIllustrationService.php'));

        foreach (['(float)', '(double)', 'floatval', 'random_int', 'mt_rand', 'microtime', 'uniqid'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, $needle.' has no place in a deterministic illustration');
        }

        $this->assertDoesNotMatchRegularExpression(
            '/(?<![a-zA-Z_])round\s*\(/',
            $source,
            'Rounding must go through the bcmath half-up helper, never PHP round().',
        );
    }

    public function test_the_same_input_always_produces_the_same_output(): void
    {
        $first = $this->service->illustrate('3d', '250.00', 'en');
        $second = $this->service->illustrate('3d', '250.00', 'en');

        $this->assertSame($first, $second);
        $this->assertSame('225000.00', $first['gross_return_if_matched']);
    }

    public function test_no_probability_or_odds_figure_is_returned(): void
    {
        $result = $this->service->illustrate('3d', '10.00', 'en');

        foreach (['probability', 'odds', 'chance', 'win_rate', 'expected_value'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $result, $forbidden.' must not be part of an illustration');
        }

        // The disclaimer sentence itself says what the figure is NOT, so the
        // scan ignores the copy and inspects the figures and the basis only.
        unset($result['disclaimer']);
        unset($result['basis']['net_basis']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertDoesNotMatchRegularExpression('/probabilit|odds|chance|expected.?value/i', $encoded);
        $this->assertStringNotContainsString('%', $encoded, 'A percentage would read as a probability.');
    }

    public function test_the_disclaimer_accompanies_every_answer(): void
    {
        foreach (['3d', 'run'] as $betType) {
            $result = $this->service->illustrate($betType, '10.00', 'en');

            $this->assertSame('payout_illustration.disclaimer', $result['disclaimer_key']);
            $this->assertStringContainsString('not a prediction', (string) $result['disclaimer']);
        }
    }

    public function test_a_bet_type_whose_published_sources_contradict_answers_not_configured(): void
    {
        // Run is declared three ways in this project: run_top = 3, run_bottom = 4,
        // and lottery.types.run = 12 (the same value the BetType enum carries).
        // The strict resolver refuses to choose, so the illustration must too.
        $result = $this->service->illustrate('run', '100.00', 'en');

        $this->assertSame(PayoutIllustrationService::STATE_NOT_CONFIGURED, $result['state']);
        $this->assertNull($result['multiplier']);
        $this->assertNull($result['gross_return_if_matched']);
        $this->assertNull($result['net_if_matched']);
        $this->assertSame('payout_illustration.not_configured_multiplier', $result['reason_key']);
        $this->assertNotEmpty($result['disclaimer']);
        $this->assertSame(
            ['run_top', 'run_bottom'],
            array_column($result['basis']['markets'], 'market'),
        );
        $this->assertSame(
            [PayoutIllustrationService::STATE_NOT_CONFIGURED, PayoutIllustrationService::STATE_NOT_CONFIGURED],
            array_column($result['basis']['markets'], 'state'),
        );
    }

    public function test_a_disabled_market_leaves_only_that_bet_type_unconfigured(): void
    {
        config(['lottery.markets.3d_direct.enabled' => false]);

        $this->assertSame(
            PayoutIllustrationService::STATE_NOT_CONFIGURED,
            $this->service->illustrate('3d', '10.00', 'en')['state'],
        );

        // The other bet types are unaffected: the refusal is per bet type.
        $this->assertSame(
            PayoutIllustrationService::STATE_READY,
            $this->service->illustrate('tod', '10.00', 'en')['state'],
        );
    }

    public function test_a_stake_above_the_published_limit_is_refused_not_clamped(): void
    {
        $this->assertSame('100000.00', $this->service->assertStake('100000.00'));

        try {
            $this->service->assertStake('100000.01');
            $this->fail('A stake above the configured maximum must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('payout_illustration.stake_above_maximum', $exception->getMessage());
        }
    }

    public function test_zero_negative_and_malformed_stakes_are_refused(): void
    {
        foreach (['0', '0.00', '-10.00', '10.001', 'ten', ''] as $stake) {
            try {
                $this->service->assertStake($stake);
                $this->fail('Stake ['.$stake.'] must be refused.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringStartsWith('payout_illustration.', $exception->getMessage());
            }
        }
    }

    public function test_an_unsupported_bet_type_is_refused(): void
    {
        try {
            $this->service->illustrate('l6', '10.00', 'en');
            $this->fail('An unsupported bet type must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('payout_illustration.unsupported_bet_type', $exception->getMessage());
        }
    }

    public function test_every_supported_bet_type_is_a_real_bet_type(): void
    {
        $supported = $this->service->supportedBetTypes();

        foreach ($supported as $value) {
            $this->assertNotNull(BetType::tryFrom($value), $value.' is not a BetType case');
        }

        $this->assertSame(['2d', '3d', 'tod', 'run'], $supported);
    }

    public function test_the_rate_source_is_the_one_the_project_declares(): void
    {
        $result = $this->service->illustrate('3d', '10.00', 'en');

        $this->assertSame('lottery.markets', $result['basis']['multiplier_source']);
        $this->assertSame('bcmath', $result['basis']['rounding']['engine']);
        $this->assertSame(2, $result['basis']['rounding']['scale']);
        $this->assertSame('half_up', $result['basis']['rounding']['mode']);
        $this->assertSame('none_published', $result['basis']['deductions']);
    }
}
