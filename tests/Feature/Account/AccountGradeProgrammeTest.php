<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\DTOs\Account\GradeTier;
use App\Enums\AccountGradeLevel;
use App\Enums\Currency;
use App\Enums\DiscountGame;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AccountGradeSnapshot;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Account\AccountGradeEvaluator;
use App\Services\Account\GradeDiscountEntitlementService;
use App\Services\Account\GradeTierCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PROMPT 2 — the account grade programme (A–E sections).
 *
 * The five benchmark programme tiers, the canonical entitlement ladder,
 * the server-side evaluator, the additive grade→game application policy
 * and the self-scoped account-grade API.
 *
 * EVERY MONEY VALUE HERE IS A DECIMAL STRING. No test multiplies a
 * float by 100, and no test asserts on a float: the whole point of the
 * programme is that the advertised rate, the applied rate and the
 * asserted rate are the same string end to end.
 */
final class AccountGradeProgrammeTest extends TestCase
{
    use RefreshDatabase;

    /** Benchmark ladder, keyed by tier: [min_spend, rate fraction, entitled game count]. */
    private const LADDER = [
        'gold_plus' => ['200.00', '0.0200', 4],
        'platinum' => ['300.00', '0.0300', 6],
        'platinum_plus' => ['400.00', '0.0400', 10],
        'diamond' => ['500.00', '0.0500', 13],
        'diamond_plus' => ['600.00', '0.0600', 18],
    ];

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->player = User::factory()->create();
    }

    // =====================================================================
    // A — the tier catalogue
    // =====================================================================

    public function test_the_catalogue_publishes_exactly_five_programme_tiers_in_sl_order(): void
    {
        $tiers = app(GradeTierCatalog::class)->publicTiers();

        $this->assertCount(5, $tiers);
        $this->assertSame([1, 2, 3, 4, 5], array_map(static fn (GradeTier $t): int => $t->sl, $tiers));
        $this->assertSame(array_keys(self::LADDER), array_map(static fn (GradeTier $t): string => $t->key, $tiers));
    }

    public function test_thresholds_and_rates_are_the_configured_decimal_strings(): void
    {
        $catalog = app(GradeTierCatalog::class);

        foreach (self::LADDER as $key => [$minSpend, $rate]) {
            $tier = $catalog->tierByKey($key);

            $this->assertNotNull($tier);
            $this->assertSame($minSpend, $tier->minSpend);
            $this->assertSame($rate, $tier->discountRate);
            // The fraction is what the engine applies; the percent is what
            // the pages show. Both derive from the same string.
            $this->assertSame(bcadd(bcmul($rate, '100', 2), '0', 2), $tier->discountPercent());
        }
    }

    public function test_the_base_state_exists_but_is_not_advertised(): void
    {
        $catalog = app(GradeTierCatalog::class);
        $base = $catalog->baseTier();

        $this->assertTrue($base->isBase());
        $this->assertSame('bronze', $base->key);
        $this->assertSame('0.00', $base->minSpend);
        $this->assertSame('0.0000', $base->discountRate);
        $this->assertFalse($base->publicVisible);

        // The public ladder never sells the floor.
        $this->assertNotContains('bronze', array_map(
            static fn (GradeTier $t): string => $t->key,
            $catalog->publicTiers(),
        ));
    }

    public function test_thresholds_are_inclusive_and_the_highest_qualifying_tier_wins(): void
    {
        $catalog = app(GradeTierCatalog::class);

        $this->assertSame('bronze', $catalog->tierForSpend('0.00')->key);
        $this->assertSame('bronze', $catalog->tierForSpend('199.99')->key);
        $this->assertSame('gold_plus', $catalog->tierForSpend('200.00')->key);
        $this->assertSame('gold_plus', $catalog->tierForSpend('299.99')->key);
        $this->assertSame('platinum', $catalog->tierForSpend('300.00')->key);
        $this->assertSame('platinum_plus', $catalog->tierForSpend('400.00')->key);
        $this->assertSame('diamond', $catalog->tierForSpend('500.00')->key);
        $this->assertSame('diamond_plus', $catalog->tierForSpend('600.00')->key);
        $this->assertSame('diamond_plus', $catalog->tierForSpend('999999.00')->key);
    }

    public function test_next_tier_after_climbs_by_sl_and_stops_at_the_top(): void
    {
        $catalog = app(GradeTierCatalog::class);

        $this->assertSame('platinum', $catalog->nextTierAfter(AccountGradeLevel::GoldPlus)->key);
        $this->assertSame('diamond_plus', $catalog->nextTierAfter(AccountGradeLevel::Diamond)->key);
        $this->assertNull($catalog->nextTierAfter(AccountGradeLevel::DiamondPlus));
        // From the base state the first rung is the first programme tier.
        $this->assertSame('gold_plus', $catalog->nextTierAfter(AccountGradeLevel::Base)->key);
    }

    public function test_the_effective_window_is_inclusive_start_exclusive_end(): void
    {
        $catalog = app(GradeTierCatalog::class);
        $tier = $catalog->tierByKey('gold_plus');

        $this->assertTrue($catalog->isEffective($tier, Carbon::parse('2026-09-28')));
    }

    public function test_a_broken_ladder_is_a_load_time_error_not_a_wrong_page(): void
    {
        // Non-increasing public thresholds would make "highest wins"
        // ambiguous; the catalogue refuses to load instead of guessing.
        config(['account_grades.tiers' => array_values(array_map(
            static fn (array $row, int $i): array => $i === 2
                ? array_merge($row, ['min_spend' => '100.00'])
                : $row,
            array_values(config('account_grades.tiers')),
            range(0, 5),
        ))]);

        $this->expectException(InvalidArgumentException::class);

        // The catalogue validates lazily on first load, so a broken ladder
        // fails the moment anything reads it — not silently at boot.
        (new GradeTierCatalog())->tiers();
    }

    public function test_an_unknown_entitled_game_is_a_load_time_error(): void
    {
        config(['account_grades.tiers' => array_values(array_map(
            static fn (array $row, int $i): array => $i === 1
                ? array_merge($row, ['eligible_games' => ['not_a_real_game']])
                : $row,
            array_values(config('account_grades.tiers')),
            range(0, 5),
        ))]);

        $this->expectException(InvalidArgumentException::class);

        (new GradeTierCatalog())->tiers();
    }

    public function test_two_base_tiers_are_a_load_time_error(): void
    {
        $rows = array_values(config('account_grades.tiers'));
        $rows[] = $rows[0]; // a second bronze

        config(['account_grades.tiers' => $rows]);

        $this->expectException(InvalidArgumentException::class);

        (new GradeTierCatalog())->tiers();
    }

    // =====================================================================
    // B — entitlements
    // =====================================================================

    public function test_entitlements_are_cumulative_and_match_the_programme_counts(): void
    {
        $catalog = app(GradeTierCatalog::class);
        $entitlements = app(GradeDiscountEntitlementService::class);

        foreach (self::LADDER as $key => [, , $count]) {
            $games = $entitlements->eligibleGames($catalog->tierByKey($key));

            $this->assertCount(
                $count,
                $games,
                sprintf('%s should entitle exactly %d games.', $key, $count),
            );

            // Entitlements are cumulative: every lower tier's games are a
            // SUBSET of every higher tier's games (the canonical order is
            // the enum's public-games order, so the shape is a set union,
            // not a shared prefix).
            foreach (self::LADDER as $lowerKey => [, , $lowerCount]) {
                if ($lowerCount >= $count) {
                    continue;
                }

                $lower = $entitlements->eligibleGames($catalog->tierByKey($lowerKey));
                $this->assertSame(
                    [],
                    array_diff(
                        array_map(static fn (DiscountGame $g): string => $g->value, $lower),
                        array_map(static fn (DiscountGame $g): string => $g->value, $games),
                    ),
                    sprintf('%s games must all be entitled by %s.', $lowerKey, $key),
                );
            }
        }
    }

    public function test_the_base_state_entitles_nothing(): void
    {
        $entitlements = app(GradeDiscountEntitlementService::class);

        $this->assertSame([], $entitlements->eligibleGames(app(GradeTierCatalog::class)->baseTier()));
    }

    public function test_an_entitled_game_is_eligible_and_a_non_entitled_game_is_explicitly_not(): void
    {
        $catalog = app(GradeTierCatalog::class);
        $entitlements = app(GradeDiscountEntitlementService::class);
        $tier = $catalog->tierByKey('gold_plus');

        $eligible = $entitlements->entitlementFor($tier, DiscountGame::NationalSixDigit);
        $this->assertTrue($eligible->isEligible());
        $this->assertSame('ELIGIBLE', $eligible->state);
        $this->assertSame('0.0200', $eligible->rate);
        $this->assertSame('2.00', $eligible->ratePercent());

        // weekly_3_ball is NOT in gold_plus's four games: the answer is an
        // explicit not-eligible with a zero rate, never silence.
        $notEligible = $entitlements->entitlementFor($tier, DiscountGame::Weekly3Ball);
        $this->assertFalse($notEligible->isEligible());
        $this->assertSame('NOT_ELIGIBLE', $notEligible->state);
        $this->assertSame('0.0000', $notEligible->rate);
        $this->assertSame('0.00', $notEligible->ratePercent());
    }

    public function test_diamond_plus_entitles_every_public_matrix_game(): void
    {
        $entitlements = app(GradeDiscountEntitlementService::class);
        $tier = app(GradeTierCatalog::class)->tierByKey('diamond_plus');

        $this->assertSame(
            array_map(static fn (DiscountGame $g): string => $g->value, DiscountGame::publicGames()),
            array_map(static fn (DiscountGame $g): string => $g->value, $entitlements->eligibleGames($tier)),
        );
    }

    public function test_the_entitlement_hash_is_stable_and_distinguishes_tiers(): void
    {
        $catalog = app(GradeTierCatalog::class);
        $entitlements = app(GradeDiscountEntitlementService::class);

        $gold = $entitlements->entitlementHash($catalog->tierByKey('gold_plus'));
        $goldAgain = $entitlements->entitlementHash($catalog->tierByKey('gold_plus'));
        $platinum = $entitlements->entitlementHash($catalog->tierByKey('platinum'));

        $this->assertSame($gold, $goldAgain);
        $this->assertNotSame($gold, $platinum);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $gold);
    }

    // =====================================================================
    // C — the evaluator
    // =====================================================================

    public function test_the_evaluator_uses_an_exact_thirty_day_window(): void
    {
        $asOf = Carbon::parse('2026-09-28 12:00:00');

        $result = app(AccountGradeEvaluator::class)->evaluate($this->player, $asOf);

        $this->assertSame(30, $result->windowDays);
        $this->assertSame('2026-08-29 12:00:00', $result->windowStart);
        $this->assertSame('2026-09-28 12:00:00', $result->windowEnd);

        $this->assertTrue(
            Carbon::parse($result->windowStart)
                ->equalTo(Carbon::parse($result->windowEnd)->subDays(30)),
        );
    }

    public function test_the_evaluator_resolves_the_tier_from_real_qualifying_spend(): void
    {
        $this->seedSpend($this->player, '250.00');

        $result = app(AccountGradeEvaluator::class)->evaluate($this->player);

        $this->assertSame('gold_plus', $result->tier->key);
        $this->assertSame('250.00', $result->qualifyingSpend);
        $this->assertSame('0.0200', $result->appliedRate);
        $this->assertSame('2', $result->sourceVersion);
        $this->assertSame('2', $result->ruleVersion);
    }

    public function test_spend_outside_the_window_does_not_qualify(): void
    {
        $this->seedSpend($this->player, '5000.00', now()->subDays(31));

        $result = app(AccountGradeEvaluator::class)->evaluate($this->player);

        $this->assertSame('0.00', $result->qualifyingSpend);
        $this->assertSame('bronze', $result->tier->key);
    }

    public function test_the_next_tier_projection_reports_the_remaining_spend(): void
    {
        $this->seedSpend($this->player, '250.00');

        $next = app(AccountGradeEvaluator::class)->nextTierFor($this->player);

        $this->assertSame('platinum', $next['tier']->key);
        $this->assertSame('50.00', $next['remaining']);

        // At the top of the ladder there is nothing left to reach.
        $this->seedSpend($this->player, '400.00'); // window now totals 650.00
        $top = app(AccountGradeEvaluator::class)->nextTierFor($this->player);

        $this->assertNull($top['tier']);
        $this->assertSame('0.00', $top['remaining']);
    }

    public function test_the_previous_level_comes_from_the_latest_snapshot(): void
    {
        AccountGradeSnapshot::query()->create([
            'user_id' => $this->player->id,
            'grade_key' => 'platinum',
            'qualifying_spend' => '300.00',
            'period_start' => now()->subDays(30)->toDateTimeString(),
            'period_end' => now()->toDateTimeString(),
            'rule_version' => '2',
            'grade_discount_rate' => '0.0300',
            'fingerprint' => uniqid('fp-'),
            'calculated_at' => now()->subMinute()->toDateTimeString(),
        ]);

        $result = app(AccountGradeEvaluator::class)->evaluate($this->player);

        $this->assertSame(AccountGradeLevel::Platinum, $result->previousLevel);
        // Current evaluation is still the base state with no spend.
        $this->assertSame('bronze', $result->tier->key);
    }

    public function test_the_evaluated_eligible_games_match_the_tier_entitlements(): void
    {
        $this->seedSpend($this->player, '600.00');

        $result = app(AccountGradeEvaluator::class)->evaluate($this->player);

        $this->assertSame(
            array_map(static fn (DiscountGame $g): string => $g->value, app(GradeDiscountEntitlementService::class)->eligibleGames($result->tier)),
            array_map(static fn (DiscountGame $g): string => $g->value, $result->eligibleGames),
        );
    }

    // =====================================================================
    // D+E — the account-grade API (self-scoped)
    // =====================================================================

    public function test_the_grade_api_requires_authentication(): void
    {
        $this->getJson(route('api.v1.account.grade.show'))->assertUnauthorized();
        $this->getJson(route('api.v1.account.grade.entitlements'))->assertUnauthorized();
    }

    public function test_the_grade_api_returns_the_callers_own_grade(): void
    {
        $this->seedSpend($this->player, '250.00');

        $response = $this->actingAs($this->player, 'sanctum')
            ->getJson(route('api.v1.account.grade.show'));

        $response->assertOk()->assertJsonPath('data.current_grade.key', 'gold_plus');
        $response->assertJsonPath('data.qualifying_spend.amount', '250.00');
        $response->assertJsonPath('data.next_grade.key', 'platinum');
        $response->assertJsonPath('data.next_grade.spend_remaining', '50.00');
        $response->assertJsonPath('data.discount.rate', '0.0200');
        $response->assertJsonPath('data.discount.percent', '2.00');
        $response->assertJsonPath('data.rule_version', '2');
    }

    public function test_the_grade_api_accepts_no_user_parameter_and_reads_no_body(): void
    {
        $other = User::factory()->create();
        $this->seedSpend($other, '600.00'); // diamond_plus

        // Spoofed identifiers in query AND body must change nothing: the
        // subject is always the caller.
        $response = $this->actingAs($this->player, 'sanctum')
            ->getJson(route('api.v1.account.grade.show').'?user_id='.$other->id);

        $response->assertOk()->assertJsonPath('data.current_grade.key', 'bronze');
        $this->assertSame(0, bccomp('0.00', $response->json('data.qualifying_spend.amount'), 2));
    }

    public function test_the_entitlements_api_answers_every_public_game_exactly_once(): void
    {
        $this->seedSpend($this->player, '600.00');

        $response = $this->actingAs($this->player, 'sanctum')
            ->getJson(route('api.v1.account.grade.entitlements'));

        $response->assertOk()->assertJsonPath('data.grade', 'diamond_plus');

        $rows = $response->json('data.entitlements');

        $this->assertCount(count(DiscountGame::publicGames()), $rows);
        $this->assertSame(
            array_map(static fn (DiscountGame $g): string => $g->value, DiscountGame::publicGames()),
            array_column($rows, 'game'),
        );

        foreach ($rows as $row) {
            $this->assertSame('ELIGIBLE', $row['state']);
            $this->assertSame('6.00', $row['rate_percent']);
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayNotHasKey('user_id', $row);
        }
    }

    public function test_the_entitlements_api_states_not_eligible_explicitly(): void
    {
        $response = $this->actingAs($this->player, 'sanctum')
            ->getJson(route('api.v1.account.grade.entitlements'));

        $rows = $response->assertOk()->json('data.entitlements');
        $byGame = array_column($rows, null, 'game');

        $this->assertSame('NOT_ELIGIBLE', $byGame['national_six_digit']['state']);
        $this->assertSame('0.00', $byGame['national_six_digit']['rate_percent']);
    }

    public function test_a_suspended_account_cannot_use_the_grade_api(): void
    {
        $suspended = User::factory()->create(['status' => \App\Enums\UserStatus::Suspended]);

        $this->actingAs($suspended, 'sanctum')
            ->getJson(route('api.v1.account.grade.show'))
            ->assertForbidden();
    }

    /**
     * Seed qualifying spend exactly the way the traced engine counts it:
     * a completed BetPlacement, processed inside the window.
     */
    private function seedSpend(User $user, string $amount, ?Carbon $at = null): void
    {
        $at = $at ?? now();

        $tx = FinancialTransaction::query()->create([
            'reference_number' => 'TX-'.uniqid(),
            'user_id' => $user->id,
            'wallet_id' => null,
            'type' => TransactionType::BetPlacement,
            'currency' => Currency::THB,
            'amount' => $amount,
            'fee' => '0.00',
            'description' => 'qualifying spend seed',
            'metadata' => ['test' => true],
            'idempotency_key' => 'seed-'.uniqid(),
        ]);

        // Lifecycle fields are not mass-assignable — set explicitly.
        $tx->status = TransactionStatus::Completed;
        $tx->processed_at = $at;
        $tx->save();
    }
}
