<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\DrawConfirmationStatus;
use App\Enums\DrawStatus;
use App\Exceptions\DrawResultException;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\User;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Lottery\GloResultImportService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * THE GLO WRITE BOUNDARY — the maker/checker separation over official results.
 *
 * ============================================================================
 * WHY THIS FILE EXISTS
 * ============================================================================
 * Two things were true of this repository before this suite existed:
 *
 *   1. `app/Services/Draw/DrawResultConfirmationService::confirm()` had NO
 *      check that the confirming operator differed from the ingesting one.
 *      Commit 544d319 added that check; commit f5cec15 ("restore and sync
 *      missing core source code from backup") deleted it.
 *
 *   2. `app/Services/Lottery/GloResultImportService::persistPayload()` wrote
 *      `draw_results` directly, with `'published_at' => now()` — so a single
 *      unattended import PUBLISHED an official result, and the confirmation
 *      service was not on the path at all.
 *
 * Together, a single operator could ingest and publish an official result with
 * no second pair of eyes, and nothing in the test suite objected — because the
 * only test covering the import asserted that the publication had happened.
 *
 * These tests are the ones that make that revert impossible to repeat silently.
 * They assert the CONTROL, not the implementation: a different operator must be
 * required, and an unattributable record must not be confirmable by anybody.
 */
class GloWriteBoundaryTest extends TestCase
{
    use DatabaseTruncation;

    private Draw $draw;

    private DrawResultIngestionService $ingestion;

    private DrawResultConfirmationService $confirmation;

    private GloResultImportService $imports;

    protected function setUp(): void
    {
        parent::setUp();

        // A draw awaiting its official numbers. DrawStatus::Drawing maps to
        // DrawLifecycleState::ResultPending, which is the ONLY state that
        // satisfies both halves of this pipeline:
        //
        //   mayIngestIn()      -> Closed | ResultPending   (ingestion)
        //   canPublishResult() -> ResultPending            (publication)
        //
        // A draw in plain `closed` can be ingested into but NOT published, so it
        // would fail the second half of every test here. ResultPending is the
        // real state a GLO result is processed in.
        $this->draw = Draw::factory()->create([
            'draw_number' => 'GLO-WRITE-BOUNDARY',
            'status' => DrawStatus::Drawing,
            'scheduled_at' => now()->subDays(1),
        ]);

        $this->ingestion = app(DrawResultIngestionService::class);
        $this->confirmation = app(DrawResultConfirmationService::class);
        $this->imports = app(GloResultImportService::class);

        config(['glo.official_source.mode' => 'fixture']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // THE CONTROL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The regression that started this. If this test ever passes vacuously by
     * being deleted, the control is gone again.
     */
    public function test_the_ingesting_operator_cannot_confirm_their_own_result(): void
    {
        $operator = User::factory()->create();

        $this->ingestion->ingest(
            (int) $this->draw->getKey(),
            ['first_prize' => '123456', 'bottom_two' => '58'],
            'operator',
            (int) $operator->getKey(),
        );

        try {
            $this->confirmation->confirm(
                (int) $this->draw->getKey(),
                (int) $operator->getKey(),
                ['first_prize' => '123456', 'bottom_two' => '58'],
            );

            $this->fail('A single operator was allowed to both ingest and confirm an official result.');
        } catch (DrawResultException $e) {
            $this->assertStringContainsString('same operator', $e->getMessage());
        }

        // And nothing was published by the attempt.
        $this->assertSame(
            0,
            DrawResult::query()->where('draw_id', $this->draw->getKey())->count(),
            'a refused confirmation must not publish anything',
        );
    }

    public function test_a_different_operator_can_confirm_and_that_publishes(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();

        $this->ingestion->ingest(
            (int) $this->draw->getKey(),
            ['first_prize' => '123456', 'bottom_two' => '58'],
            'operator',
            (int) $maker->getKey(),
        );

        $confirmed = $this->confirmation->confirm(
            (int) $this->draw->getKey(),
            (int) $checker->getKey(),
            ['first_prize' => '123456', 'bottom_two' => '58'],
        );

        $this->assertTrue($confirmed['published']);

        $result = DrawResult::query()->where('draw_id', $this->draw->getKey())->first();

        $this->assertNotNull($result);
        $this->assertSame('123456', $result->first_prize);
        $this->assertNotNull($result->published_at);
    }

    /**
     * FAIL CLOSED. An ingestion record with no attributable operator must not be
     * confirmable by anybody — otherwise "who ingested this?" has no answer and
     * the separation is unenforceable.
     */
    public function test_an_unattributable_ingestion_cannot_be_confirmed_by_anyone(): void
    {
        // No actorUserId — the same shape an unattended feed produces.
        $this->ingestion->ingest(
            (int) $this->draw->getKey(),
            ['first_prize' => '123456', 'bottom_two' => '58'],
            'glo_feed',
            null,
        );

        try {
            $this->confirmation->confirm(
                (int) $this->draw->getKey(),
                (int) User::factory()->create()->getKey(),
                ['first_prize' => '123456', 'bottom_two' => '58'],
            );

            $this->fail('An unattributable ingestion was confirmed.');
        } catch (DrawResultException $e) {
            $this->assertStringContainsString('attributable', $e->getMessage());
        }

        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // THE CO-OPERATING HALF: the importer cannot publish either
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_importer_writes_no_draw_result_and_sets_no_published_at(): void
    {
        $operator = User::factory()->create();

        $outcome = $this->imports->importForDraw($this->draw, $operator);

        $this->assertNull($outcome['draw_result']);
        $this->assertSame(
            0,
            DrawResult::query()->where('draw_id', $this->draw->getKey())->count(),
            'the importer must never write a draw_results row',
        );

        // The staged record is Pending — not Confirmed, not published.
        $stored = $this->draw->fresh()->metadata['result_ingestion'] ?? [];

        $this->assertSame(DrawConfirmationStatus::Pending->value, $stored['status'] ?? null);
        $this->assertArrayNotHasKey('confirmed_by', $stored);
        $this->assertArrayNotHasKey('draw_result_id', $stored);
    }

    /**
     * The full legitimate journey, end to end, with the separation intact:
     * import → stage → different operator confirms → published.
     */
    public function test_the_full_gated_journey_requires_two_operators(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();

        $outcome = $this->imports->importForDraw($this->draw, $maker);

        $this->assertNull($outcome['draw_result']);
        $this->assertSame('042042', $outcome['ingestion']['first_prize']);

        // The maker cannot be the checker.
        try {
            $this->confirmation->confirm(
                (int) $this->draw->getKey(),
                (int) $maker->getKey(),
                ['first_prize' => '042042', 'bottom_two' => '58'],
            );

            $this->fail('The importing operator confirmed their own import.');
        } catch (DrawResultException) {
            // expected
        }

        // The second operator can.
        $confirmed = $this->confirmation->confirm(
            (int) $this->draw->getKey(),
            (int) $checker->getKey(),
            ['first_prize' => '042042', 'bottom_two' => '58'],
        );

        $this->assertTrue($confirmed['published']);

        $result = DrawResult::query()->where('draw_id', $this->draw->getKey())->firstOrFail();

        $this->assertSame('042042', $result->first_prize);
        $this->assertNotNull($result->published_at);
    }

    /**
     * The tier lane a feed carries must reach draw_results only via the
     * confirmation act — because GloN3TicketChecker and the public tier display
     * read it, and an unconfirmed lane would be unverified data on a paying
     * surface.
     */
    public function test_the_source_lane_is_written_only_by_the_confirming_operator(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();

        $this->imports->importForDraw($this->draw, $maker);

        // Nothing on draw_results yet at all.
        $this->assertSame(0, DrawResult::query()->where('draw_id', $this->draw->getKey())->count());

        $this->confirmation->confirm(
            (int) $this->draw->getKey(),
            (int) $checker->getKey(),
            ['first_prize' => '042042', 'bottom_two' => '58'],
        );

        $result = DrawResult::query()->where('draw_id', $this->draw->getKey())->firstOrFail();
        $lane = $result->metadata['glo'] ?? [];

        // The lane is present, attributed to the provider, and stamped with the
        // operator who vouched for it.
        $this->assertSame('fixture', $lane['import_provider'] ?? null);
        $this->assertNotEmpty($lane['import_fingerprint'] ?? '');
        $this->assertSame($checker->getKey(), $lane['lane_confirmed_by'] ?? null);

        // Publication's own metadata survived the merge — MarketResultResolver
        // reads this key and dropping it would break every market lookup.
        $this->assertSame('58', $result->metadata['bottom_two'] ?? null);

        // The importer is not the one who vouched for the lane.
        $this->assertNotSame($maker->getKey(), $lane['lane_confirmed_by'] ?? null);
    }

    /**
     * A plain operator paste (no feed tiers) must not break publication, and
     * must not invent a lane.
     */
    public function test_a_lane_less_confirmation_publishes_without_a_source_lane(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();

        $this->ingestion->ingest(
            (int) $this->draw->getKey(),
            ['first_prize' => '654321', 'bottom_two' => '58'],
            'operator-cli',
            (int) $maker->getKey(),
        );

        $this->confirmation->confirm(
            (int) $this->draw->getKey(),
            (int) $checker->getKey(),
            ['first_prize' => '654321', 'bottom_two' => '58'],
        );

        $result = DrawResult::query()->where('draw_id', $this->draw->getKey())->firstOrFail();

        $this->assertSame('654321', $result->first_prize);
        $this->assertSame('58', $result->metadata['bottom_two'] ?? null);
        $this->assertArrayNotHasKey('glo', $result->metadata ?? []);
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * THE TWO-DIGIT PRIZE IS ITS OWN DRAW, AND THE PLATFORM MUST NOT INVENT IT.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * Three tests below exist because this pipeline used to derive the bottom two
     * from the first prize and then REFUSE any announcement where the two
     * disagreed. Real announcements always disagree — seven published GLO draws,
     * no agreement in any of them — so the behaviour was wrong in both
     * directions at once: it rejected genuine results, and when a payload omitted
     * the bottom two it filled the gap with a number nobody had drawn.
     */

    /**
     * A real-shaped announcement is accepted, and the two numbers stay different.
     *
     * This is the case the old code refused. 287184 with a bottom two of 48 is a
     * genuine published pair; the old canonicalizer computed 84 from the first
     * prize, compared, and threw `malformed` with "the announcement cannot say
     * both". It can, it did, and the platform could not ingest it.
     */
    public function test_an_independent_bottom_two_is_accepted_and_stored_verbatim(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();

        $this->ingestion->ingest(
            (int) $this->draw->getKey(),
            ['first_prize' => '287184', 'bottom_two' => '48'],
            'operator-cli',
            (int) $maker->getKey(),
        );

        // Staged, not published: the write boundary is unaffected by any of this.
        // Asserted on the shape currentIngestion() actually returns — the flat
        // result row — rather than a nested `payload`, which is how this test
        // would have passed while reading nothing.
        $stored = $this->ingestion->currentIngestion((int) $this->draw->getKey());

        $this->assertIsArray($stored);
        $this->assertSame('287184', $stored['first_prize']);
        $this->assertSame('48', $stored['bottom_two'], 'the announced value, not substr(287184, -2) = 84');
        $this->assertSame(DrawConfirmationStatus::Pending->value, $stored['status']);

        $this->confirmation->confirm(
            (int) $this->draw->getKey(),
            (int) $checker->getKey(),
            ['first_prize' => '287184', 'bottom_two' => '48'],
        );

        $result = DrawResult::query()->where('draw_id', $this->draw->getKey())->firstOrFail();

        $this->assertSame('287184', $result->first_prize);
        $this->assertSame('48', $result->metadata['bottom_two'] ?? null);
        $this->assertNotSame('84', $result->metadata['bottom_two'] ?? null, 'the derived value must never be written');
    }

    /**
     * An announcement with no bottom two is REFUSED, not completed by guesswork.
     *
     * The old code accepted this and wrote `substr($firstPrize, -2)` as if the GLO
     * had announced it. That invented number is what the two-digit market would
     * have settled against. At roughly 10,000 winning slips per draw at 2,000
     * THB, it is a twenty-million-baht market decided by a substring call.
     */
    public function test_a_payload_without_a_bottom_two_is_refused_rather_than_derived(): void
    {
        $maker = User::factory()->create();

        try {
            $this->ingestion->ingest(
                (int) $this->draw->getKey(),
                ['first_prize' => '287184'],
                'operator-cli',
                (int) $maker->getKey(),
            );

            $this->fail('A payload with no bottom_two was ingested: the platform invented a winning number.');
        } catch (DrawResultException $e) {
            // The refusal must explain WHY, because an operator who is told only
            // "malformed" will retry the same payload forever.
            $this->assertStringContainsString('separately drawn number', $e->getMessage());
            $this->assertStringContainsString('287184', $e->getMessage());
        }

        // Nothing was staged, so there is nothing for a second operator to confirm.
        $this->assertSame(
            0,
            DrawResult::query()->where('draw_id', $this->draw->getKey())->count(),
            'a refused ingestion must not publish anything',
        );
    }

    /**
     * The CONFIRMING operator must state both numbers they are attesting.
     *
     * The old confirmation path filled a missing bottom_two in from the claimed
     * first prize and then compared it against the stored value, which passed —
     * so a checker who never looked at the two-digit prize produced a signed
     * attestation of it. That is not a second pair of eyes; it is a system
     * agreeing with itself in front of a witness.
     */
    public function test_a_confirmation_that_does_not_state_the_bottom_two_is_refused(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();

        $this->ingestion->ingest(
            (int) $this->draw->getKey(),
            ['first_prize' => '287184', 'bottom_two' => '48'],
            'operator-cli',
            (int) $maker->getKey(),
        );

        try {
            $this->confirmation->confirm(
                (int) $this->draw->getKey(),
                (int) $checker->getKey(),
                ['first_prize' => '287184'],
            );

            $this->fail('A checker confirmed a result without stating the two-digit prize.');
        } catch (DrawResultException $e) {
            $this->assertStringContainsString('did not state bottom_two', $e->getMessage());
        }

        $this->assertSame(
            0,
            DrawResult::query()->where('draw_id', $this->draw->getKey())->count(),
            'an incomplete attestation must not publish anything',
        );
    }
}
