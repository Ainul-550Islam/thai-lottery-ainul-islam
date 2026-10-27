<?php

declare(strict_types=1);

namespace Tests\Unit\Account;

use App\Services\Account\GradeTierCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every load-time guard of the account-grade catalogue, pinned by its message.
 *
 * The grade ladder decides what percentage of a stake is discounted, so a malformed
 * operator configuration is a money defect, not a cosmetic one: a tier list that
 * silently loaded "wrong" would change what players pay. The application's answer is
 * to refuse to load at all - thirteen InvalidArgumentException throws in
 * GradeTierCatalog::loadTiers() - and each one says what it found.
 *
 * Before this file, three of those guards had a test, and all three asserted only the
 * exception class. `expectException(InvalidArgumentException::class)` is satisfied by
 * ANY of the thirteen throws, so deleting the threshold-ordering guard and keeping the
 * duplicate-key guard would have left all three tests green: the tests proved the
 * catalogue throws, never that the check they named is what threw. Every test below
 * pins the message for exactly that reason.
 *
 * One guard is deliberately NOT tested: baseTier()'s "Grade catalogue has no base
 * tier." line, which its own comment calls unreachable because loadTiers() guarantees
 * exactly one base tier. A test that cannot fail is worse than no test, so the
 * unreachability is left documented where it is written rather than dressed up as
 * coverage.
 *
 * Config is rebuilt from the shipped tiers in every test rather than invented, so a
 * guard is exercised against a catalogue that is otherwise valid. If a future change
 * adds a required key, these tests start failing loudly on the unrelated guards too -
 * which is the correct outcome for a fixture that no longer matches the contract.
 */
final class GradeTierCatalogGuardTest extends TestCase
{
    /**
     * The shipped catalogue, indexable and safe to mutate one field at a time.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return array_values((array) config('account_grades.tiers'));
    }

    /**
     * @param  array<string, mixed>  $override
     * @return list<array<string, mixed>>
     */
    private function mutate(int $index, array $override): array
    {
        $rows = $this->rows();
        $rows[$index] = array_merge($rows[$index], $override);

        return $rows;
    }

    private function load(): void
    {
        // A fresh instance every time: the catalogue caches its parsed tiers, so a
        // singleton would hand the next test the previous test's answer.
        (new GradeTierCatalog)->tiers();
    }

    /**
     * Assert the catalogue refuses to load, and refuses with the message that names the
     * broken thing. The message is the contract: it is what an operator reads.
     */
    private function assertRefusedWith(string $message): void
    {
        try {
            $this->load();
        } catch (InvalidArgumentException $e) {
            $this->assertSame(
                $message,
                $e->getMessage(),
                'The catalogue threw, but not for the reason this test is about. Because '
                .'every guard raises the same exception class, an unpinned message would '
                .'let one guard stand in for another and the check under test could be '
                .'deleted without any test noticing.'
            );

            return;
        }

        $this->fail(sprintf(
            'The catalogue loaded a configuration it must refuse (%s). A silent load here '
            .'is a wrong discount applied to real stakes.',
            $message
        ));
    }

    #[Test]
    public function the_shipped_catalogue_loads_and_every_rate_is_a_valid_fraction(): void
    {
        $tiers = (new GradeTierCatalog)->tiers();

        $this->assertNotSame([], $tiers, 'The shipped catalogue must load in the first place.');
        $this->assertCount(
            count($this->rows()),
            $tiers,
            'Every configured tier must survive the load; a dropped tier would silently '
            .'remove a discount level from the programme.'
        );

        // The invariant the regex and bccomp bound in loadTiers() exists to hold: the
        // stored rate is a FRACTION on 0..1 with four decimals, never a percentage.
        foreach ($tiers as $tier) {
            $this->assertGreaterThanOrEqual(0, bccomp($tier->discountRate, '0.0000', 4));
            $this->assertLessThanOrEqual(
                0,
                bccomp($tier->discountRate, '1.0000', 4),
                sprintf(
                    'Tier %s carries discount_rate %s. A value above 1.0000 means the '
                    .'operator wrote a percentage (15 for 15%%) where the engine expects a '
                    .'fraction (0.1500) - a hundred-fold discount error.',
                    $tier->key,
                    $tier->discountRate
                )
            );
            $this->assertMatchesRegularExpression(
                '/^\d+\.\d{4}$/',
                bcadd($tier->discountRate, '0', 4),
                'Rates are normalised to four decimals, so the stored, applied and displayed '
                .'number are the same number.'
            );
        }
    }

    #[Test]
    public function a_rate_written_as_a_percentage_instead_of_a_fraction_is_refused(): void
    {
        // The specific operator error the bound protects against: 15 meaning fifteen per
        // cent, where the engine would read fifteen hundred per cent.
        config(['account_grades.tiers' => $this->mutate(1, ['discount_rate' => '15.0000'])]);

        $this->assertRefusedWith('Tier gold_plus has an invalid discount_rate fraction.');
    }

    #[Test]
    public function a_rate_above_one_is_refused_and_one_exactly_is_allowed(): void
    {
        config(['account_grades.tiers' => $this->mutate(1, ['discount_rate' => '1.5000'])]);
        $this->assertRefusedWith('Tier gold_plus has an invalid discount_rate fraction.');

        // The boundary belongs to the programme: a hundred per cent discount is legal
        // arithmetic, so the bound must be > 1.0000 and not >= - pinning it here keeps a
        // future "tightening" from quietly making a full discount unconfigurable.
        config(['account_grades.tiers' => $this->mutate(1, ['discount_rate' => '1.0000'])]);
        $tiers = (new GradeTierCatalog)->tiers();

        foreach ($tiers as $tier) {
            if ($tier->key === 'gold_plus') {
                $this->assertSame('1.0000', $tier->discountRate);
            }
        }
    }

    #[Test]
    public function a_negative_rate_is_refused_before_it_can_become_a_price_increase(): void
    {
        // The shape regex is the first line of defence here: '-0.0200' cannot reach bccomp,
        // because a negative sign is not a digit. What matters is that a negative rate -
        // which would turn a discount into a surcharge - is impossible to configure.
        foreach (['-0.0200', '+0.0200', '0.0200 ', ' 0.0200', '1e-2', '0.02%'] as $shape) {
            config(['account_grades.tiers' => $this->mutate(1, ['discount_rate' => $shape])]);

            $this->assertRefusedWith('Tier gold_plus has an invalid discount_rate fraction.');
        }
    }

    #[Test]
    public function a_threshold_that_is_not_two_decimals_is_refused(): void
    {
        config(['account_grades.tiers' => $this->mutate(1, ['min_spend' => '200.000'])]);

        $this->assertRefusedWith('Tier gold_plus has an invalid min_spend.');
    }

    #[Test]
    public function a_negative_threshold_is_refused(): void
    {
        config(['account_grades.tiers' => $this->mutate(1, ['min_spend' => '-200.00'])]);

        $this->assertRefusedWith('Tier gold_plus has an invalid min_spend.');
    }

    #[Test]
    public function an_empty_catalogue_is_refused(): void
    {
        config(['account_grades.tiers' => []]);

        $this->assertRefusedWith('The grade catalogue must define at least one tier.');
    }

    #[Test]
    public function a_tier_that_is_not_an_array_is_refused(): void
    {
        $rows = $this->rows();
        $rows[] = 'not-a-tier';

        config(['account_grades.tiers' => $rows]);

        $this->assertRefusedWith('Every account grade tier must be an array.');
    }

    #[Test]
    public function an_unknown_tier_key_is_refused_and_named_in_the_message(): void
    {
        config(['account_grades.tiers' => $this->mutate(1, ['key' => 'mystery'])]);

        // The message carries the offending key, because that is what a deployment
        // operator needs in order to fix a YAML or env-authored catalogue.
        $this->assertRefusedWith('Unknown account grade tier key: mystery');
    }

    #[Test]
    public function a_duplicate_tier_key_is_refused(): void
    {
        $rows = $this->rows();
        $rows[] = $rows[1];

        config(['account_grades.tiers' => $rows]);

        $this->assertRefusedWith('Duplicate account grade tier key: gold_plus');
    }

    #[Test]
    public function an_unknown_entitled_game_is_named_in_the_message(): void
    {
        config(['account_grades.tiers' => $this->mutate(1, ['eligible_games' => ['not_a_real_game']])]);

        $this->assertRefusedWith('Tier gold_plus entitles an unknown game: not_a_real_game');
    }

    #[Test]
    public function the_base_tier_must_start_at_exactly_zero(): void
    {
        config(['account_grades.tiers' => $this->mutate(0, ['min_spend' => '10.00'])]);

        $this->assertRefusedWith('The base tier threshold must be exactly 0.00.');
    }

    #[Test]
    public function the_base_state_cannot_entitle_any_game(): void
    {
        config(['account_grades.tiers' => $this->mutate(0, ['eligible_games' => ['national_six_digit']])]);

        $this->assertRefusedWith('The base/no-grade state cannot entitle any game.');
    }

    #[Test]
    public function a_public_tier_must_have_a_series_level_of_at_least_one(): void
    {
        config(['account_grades.tiers' => $this->mutate(1, ['sl' => 0])]);

        $this->assertRefusedWith('Public tier gold_plus must have SL >= 1.');
    }

    #[Test]
    public function public_series_levels_must_strictly_increase(): void
    {
        // Two tiers at the same SL make "highest wins" ambiguous, which is a decision
        // about what discount a player receives - not a display detail.
        config(['account_grades.tiers' => $this->mutate(2, ['sl' => 1])]);

        $this->assertRefusedWith('Public tier SL values must strictly increase.');
    }

    #[Test]
    public function public_thresholds_must_strictly_increase_and_say_which_pair_overlaps(): void
    {
        config(['account_grades.tiers' => $this->mutate(2, ['min_spend' => '100.00'])]);

        $this->assertRefusedWith(
            'Public tier thresholds must strictly increase — platinum overlaps gold_plus.'
        );
    }

    #[Test]
    public function a_catalogue_without_a_base_tier_is_refused(): void
    {
        // Note which guard this really is: the loop finishes and the count check fires.
        // baseTier() has its own "no base tier" line, documented as unreachable, and this
        // test is why it stays unreachable.
        $rows = $this->rows();
        array_shift($rows);

        config(['account_grades.tiers' => $rows]);

        $this->assertRefusedWith('The catalogue must contain exactly one base tier.');
    }

    #[Test]
    public function a_second_base_tier_is_refused_by_the_duplicate_key_guard(): void
    {
        // Measured, not assumed: appending a second bronze row never reaches the
        // base-count check, because the duplicate-key guard fires first. That makes
        // $baseCount > 1 unreachable through configuration - two rows cannot both be the
        // base tier without sharing the key that identifies it - so the exactly-one check
        // is only ever reached from the other side, with a base tier missing. The
        // shipped Feature test for this scenario asserts only the exception class, which
        // is exactly the looseness this file exists to remove.
        $rows = $this->rows();
        $rows[] = $rows[0];

        config(['account_grades.tiers' => $rows]);

        $this->assertRefusedWith('Duplicate account grade tier key: bronze');
    }

    #[Test]
    public function the_guard_set_is_complete_against_the_loader(): void
    {
        // A count pin, in the same spirit as the >= 2 floor in the scheduler tests: if
        // guards are deleted from loadTiers(), the number of distinct refusal messages
        // reachable in this file falls, and this assertion says so out loud instead of
        // quietly passing with fewer tests. 13 is the count of messages asserted above
        // (the fourteenth throw is documented unreachable and is not exercised).
        $distinct = [
            'The grade catalogue must define at least one tier.',
            'Every account grade tier must be an array.',
            'Unknown account grade tier key: mystery',
            'Duplicate account grade tier key: gold_plus',
            'Tier gold_plus has an invalid min_spend.',
            'Tier gold_plus has an invalid discount_rate fraction.',
            'Tier gold_plus entitles an unknown game: not_a_real_game',
            'The base tier threshold must be exactly 0.00.',
            'The base/no-grade state cannot entitle any game.',
            'Public tier gold_plus must have SL >= 1.',
            'Public tier SL values must strictly increase.',
            'Public tier thresholds must strictly increase — platinum overlaps gold_plus.',
            'The catalogue must contain exactly one base tier.',
        ];

        $this->assertCount(13, array_unique($distinct));

        $source = (string) file_get_contents(app_path('Services/Account/GradeTierCatalog.php'));
        $thrown = preg_match_all('/throw new InvalidArgumentException\(/', $source);

        $this->assertSame(
            14,
            $thrown,
            sprintf(
                'The loader has %d refusal sites but this file pins 13 of them plus one '
                .'documented-as-unreachable. If a guard was added, pin its message too; if '
                .'one was removed, delete its test rather than leaving a vacuous pass.',
                $thrown
            )
        );
    }
}
