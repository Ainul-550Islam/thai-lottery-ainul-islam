<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\LotteryGames\DigitDrawResult;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use App\Models\GameResultImport;
use App\Models\User;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameResultIngestionService;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * Getting an official result INTO the platform, without ever inventing one.
 *
 * WHY INGESTION IS THE MOST DANGEROUS COMPONENT IN BATCH 2
 * Every other component can only refuse. This one writes the digits that decide who wins,
 * so its defects do not look like defects: a result accepted at the wrong width, or twice,
 * or replaced after publication, produces payouts that are internally consistent and wrong.
 * The design answer is that ingestion does not publish anything at all. It STAGES a row,
 * and publication goes through Batch 1's existing confirmation boundary under four eyes.
 * This suite is the proof of that separation, and of the four properties that make it hold:
 *
 *   WIDTH    the drawn digits must be a real draw of that game (exactly `digits` long, all
 *            digits). A provider that changes its schema must fail loudly, not be accepted
 *            short and matched against the wrong end of the number.
 *   ONCE     the same payload delivered twice produces one row and no second publication.
 *            The identity is (game_code, draw_no, source) plus a payload hash.
 *   FOUR EYES  publication needs a maker seat configured AND a checker who is a different
 *            person. Without a configured maker the lane refuses in its OWN vocabulary
 *            (naming the configuration key) rather than leaking a boundary error.
 *   ONE WAY  a staged result may be published or rejected, never published twice, and a
 *            DIFFERENT payload for an already published draw is a correction that a human
 *            must review - never a quiet overwrite by whoever re-ran the import.
 *
 * WHAT IS NOT TESTED HERE
 * What the confirmation boundary does to the draws table (Batch 1's suite owns that), and
 * what settlement then pays (SettlementTest owns that). This file stops at the boundary.
 */
final class ResultIngestionTest extends GameLaneTestCase
{
    private function ingestion(): GameResultIngestionService
    {
        return app(GameResultIngestionService::class);
    }

    private function definition(string $code = 'lane_game'): \App\DTOs\LotteryGames\GameDefinition
    {
        return app(GameRegistry::class)->definition($code);
    }

    /**
     * A declared game with a draw whose number is the one the provider will report.
     *
     * @return array{draw: Draw, maker: User, checker: User}
     */
    private function stage(string $drawNo = 'LANE-20260310-2100'): array
    {
        ['draw' => $draw] = $this->openLane('lane_game');

        // A result may only be published for a draw in the RESULT PENDING state (Batch 1's
        // DrawLifecycleState::ResultPending, carried by DrawStatus::Drawing): the draw has
        // closed, the number is fixed, and settlement has not run yet. Handing a result to
        // an open draw would settle bets that can still arrive; handing one to a settled
        // draw would rewrite money.
        $draw->forceFill([
            'draw_number' => $drawNo,
            'status' => \App\Enums\DrawStatus::Drawing->value,
            'closed_at' => now()->subMinutes(10),
            'betting_close_at' => now()->subMinutes(20),
        ])->save();

        $maker = User::factory()->create();
        $checker = User::factory()->create();

        config()->set('lottery_games.ingestion.maker_user_id', (int) $maker->getKey());

        return ['draw' => $draw->fresh(), 'maker' => $maker, 'checker' => $checker];
    }

    private function feedResult(string $drawNo, string $digits, string $source = 'official'): DigitDrawResult
    {
        return new DigitDrawResult(
            gameCode: 'lane_game',
            drawNo: $drawNo,
            digits: $digits,
            drawnAt: now()->toIso8601String(),
            source: $source,
        );
    }

    // =========================================================================
    // Staging
    // =========================================================================

    public function test_a_valid_result_is_staged_and_nothing_is_published_yet(): void
    {
        $this->stage();

        $outcome = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));

        $this->assertTrue($outcome['staged']);
        $this->assertSame('staged', $outcome['status']);
        $this->assertSame('123', $outcome['digits']);
        $this->assertNotNull($outcome['import_id']);
        $this->assertNull($outcome['publication'], 'staging must not publish');

        $import = GameResultImport::query()->findOrFail($outcome['import_id']);
        $this->assertSame(GameResultImport::STATUS_STAGED, $import->status);
        $this->assertSame('lane_game', (string) $import->game_code);
        $this->assertNotNull($import->imported_at);
    }

    public function test_a_second_delivery_of_the_same_payload_creates_no_second_row(): void
    {
        $this->stage();

        $first = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));
        $second = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));

        $this->assertSame('duplicate_delivery', $second['reason']);
        $this->assertSame($first['import_id'], $second['import_id'], 'the original row is reported, not a new one');
        $this->assertSame(1, GameResultImport::query()->count());
    }

    public function test_digits_of_the_wrong_width_are_refused(): void
    {
        $this->stage();

        try {
            // The provider shortened its payload: two digits for a three-digit game.
            $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '12'));
            $this->fail('a result of the wrong width must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_result_payload_invalid', $e->errorCode());
        }

        $this->assertSame(0, GameResultImport::query()->count());
    }

    public function test_non_digit_result_digits_are_refused(): void
    {
        $this->stage();

        try {
            $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '12a'));
            $this->fail('a non-digit result must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_result_payload_invalid', $e->errorCode());
        }

        $this->assertSame(0, GameResultImport::query()->count());
    }

    // =========================================================================
    // Publication, through the Batch-1 boundary
    // =========================================================================

    public function test_publication_is_refused_when_no_maker_seat_is_configured(): void
    {
        ['checker' => $checker] = $this->stage();

        // No maker appointed: the two-person rule cannot be satisfied at all.
        config()->set('lottery_games.ingestion.maker_user_id', null);

        $staged = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));

        try {
            $this->ingestion()->publish((int) $staged['import_id'], (int) $checker->getKey());
            $this->fail('publication without a maker seat must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_configured', $e->errorCode());
            $this->assertContains('ingestion.maker_user_id', $e->context()['missing']);
        }

        $this->assertSame(
            GameResultImport::STATUS_STAGED,
            GameResultImport::query()->findOrFail($staged['import_id'])->status,
            'a refused publication leaves the import staged',
        );
    }

    public function test_a_staged_result_is_published_through_the_existing_boundary(): void
    {
        ['draw' => $draw, 'maker' => $maker, 'checker' => $checker] = $this->stage();

        $staged = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));
        $published = $this->ingestion()->publish((int) $staged['import_id'], (int) $checker->getKey(), 'checked against the feed');

        $this->assertTrue($published['published']);
        $this->assertSame((int) $draw->getKey(), $published['draw_id']);
        $this->assertArrayHasKey('ingest', $published['boundary']);
        $this->assertArrayHasKey('confirm', $published['boundary']);

        $import = GameResultImport::query()->findOrFail($staged['import_id']);
        $this->assertSame(GameResultImport::STATUS_PUBLISHED, $import->status);
        $this->assertNotNull($import->published_at);

        // The digits are the ones the provider reported, unchanged.
        $this->assertSame('123', (string) ($import->raw['digits'] ?? ''));
        $this->assertNotSame($maker->getKey(), $checker->getKey(), 'four eyes means two people');

        // The provider that produced the payload is still identifiable after publication:
        // the boundary is told the LANE, and the feed travels in the provenance reference.
        $this->assertSame('official', (string) $import->source);
    }

    public function test_publishing_twice_is_refused(): void
    {
        ['checker' => $checker] = $this->stage();

        $staged = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));

        $this->ingestion()->publish((int) $staged['import_id'], (int) $checker->getKey());

        try {
            $this->ingestion()->publish((int) $staged['import_id'], (int) $checker->getKey());
            $this->fail('a published import must not be published again');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_result_payload_invalid', $e->errorCode());
            $this->assertSame(GameResultImport::STATUS_PUBLISHED, $e->context()['status']);
        }
    }

    public function test_a_result_for_a_draw_that_does_not_exist_is_rejected_rather_than_invented(): void
    {
        ['checker' => $checker] = $this->stage();

        $staged = $this->ingestion()->stage($this->definition(), $this->feedResult('NO-SUCH-DRAW', '123'));

        try {
            $this->ingestion()->publish((int) $staged['import_id'], (int) $checker->getKey());
            $this->fail('a result for a missing draw must not be published');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_result_payload_invalid', $e->errorCode());
        }

        // The row is kept, marked rejected with the reason: an operator must be able to see
        // that the feed reported a draw this platform has no row for.
        $import = GameResultImport::query()->findOrFail($staged['import_id']);
        $this->assertSame(GameResultImport::STATUS_REJECTED, $import->status);

        // And no draw was created to receive it.
        $this->assertSame(0, Draw::query()->where('draw_number', 'NO-SUCH-DRAW')->count());
    }

    public function test_a_different_payload_for_an_already_published_draw_is_refused_as_a_correction(): void
    {
        ['checker' => $checker] = $this->stage();

        $staged = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));
        $this->ingestion()->publish((int) $staged['import_id'], (int) $checker->getKey());

        try {
            // A re-run of the import reports different digits: that is a correction, and a
            // correction is a decision a human signs, not a silent overwrite.
            $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '456'));
            $this->fail('a changed payload for a published draw must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_result_payload_invalid', $e->errorCode());
            $this->assertSame((int) $staged['import_id'], $e->context()['import_id']);
        }

        $this->assertSame(1, GameResultImport::query()->count());
        $this->assertSame(
            '123',
            (string) (GameResultImport::query()->findOrFail($staged['import_id'])->raw['digits'] ?? ''),
            'the published digits are untouched',
        );
    }

    public function test_a_corrected_payload_before_publication_reuses_the_import_slot(): void
    {
        $this->stage();

        $first = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));
        $second = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '456'));

        // ONE slot per (game, draw_no, source) - that unique key is the idempotency
        // mechanism - so a correction before publication re-uses it rather than inserting
        // a second row and colliding with the key.
        $this->assertSame($first['import_id'], $second['import_id']);
        $this->assertSame('corrected_before_publication', $second['reason']);
        $this->assertSame(1, GameResultImport::query()->count());

        $row = GameResultImport::query()->findOrFail($first['import_id']);

        $this->assertSame(GameResultImport::STATUS_STAGED, $row->status, 'the corrected result is publishable again');
        $this->assertSame('456', (string) ($row->raw['digits'] ?? ''), 'the corrected digits are the ones it holds');

        // The replaced payload hash survives in the message: the overwrite is auditable.
        $this->assertStringContainsString('replaced payload', (string) $row->message);
    }

    public function test_the_published_result_is_the_corrected_one(): void
    {
        ['checker' => $checker] = $this->stage();

        $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));
        $corrected = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '456'));

        $published = $this->ingestion()->publish((int) $corrected['import_id'], (int) $checker->getKey());

        $this->assertTrue($published['published']);
        $this->assertSame(
            '456',
            (string) (GameResultImport::query()->findOrFail($corrected['import_id'])->raw['digits'] ?? ''),
        );
    }

    // =========================================================================
    // Rejection, retention and the read model
    // =========================================================================

    public function test_a_staged_result_can_be_rejected_with_its_reason_kept(): void
    {
        $this->stage();

        $staged = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));
        $rejected = $this->ingestion()->reject((int) $staged['import_id'], 'the feed corrected itself before publication');

        $this->assertSame(GameResultImport::STATUS_REJECTED, $rejected['status']);
        $this->assertSame('the feed corrected itself before publication', $rejected['reason']);

        $import = GameResultImport::query()->findOrFail($staged['import_id']);
        $this->assertSame(GameResultImport::STATUS_REJECTED, $import->status);
        $this->assertStringContainsString('corrected itself', (string) $import->message);
    }

    public function test_the_recent_read_model_carries_the_game_and_the_digits(): void
    {
        $this->stage();

        $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));

        $recent = $this->ingestion()->recent('lane_game');

        $this->assertCount(1, $recent);
        $this->assertSame('lane_game', $recent[0]['game_code']);
        $this->assertSame('123', $recent[0]['digits']);
        $this->assertSame('LANE-20260310-2100', $recent[0]['draw_no']);
        $this->assertSame('staged', $recent[0]['status']);

        // A game with no imports is simply empty rather than an error.
        $this->assertSame([], $this->ingestion()->recent('lao_hanoi_4d'));
    }

    public function test_pruning_drops_the_payload_of_old_rows_and_keeps_their_history(): void
    {
        $this->stage();

        $old = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260310-2100', '123'));
        $fresh = $this->ingestion()->stage($this->definition(), $this->feedResult('LANE-20260311-2100', '456'));

        GameResultImport::query()->whereKey($old['import_id'])->update([
            'imported_at' => now()->subDays(120),
            'created_at' => now()->subDays(120),
        ]);

        $pruned = $this->ingestion()->prune(90);

        $this->assertSame(1, $pruned['pruned']);
        $this->assertSame(90, $pruned['retention_days']);

        // The PAYLOAD goes, the ROW stays: "a result was received for this draw, and here is
        // when" is the audit fact an operator needs; the raw body is what retention limits.
        $expired = GameResultImport::query()->findOrFail($old['import_id']);
        $this->assertNull($expired->raw);
        $this->assertSame(GameResultImport::STATUS_PRUNED, $expired->status);

        $recent = GameResultImport::query()->findOrFail($fresh['import_id']);
        $this->assertNotNull($recent->raw, 'a row inside the window keeps its payload');
        $this->assertSame(GameResultImport::STATUS_STAGED, $recent->status);
    }

    public function test_an_undeclared_game_cannot_be_ingested_at_all(): void
    {
        $this->expectException(LotteryGameException::class);

        try {
            $this->ingestion()->ingestLatest('no_such_game');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_unknown', $e->errorCode());

            throw $e;
        }
    }
}
