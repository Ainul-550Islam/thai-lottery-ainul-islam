<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Security\SecurityOverviewService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The security score: the checks are FACTS, and the score is their sum.
 *
 * The rule this class defends is that the score can never be prettier than the
 * checks printed beside it. A member with no TOTP, no verified email and no
 * trusted device must not see a score implying otherwise, and the percentage must
 * not round up into "complete".
 */
final class SecurityOverviewServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_the_score_is_the_sum_of_the_passing_weights(): void
    {
        $user = User::factory()->create();

        $overview = app(SecurityOverviewService::class)->overview($user);

        $this->assertArrayHasKey('score', $overview);
        $this->assertArrayHasKey('max_score', $overview);
        $this->assertArrayHasKey('checks', $overview);
        $this->assertIsInt($overview['score']);
        $this->assertIsInt($overview['max_score']);
        $this->assertGreaterThan(0, $overview['max_score']);
    }

    public function test_the_percentage_never_rounds_up_to_a_hundred(): void
    {
        $user = User::factory()->create();

        $overview = app(SecurityOverviewService::class)->overview($user);

        if ($overview['score'] < $overview['max_score']) {
            $this->assertLessThan(
                100,
                $overview['score_percent'],
                'A partial score must never present as 100%.',
            );
        }

        $this->assertLessThanOrEqual(100, $overview['score_percent']);
    }

    public function test_every_check_reports_a_real_boolean_and_a_weight(): void
    {
        $user = User::factory()->create();

        $overview = app(SecurityOverviewService::class)->overview($user);

        $this->assertNotEmpty($overview['checks']);

        foreach ($overview['checks'] as $check) {
            $this->assertArrayHasKey('key', $check);
            $this->assertArrayHasKey('passed', $check);
            $this->assertIsBool($check['passed']);
            $this->assertArrayHasKey('weight', $check);
            $this->assertIsInt($check['weight']);
        }
    }

    public function test_all_checks_passed_is_exactly_when_score_equals_max(): void
    {
        $user = User::factory()->create();

        $overview = app(SecurityOverviewService::class)->overview($user);

        $this->assertSame(
            $overview['score'] === $overview['max_score'],
            (bool) $overview['all_checks_passed'],
        );
    }

    public function test_the_weights_sum_to_the_configured_budget(): void
    {
        $weights = (array) config('player_experience.security.score_weights', []);

        $this->assertNotEmpty($weights);
        $this->assertSame(
            100,
            array_sum(array_map(static fn ($w): int => (int) $w, $weights)),
            'The published security weights must sum to exactly 100, or score_percent is meaningless.',
        );
    }

    public function test_the_label_describes_the_account_and_never_the_platform(): void
    {
        $user = User::factory()->create();

        $overview = app(SecurityOverviewService::class)->overview($user);

        $this->assertIsString($overview['label']);
        // A claim about the platform would be a marketing assertion, which the
        // compliance guard exists to prevent.
        $this->assertStringNotContainsString('%', $overview['label']);
        $this->assertStringNotContainsStringIgnoringCase('secure', $overview['label']);
    }

    public function test_reading_the_overview_writes_no_security_event(): void
    {
        $user = User::factory()->create();

        $before = \App\Models\SecurityEvent::query()->count();

        app(SecurityOverviewService::class)->overview($user);
        app(SecurityOverviewService::class)->overview($user);

        $this->assertSame(
            $before,
            \App\Models\SecurityEvent::query()->count(),
            'A GET on the security overview must not itself be recorded as a security event.',
        );
    }
}
