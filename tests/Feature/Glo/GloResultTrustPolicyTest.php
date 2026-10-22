<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\User;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Lottery\GloResultTrustPolicy;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Feature\Glo\Concerns\CreatesTrustedGloResult;
use Tests\TestCase;

/**
 * Proves the fail-closed rule for player-facing result publication: only an
 * official, four-eyes, untampered, published result is broadcastable.
 */
class GloResultTrustPolicyTest extends TestCase
{
    use CreatesTrustedGloResult;
    use DatabaseTruncation;

    private GloResultTrustPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = app(GloResultTrustPolicy::class);
    }

    private function makeDraw(): Draw
    {
        return Draw::factory()->create([
            'draw_number' => 'GLO-TRUST-'.strtoupper(bin2hex(random_bytes(3))),
            'status' => DrawStatus::Drawing,
            'scheduled_at' => now()->subDay(),
        ]);
    }

    /**
     * Create a published result row whose draw metadata carries the given
     * trust metadata.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function makeResult(Draw $draw, string $firstPrize, array $metadata): DrawResult
    {
        $result = new DrawResult();
        $result->forceFill([
            'draw_id' => $draw->id,
            'first_prize' => $firstPrize,
            'second_prize' => [],
            'third_prize' => [],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 0,
            'total_payout' => '0.00',
            'metadata' => $metadata,
            'published_at' => now(),
        ])->save();

        return $result;
    }

    public function test_official_four_eyes_result_is_broadcastable(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $draw = $this->makeDraw();

        $metadata = $this->trustedFixtureResultMetadata(
            $draw, $maker, $checker, '123456', '78',
            provider: 'official', mode: 'official',
        );
        $result = $this->makeResult($draw, '123456', $metadata);

        $this->assertTrue($this->policy->isBroadcastable($result, $draw->fresh()));

        $publication = $this->policy->trustedPublication($result, $draw->fresh());
        $this->assertNotNull($publication);
        $this->assertSame('123456', $publication['result']->first_prize);
    }

    public function test_fixture_provenance_is_never_broadcastable(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $draw = $this->makeDraw();

        $metadata = $this->trustedFixtureResultMetadata($draw, $maker, $checker, '123456', '78');
        $result = $this->makeResult($draw, '123456', $metadata);

        $this->assertFalse($this->policy->isBroadcastable($result, $draw->fresh()));
        $this->assertNull($this->policy->trustedPublication($result, $draw->fresh()));
    }

    public function test_same_maker_and_checker_is_refused(): void
    {
        $operator = User::factory()->create();
        $draw = $this->makeDraw();

        $metadata = $this->trustedFixtureResultMetadata(
            $draw, $operator, $operator, '123456', '78',
            provider: 'official', mode: 'official',
        );
        $result = $this->makeResult($draw, '123456', $metadata);

        $this->assertFalse($this->policy->isBroadcastable($result, $draw->fresh()));
    }

    public function test_tampered_payload_after_confirmation_is_refused(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $draw = $this->makeDraw();

        $metadata = $this->trustedFixtureResultMetadata(
            $draw, $maker, $checker, '123456', '78',
            provider: 'official', mode: 'official',
        );
        $result = $this->makeResult($draw, '123456', $metadata);

        // Edit the confirmed payload directly, leaving the fingerprint as it was.
        $drawMetadata = $draw->fresh()->metadata;
        $drawMetadata[DrawResultIngestionService::METADATA_KEY]['payload']['first_prize'] = '999999';
        $draw->fresh()->forceFill(['metadata' => $drawMetadata])->save();

        $this->assertFalse($this->policy->isBroadcastable($result, $draw->fresh()));
    }

    public function test_unpublished_draw_is_refused(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $draw = $this->makeDraw();

        $metadata = $this->trustedFixtureResultMetadata(
            $draw, $maker, $checker, '123456', '78',
            provider: 'official', mode: 'official',
        );
        $result = $this->makeResult($draw, '123456', $metadata);

        $draw->fresh()->forceFill(['result_published_at' => null])->save();

        $this->assertFalse($this->policy->isBroadcastable($result, $draw->fresh()));
    }
}
