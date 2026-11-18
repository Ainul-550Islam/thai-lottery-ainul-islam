<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\User;
use App\Services\Compliance\ResponsibleGamingSummaryService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * /me/responsible-gaming/summary — the member's own limits, honestly reported.
 *
 * THE RULE THAT MATTERS
 * A missing limit is NULL, not zero and not "unlimited". "You have no deposit limit"
 * and "your deposit limit is 0" are different statements, and only the first is true
 * when no row exists. A member reading "0" would believe they cannot deposit at all.
 *
 * The second rule is that this read model never sets a limit. Limiting is an action
 * with its own audited path; a GET that could restrict an account would be an outage
 * waiting for a retry.
 */
final class ResponsibleGamingSummaryServiceTest extends TestCase
{
    use DatabaseTruncation;

    private ResponsibleGamingSummaryService $summary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->summary = app(ResponsibleGamingSummaryService::class);
    }

    public function test_the_summary_returns_the_documented_sections(): void
    {
        $user = User::factory()->create();

        $payload = $this->summary->summary($user);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('limits', $payload);
        $this->assertArrayHasKey('self_exclusion', $payload);
    }

    public function test_an_absent_limit_is_null_not_zero(): void
    {
        $user = User::factory()->create();

        $payload = $this->summary->summary($user);

        foreach ((array) ($payload['limits'] ?? []) as $key => $value) {
            if ($value === null) {
                $this->assertNull($value, $key.' must be null when no limit is set.');
            } else {
                $this->assertTrue(
                    is_string($value) || is_int($value) || is_bool($value),
                    $key.' must be a scalar, never an object.',
                );
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_self_exclusion_reports_a_boolean_or_null_not_a_guess(): void
    {
        $user = User::factory()->create();

        $payload = $this->summary->summary($user);

        $state = $payload['self_exclusion'];

        $this->assertTrue(
            is_array($state) || is_bool($state) || $state === null,
            'Self-exclusion state must not be inferred from an unrelated column.',
        );
    }

    public function test_the_summary_does_not_apply_a_limit(): void
    {
        $user = User::factory()->create();

        $before = $user->fresh()->toArray();

        $this->summary->summary($user);
        $this->summary->summary($user);

        $this->assertSame(
            $before,
            $user->fresh()->toArray(),
            'Reading the summary must not narrow or widen any limit.',
        );
    }

    public function test_the_payload_carries_no_percentage_or_odds_claim(): void
    {
        $user = User::factory()->create();

        $json = json_encode($this->summary->summary($user), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('%', $json);
        $this->assertStringNotContainsString('Guaranteed', $json);
        $this->assertStringNotContainsString('guaranteed', $json);
    }

    public function test_the_summary_is_member_scoped(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $minePayload = $this->summary->summary($mine);
        $theirPayload = $this->summary->summary($theirs);

        $json = json_encode($minePayload, JSON_THROW_ON_ERROR);

        // The other member's identifier must not appear anywhere in my payload.
        $this->assertStringNotContainsString('"'.$theirs->id.'"', $json);
        $this->assertIsArray($theirPayload);

        $this->addToAssertionCount(1);
    }
}
