<?php

declare(strict_types=1);

namespace Tests\Feature\Lottery;

use App\DTOs\Account\GradeDiscountEntitlement;
use App\DTOs\Lottery\DiscountRule;
use App\Enums\DiscountGame;
use App\Enums\DiscountLottery;
use App\Models\User;
use App\Services\Account\GradeDiscountApplicationPolicy;
use App\Services\Account\GradeTierCatalog;
use App\Services\Lottery\CanonicalDiscountMatrixService;
use App\Services\Lottery\DiscountParityProjectionService;
use App\Services\Lottery\LottoDiscountCalculator;
use App\Services\Pricing\LottoDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PROMPT 2 — the canonical game/prize/discount matrix (F–I sections).
 *
 * The matrix is a NEW canonical game space alongside the traced live
 * product lanes: it serves the public parity surface and the quote
 * calculator. It never feeds the live bet economics (that path keeps
 * LottoDiscountService untouched), and GLO is structurally absent.
 *
 * ALL ARITHMETIC ASSERTIONS ARE DECIMAL STRINGS. The calculator must
 * derive every combined percentage from money, never by adding rate
 * floats.
 */
final class DiscountMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Benchmark headline rules: game => [D, R, percent]. */
    private const HEADLINE = [
        'national_six_digit' => ['3000', '500', '35.00'],
        'national_3_up' => ['500', '100', '30.00'],
        'national_2_up' => ['80', '40', '20.00'],
        'national_2_down' => ['80', '40', '20.00'],
        'weekly_6_ball' => ['1000', '500', '35.00'],
        'weekly_3_ball' => ['200', '60', '20.00'],
        'weekly_2_ball' => ['50', '25', '15.00'],
    ];

    // =====================================================================
    // F — the matrix itself
    // =====================================================================

    public function test_every_public_game_appears_exactly_once_in_the_matrix(): void
    {
        $matrix = app(CanonicalDiscountMatrixService::class)->matrix();

        $seen = [];

        foreach (DiscountLottery::cases() as $family) {
            foreach ($matrix->rules($family) as $rule) {
                $seen[] = $rule->game->value;
                $this->assertSame($family, $rule->lottery);
            }
        }

        $expected = array_map(static fn (DiscountGame $g): string => $g->value, DiscountGame::publicGames());

        $this->assertCount(count($expected), array_unique($seen));
        $this->assertSame([], array_diff($expected, $seen));
        $this->assertSame([], array_diff($seen, $expected));
    }

    public function test_the_matrix_has_ten_national_and_eight_weekly_rules(): void
    {
        $matrix = app(CanonicalDiscountMatrixService::class)->matrix();

        $this->assertCount(10, $matrix->rules(DiscountLottery::National));
        $this->assertCount(8, $matrix->rules(DiscountLottery::BangkokWeekly));
        $this->assertSame('THB', $matrix->currency);
        $this->assertSame('1.00', $matrix->baseStake);
        $this->assertSame('1', $matrix->ruleVersion);
    }

    public function test_headline_rules_carry_both_directions_and_the_configured_percent(): void
    {
        $service = app(CanonicalDiscountMatrixService::class);

        foreach (self::HEADLINE as $gameKey => [$d, $r, $percent]) {
            $game = DiscountGame::from($gameKey);
            $rule = $service->ruleFor($game);

            $this->assertNotNull($rule, $gameKey.' is missing from the matrix.');
            $this->assertSame($d, $rule->multiplier('D'), $gameKey.' D multiplier');
            $this->assertSame($r, $rule->multiplier('R'), $gameKey.' R multiplier');
            $this->assertSame($percent, $rule->discount, $gameKey.' discount');
            $this->assertSame('CONFIGURED', $rule->discountState());
            $this->assertSame($percent.'%', $rule->discountDisplay());
        }
    }

    public function test_variant_rules_are_single_multiplier_and_not_configured(): void
    {
        $service = app(CanonicalDiscountMatrixService::class);

        $variants = array_diff(
            array_map(static fn (DiscountGame $g): string => $g->value, DiscountGame::publicGames()),
            array_keys(self::HEADLINE),
        );

        $this->assertCount(11, $variants);

        foreach ($variants as $gameKey) {
            $rule = $service->ruleFor(DiscountGame::from($gameKey));

            $this->assertNotNull($rule);
            $this->assertTrue($rule->hasMode(DiscountRule::MODE_SINGLE));
            $this->assertFalse($rule->hasMode(DiscountRule::MODE_DIRECT));
            $this->assertNull($rule->discount);
            $this->assertSame('NOT_CONFIGURED', $rule->discountState());
            $this->assertNotSame('', $rule->multiplier('*'), $gameKey.' must still publish its win multiplier');
        }
    }

    public function test_glo_products_are_structurally_absent_from_the_game_space(): void
    {
        // The government-priced lanes are not matrix games; the discount
        // programme cannot even express them, so it can never discount one.
        $keys = array_map(static fn (DiscountGame $g): string => $g->value, DiscountGame::publicGames());

        $this->assertSame([], array_filter($keys, static fn (string $k): bool => str_contains($k, 'glo')
            || str_contains($k, 'l6') || str_contains($k, 'n3')));
    }

    public function test_affiliate_commission_is_two_percent_for_both_lotteries(): void
    {
        $matrix = app(CanonicalDiscountMatrixService::class)->matrix();

        $this->assertSame('2.00', $matrix->affiliateCommission['national']);
        $this->assertSame('2.00', $matrix->affiliateCommission['bangkok_weekly']);
    }

    public function test_the_live_bet_economics_are_untouched_by_the_new_calculator(): void
    {
        // The canonical calculator exists for the matrix/quote surface
        // only. The traced live quote keeps its own immutable contract.
        $live = app(LottoDiscountService::class);

        $quote = $live->quote('glo_l6', null, '80.00');

        $this->assertSame('GLO_PRICE_IMMUTABLE', $quote['refused']);
        $this->assertSame('80.00', $quote['final']);
    }

    // =====================================================================
    // G+H — the calculator and the application policy
    // =====================================================================

    public function test_an_anonymous_quote_is_the_published_game_rule_verbatim(): void
    {
        $result = app(LottoDiscountCalculator::class)
            ->calculateAnonymous(DiscountGame::NationalSixDigit, '100.00', 'D');

        $this->assertSame('national', $result['lottery']);
        $this->assertSame('national_six_digit', $result['game']);
        $this->assertSame('D', $result['mode']);
        $this->assertSame('100.00', $result['gross_stake']);
        $this->assertSame('3000', $result['payout_multiplier']);
        $this->assertSame('300000.00', $result['projected_payout']);
        $this->assertSame('35.00', $result['applicable_discount_percent']);
        $this->assertSame('35.00', $result['game_discount_percent']);
        $this->assertSame('35.00', $result['discount_amount']);
        $this->assertSame('65.00', $result['net_stake']);
        $this->assertSame('GAME_ONLY', $result['policy_mode']);
        $this->assertSame('bronze', $result['grade_level']);
        $this->assertSame('NOT_ELIGIBLE', $result['grade_state']);
        $this->assertSame('THB', $result['currency']);
    }

    public function test_the_reverse_direction_uses_the_reverse_multiplier(): void
    {
        $result = app(LottoDiscountCalculator::class)
            ->calculateAnonymous(DiscountGame::NationalSixDigit, '100.00', 'R');

        $this->assertSame('R', $result['mode']);
        $this->assertSame('500', $result['payout_multiplier']);
        $this->assertSame('50000.00', $result['projected_payout']);
        $this->assertSame('65.00', $result['net_stake']);
    }

    public function test_a_headline_game_refuses_to_quote_without_a_direction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LottoDiscountCalculator::class)->calculateAnonymous(DiscountGame::NationalSixDigit, '100.00');
    }

    public function test_a_variant_refuses_the_d_or_r_direction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LottoDiscountCalculator::class)->calculateAnonymous(
            DiscountGame::National1Of3UpSingleDigit,
            '100.00',
            'D',
        );
    }

    public function test_a_variant_quote_without_a_game_discount_charges_the_full_stake(): void
    {
        $result = app(LottoDiscountCalculator::class)
            ->calculateAnonymous(DiscountGame::National1Of3UpSingleDigit, '100.00');

        $this->assertSame('*', $result['mode']);
        $this->assertNull($result['game_discount_percent']);
        $this->assertSame('0.00', $result['discount_amount']);
        $this->assertSame('100.00', $result['net_stake']);
        $this->assertSame('NONE', $result['policy_mode']);
    }

    /**
     * THE money test: game layer first, grade layer on what remains.
     * 100.00 → 35% game = 35.00; 6% of the remaining 65.00 = 3.90;
     * total 38.90; net 61.10. The combined percent is derived from
     * money (38.90), never from 35 + 6.
     */
    public function test_the_grade_layer_stacks_additively_on_the_remaining_stake(): void
    {
        $user = User::factory()->create();

        $result = app(LottoDiscountCalculator::class)
            ->calculate(DiscountGame::NationalSixDigit, '100.00', 'D', $user);

        // No spend → base tier → the grade layer contributes exactly
        // zero and says so explicitly.
        $this->assertSame('bronze', $result['grade_level']);
        $this->assertSame('NOT_ELIGIBLE', $result['eligibility_state']);
        $this->assertSame('0.00', $result['grade_discount_percent']);
        $this->assertSame('35.00', $result['discount_amount']);
        $this->assertSame('65.00', $result['net_stake']);
    }

    public function test_an_entitled_grade_stacks_on_the_game_rule(): void
    {
        $catalog = app(GradeTierCatalog::class);
        $policy = app(GradeDiscountApplicationPolicy::class);
        $rule = app(CanonicalDiscountMatrixService::class)->ruleFor(DiscountGame::NationalSixDigit);

        $entitlement = GradeDiscountEntitlement::eligible(
            DiscountGame::NationalSixDigit,
            app(GradeTierCatalog::class)->tierByKey('diamond_plus'),
        );

        $result = $policy->resolve($rule, $entitlement, '100.00');

        $this->assertSame('STACKED', $result['mode']);
        $this->assertSame('35.00', $result['game_discount']);
        $this->assertSame('6.00', $result['grade_discount_percent']);
        $this->assertSame('3.90', $result['grade_discount']);
        $this->assertSame('38.90', $result['total_discount']);
        $this->assertSame('61.10', $result['net_stake']);
        $this->assertSame('ELIGIBLE', $result['grade_state']);
    }

    public function test_a_not_entitled_grade_contributes_exactly_zero_and_says_why(): void
    {
        $rule = app(CanonicalDiscountMatrixService::class)->ruleFor(DiscountGame::Weekly3Ball);

        $entitlement = GradeDiscountEntitlement::notEligible(
            DiscountGame::Weekly3Ball,
            app(GradeTierCatalog::class)->tierByKey('gold_plus'),
        );

        $result = app(GradeDiscountApplicationPolicy::class)->resolve($rule, $entitlement, '100.00');

        $this->assertSame('GAME_ONLY', $result['mode']);
        $this->assertSame('0.00', $result['grade_discount']);
        $this->assertSame('0.00', $result['grade_discount_percent']);
        $this->assertSame('NOT_ELIGIBLE', $result['grade_state']);
    }

    public function test_an_entitled_grade_applies_to_a_not_configured_game_as_grade_only(): void
    {
        $rule = app(CanonicalDiscountMatrixService::class)->ruleFor(DiscountGame::National1Of3UpSingleDigit);

        $entitlement = GradeDiscountEntitlement::eligible(
            DiscountGame::National1Of3UpSingleDigit,
            app(GradeTierCatalog::class)->tierByKey('diamond_plus'),
        );

        $result = app(GradeDiscountApplicationPolicy::class)->resolve($rule, $entitlement, '100.00');

        // An unspecified game percentage is a state, never a guessed
        // number — but the player's own grade entitlement still applies.
        $this->assertSame('GRADE_ONLY', $result['mode']);
        $this->assertNull($result['game_discount_percent']);
        $this->assertSame('0.00', $result['game_discount']);
        $this->assertSame('6.00', $result['grade_discount']);
        $this->assertSame('6.00', $result['total_discount']);
        $this->assertSame('94.00', $result['net_stake']);
    }

    public function test_the_global_ceiling_caps_the_combined_discount(): void
    {
        config(['discounts.limits.max_total_percentage' => '36.00']);

        $rule = app(CanonicalDiscountMatrixService::class)->ruleFor(DiscountGame::NationalSixDigit);

        $entitlement = GradeDiscountEntitlement::eligible(
            DiscountGame::NationalSixDigit,
            app(GradeTierCatalog::class)->tierByKey('diamond_plus'),
        );

        // Uncapped: 38.90. Capped at 36%: 36.00.
        $result = app(GradeDiscountApplicationPolicy::class)->resolve($rule, $entitlement, '100.00');

        $this->assertSame('36.00', $result['total_discount']);
        $this->assertSame('64.00', $result['net_stake']);
    }

    public function test_discount_rounding_is_half_up_on_strings_not_floats(): void
    {
        // 33.33 × 0.35 = 11.6655 → half-up at two decimals → 11.67.
        $rule = app(CanonicalDiscountMatrixService::class)->ruleFor(DiscountGame::NationalSixDigit);

        $entitlement = GradeDiscountEntitlement::notEligible(
            DiscountGame::NationalSixDigit,
            app(GradeTierCatalog::class)->baseTier(),
        );

        $result = app(GradeDiscountApplicationPolicy::class)->resolve($rule, $entitlement, '33.33');

        $this->assertSame('11.67', $result['game_discount']);
        $this->assertSame('21.66', $result['net_stake']);
    }

    public function test_a_negative_stake_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LottoDiscountCalculator::class)->calculateAnonymous(DiscountGame::NationalSixDigit, '-5.00', 'D');
    }

    public function test_stakes_with_more_than_two_decimals_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(LottoDiscountCalculator::class)->calculateAnonymous(DiscountGame::NationalSixDigit, '100.123', 'D');
    }

    public function test_exponent_and_separator_stake_notation_is_refused(): void
    {
        $refused = [];

        foreach (['1e3', '1,000.00', ' 100.00'] as $stake) {
            try {
                app(LottoDiscountCalculator::class)->calculateAnonymous(DiscountGame::NationalSixDigit, $stake, 'D');
            } catch (InvalidArgumentException) {
                $refused[] = $stake;
            }
        }

        $this->assertSame(['1e3', '1,000.00', ' 100.00'], $refused);
    }

    public function test_the_combined_percent_is_derived_from_money_not_rate_addition(): void
    {
        $rule = app(CanonicalDiscountMatrixService::class)->ruleFor(DiscountGame::NationalSixDigit);

        $entitlement = GradeDiscountEntitlement::eligible(
            DiscountGame::NationalSixDigit,
            app(GradeTierCatalog::class)->tierByKey('diamond_plus'),
        );

        $result = app(GradeDiscountApplicationPolicy::class)->resolve($rule, $entitlement, '100.00');

        // 38.90 out of 100.00 — derived from the money. Adding the rate
        // percentages against the ORIGINAL stake instead (35% + 6% = 41%)
        // is exactly the float shortcut this calculator must not take.
        $this->assertSame('38.90', $result['total_discount']);
        $this->assertSame('61.10', $result['net_stake']);
    }

    public function test_the_calculator_resolves_a_real_users_tier_server_side(): void
    {
        $user = User::factory()->create();

        $result = app(LottoDiscountCalculator::class)
            ->calculate(DiscountGame::Weekly2Ball, '100.00', 'D', $user);

        $this->assertSame('bronze', $result['grade_level']);
        $this->assertSame('15.00', $result['game_discount_percent']);
        $this->assertSame('15.00', $result['discount_amount']);
        $this->assertSame('2', $result['grade_rule_version']);
        $this->assertSame('1', $result['rule_version']);
    }

    // =====================================================================
    // I — public projections and the rendered pages
    // =====================================================================

    public function test_the_projection_publishes_both_lottery_families_with_labels(): void
    {
        $matrix = app(DiscountParityProjectionService::class)->discountMatrix();

        $this->assertSame('CONFIGURED', $matrix['status']);
        $this->assertSame('THB', $matrix['currency']);

        $this->assertSame(
            ['national', 'bangkok_weekly'],
            array_column($matrix['lotteries'], 'key'),
        );

        foreach ($matrix['lotteries'] as $family) {
            $this->assertNotSame('', $family['label']);
            $this->assertNotSame($family['key'], $family['label']);
            $this->assertSame('2.00', $family['affiliate_commission_percent']);

            foreach ($family['games'] as $game) {
                // Field whitelist: no ids, no internals ride along.
                $this->assertSame([
                    'lottery', 'game', 'label', 'd_multiplier', 'r_multiplier',
                    'multiplier', 'has_modes', 'discount', 'discount_state',
                    'discount_display', 'base_stake', 'currency', 'rule_version',
                ], array_keys($game));
            }
        }
    }

    public function test_the_discounts_page_renders_the_matrix_with_not_configured_states(): void
    {
        $response = $this->get(route('discounts'));

        $response->assertOk();
        $response->assertSee(trans('prize_discount.matrix_lottery_national'), false);
        $response->assertSee(trans('prize_discount.matrix_lottery_bangkok_weekly'), false);
        $response->assertSee(trans('prize_discount.matrix_col_discount'), false);
        $response->assertSee('NOT_CONFIGURED', false);
        $response->assertSee('data-pd-matrix-lottery="national"', false);
        $response->assertSee('data-pd-matrix-lottery="bangkok_weekly"', false);
        // The D/R multiplier cells carry the configured base stake unit.
        $response->assertSee('1.00 × 3000', false);
        $response->assertSee('1.00 × 500', false);
    }

    public function test_the_discounts_page_never_renders_a_usd_literal(): void
    {
        $body = $this->get(route('discounts'))->assertOk()->getContent();

        $this->assertStringNotContainsString('$', $body);
    }

    public function test_the_public_grades_page_renders_sl_icon_and_discount_of_game_columns(): void
    {
        $response = $this->get(route('account-grades'));

        $response->assertOk();
        $response->assertSee(trans('account_info.col_sl'), false);
        $response->assertSee(trans('account_info.col_discount_of_game'), false);
        $response->assertSee('data-grade-games-toggle', false);

        // Every programme tier renders its SL position and its own panel.
        foreach (['gold_plus', 'platinum', 'platinum_plus', 'diamond', 'diamond_plus'] as $key) {
            $response->assertSee('data-grade-tier="'.$key.'"', false);
            $response->assertSee('id="grade-games-'.$key.'"', false);
        }
    }

    public function test_the_public_grades_page_renders_each_tiers_entitled_games(): void
    {
        $body = $this->get(route('account-grades'))->assertOk()->getContent();

        // gold_plus: the four benchmark games, by their public labels.
        $this->assertStringContainsString(trans('prize_discount.matrix_game_national_six_digit'), $body);
        $this->assertStringContainsString(trans('prize_discount.matrix_game_national_3_up'), $body);
        $this->assertStringContainsString(trans('prize_discount.matrix_game_national_2_up'), $body);
        $this->assertStringContainsString(trans('prize_discount.matrix_game_weekly_6_ball'), $body);
        $this->assertStringContainsString('data-grade-game-count="4"', $body);
        $this->assertStringContainsString('data-grade-game-count="18"', $body);
    }

    public function test_the_authenticated_grade_page_lists_explicit_entitlement_answers(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('account.grade'));

        $response->assertOk();
        $response->assertSee(trans('account_services.grade_entitlements_title'), false);
        $response->assertSee(trans('account_services.grade_entitlements_not_eligible'), false);
        $response->assertSee('data-entitlement-game="national_six_digit"', false);
        $response->assertSee('data-entitlement-state="NOT_ELIGIBLE"', false);
    }

    public function test_the_public_surfaces_expose_no_identifiers(): void
    {
        $user = User::factory()->create(['email' => 'grade-private@example.test']);

        foreach ([route('discounts'), route('account-grades')] as $url) {
            $body = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('grade-private@example.test', $body);
            $this->assertStringNotContainsString('qualifying_spend', $body);
        }

        unset($user);
    }
}
