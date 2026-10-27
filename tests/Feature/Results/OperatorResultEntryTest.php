<?php

declare(strict_types=1);

namespace Tests\Feature\Results;

use App\Enums\ResultVersionState;
use App\Models\User;
use App\Models\WeeklyLotteryResultVersion;
use App\Services\Lottery\OperatorResultEntryService;
use App\Services\Lottery\WeeklyLotteryImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The manual result path, asserted from the layer the Filament page calls.
 *
 * ============================================================================
 * WHAT THIS SUITE IS FOR
 * ============================================================================
 * A manual entry is the one way a lottery result can reach the public surface
 * without a provider having said it. That makes the two-person rule the whole
 * control, and the control lives in the approval gate, not in the UI: a Filament
 * action can be re-pointed, a policy can be mis-registered, but `approveVersion()`
 * is the last thing between a number and a payout.
 *
 * So every test here is a REFUSAL, and each one is a distinct way a single
 * operator could otherwise publish alone:
 *
 *   - they approve their own entry;
 *   - they approve with a blank reason, leaving no record of the judgement;
 *   - they enter into a lane whose engine cannot complete the approval;
 *   - they name a lane that does not exist and hope for a default.
 *
 * Nothing here writes a draw result. The refusals all resolve before the engine
 * opens its transaction, which is exactly why they can be asserted against a
 * version that was never persisted.
 */
final class OperatorResultEntryTest extends TestCase
{
    use RefreshDatabase;

    private OperatorResultEntryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(OperatorResultEntryService::class);
    }

    /**
     * An unpersisted version: enough for every pre-transaction refusal.
     */
    private function version(int $importerId, string $provider = 'internal', int $number = 1): WeeklyLotteryResultVersion
    {
        $version = new WeeklyLotteryResultVersion;
        $version->forceFill([
            'draw_id' => 1,
            'version_number' => $number,
            'state' => ResultVersionState::Verified,
            'provider' => $provider,
            'source_state' => 'INTERNAL_RECONCILED',
            'imported_by' => $importerId,
        ]);

        return $version;
    }

    private function weekly(): WeeklyLotteryImportService
    {
        return $this->app->make(WeeklyLotteryImportService::class);
    }

    public function test_the_four_sourced_lanes_are_the_only_ones_offered(): void
    {
        $this->assertSame(
            ['national', 'weekly', 'bingo', 'pcso'],
            array_keys($this->service->lanes()),
        );
    }

    public function test_a_manual_entry_never_claims_an_official_provider_label(): void
    {
        foreach (array_keys($this->service->lanes()) as $lane) {
            $label = $this->service->providerLabel($lane);

            $this->assertNotSame('official', $label, "lane {$lane} must not label a human entry as an official source");
            $this->assertNotSame('fixture', $label, "lane {$lane} must not label a human entry as a test vector");
            $this->assertNotSame('', trim($label));
        }
    }

    public function test_an_unknown_lane_is_refused_rather_than_defaulted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->providerLabel('scratchcard');
    }

    public function test_the_importer_cannot_approve_their_own_entry(): void
    {
        $outcome = $this->weekly()->approveVersion(
            $this->version(importerId: 41),
            approverId: 41,
            reason: 'Second pair of eyes, honestly.',
        );

        $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertContains('APPROVER_IS_IMPORTER', $outcome['errors']);
        $this->assertFalse((bool) $outcome['published']);
    }

    public function test_a_second_person_is_required_to_be_distinguishable_from_the_first(): void
    {
        $outcome = $this->weekly()->approveVersion(
            $this->version(importerId: 0) ,
            approverId: 0,
            reason: 'Same seat, same session.',
        );

        $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertContains('APPROVER_IS_IMPORTER', $outcome['errors']);
    }

    public function test_a_version_with_no_recorded_importer_cannot_be_approved(): void
    {
        $version = $this->version(importerId: 1);
        $version->forceFill(['imported_by' => null]);

        $outcome = $this->weekly()->approveVersion($version, approverId: 2, reason: 'A reason long enough to keep.');

        $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertContains('IMPORTER_UNKNOWN', $outcome['errors']);
    }

    public function test_a_blank_approval_reason_is_refused(): void
    {
        $outcome = $this->weekly()->approveVersion(
            $this->version(importerId: 5),
            approverId: 6,
            reason: "   \n\t  ",
        );

        $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertContains('APPROVAL_REASON_REQUIRED', $outcome['errors']);
    }

    public function test_a_version_that_is_not_verified_cannot_be_approved(): void
    {
        $version = $this->version(importerId: 5);
        $version->forceFill(['state' => ResultVersionState::Conflict]);

        $outcome = $this->weekly()->approveVersion($version, approverId: 6, reason: 'Resolve the conflict first.');

        $this->assertSame(WeeklyLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertContains('CONFLICT_VERSION_REQUIRES_RESOLUTION', $outcome['errors']);
    }

    public function test_a_lane_whose_engine_cannot_complete_an_approval_is_reported_rather_than_offered(): void
    {
        $lanes = $this->service->lanes();

        $this->assertFalse($lanes['national']['available']);
        $this->assertSame('approval_engine_unsupported', $lanes['national']['reason']);
        $this->assertFalse($this->service->isPublishable('national'));
    }

    public function test_submitting_to_a_lane_without_an_approval_engine_is_refused_before_any_write(): void
    {
        $actor = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->service->submit('national', ['draw_date' => '2026-09-16'], $actor);
    }

    public function test_the_operator_provider_must_be_in_the_lane_ladder_for_the_lane_to_be_publishable(): void
    {
        // The label is removed from the ladder: an entry could be stored but
        // could never publish, so the service must say so rather than collect it.
        config(['weekly_lottery.sources.priority' => ['official', 'fixture']]);

        $lanes = $this->service->lanes();

        $this->assertFalse($lanes['weekly']['available']);
        $this->assertSame('provider_not_in_lane_priority', $lanes['weekly']['reason']);
        $this->assertFalse($this->service->isPublishable('weekly'));
    }
}
