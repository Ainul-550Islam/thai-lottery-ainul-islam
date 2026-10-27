<?php

declare(strict_types=1);

namespace Tests\Feature\Hardening;

use App\Enums\ResultVersionState;
use App\Models\WeeklyLotteryResultVersion;
use App\Services\Lottery\OperatorResultEntryService;
use App\Services\Lottery\Support\AbstractLotteryImportService;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The manual result path: which lanes may use it, and what the approval gate
 * refuses before it ever touches a row.
 *
 * The refusals below are the whole control. Each one is a distinct way an
 * operator could otherwise publish a number alone, so each gets its own test
 * rather than one happy-path test with a counter.
 */
final class OperatorResultEntryTest extends TestCase
{
    private OperatorResultEntryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(OperatorResultEntryService::class);
    }

    /**
     * A version model that is NOT persisted: every refusal asserted here happens
     * before the engine opens a transaction, which is exactly why it can be
     * tested without a draw, a payload or a database fixture.
     */
    private function version(int $importerId, string $provider = 'internal', int $number = 1): WeeklyLotteryResultVersion
    {
        $version = new WeeklyLotteryResultVersion();
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

    private function engine(): AbstractLotteryImportService
    {
        return $this->app->make(\App\Services\Lottery\WeeklyLotteryImportService::class);
    }

    public function test_every_supported_lane_is_reported_with_its_provider_label(): void
    {
        $lanes = $this->service->lanes();

        $this->assertSame(['national', 'weekly', 'bingo', 'pcso'], array_keys($lanes));

        foreach ($lanes as $lane => $state) {
            $this->assertSame('internal', $state['provider'], "lane {$lane} must claim the internal label, never official");
            $this->assertNotSame('official', $state['provider']);
        }
    }

    public function test_a_lane_is_available_only_when_its_provider_is_in_the_priority_list(): void
    {
        // The operator label is removed from the ladder: the entry could be
        // stored but could never publish, so the service must say so up front.
        Config::set('weekly_lottery.sources.priority', ['official', 'fixture']);

        $lanes = $this->service->lanes();

        $this->assertFalse($lanes['weekly']['available']);
        $this->assertSame('provider_not_in_lane_priority', $lanes['weekly']['reason']);
        $this->assertFalse($this->service->isPublishable('weekly'));
    }

    /**
     * THE NATIONAL LANE'S ENGINE DOES NOT IMPLEMENT APPROVAL.
     *
     * It is a standalone importer, not a subclass of the shared engine, so it has
     * no `approveVersion()`. The service must report that rather than offer a
     * lane whose approval would raise a TypeError inside a Filament action.
     */
    public function test_a_lane_whose_engine_cannot_approve_is_reported_as_unavailable(): void
    {
        $lanes = $this->service->lanes();

        $this->assertFalse($lanes['national']['available']);
        $this->assertSame('approval_engine_unsupported', $lanes['national']['reason']);
        $this->assertNull($this->service->capableEngine('national'));
        $this->assertFalse($this->service->isPublishable('national'));
    }

    public function test_submitting_to_a_lane_without_an_approval_engine_is_refused(): void
    {
        $actor = \App\Models\User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement two-person approval');

        $this->service->submit('national', ['draw_date' => '2026-09-16'], $actor);
    }

    public function test_an_unknown_lane_is_refused_rather_than_defaulted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->providerLabel('scratchcard');
    }

    public function test_a_blank_approval_reason_is_refused(): void
    {
        $outcome = $this->engine()->approveVersion($this->version(7), 8, '   ');

        $this->assertSame(AbstractLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertContains('APPROVAL_REASON_REQUIRED', $outcome['errors']);
        $this->assertFalse($outcome['published']);
    }

    public function test_the_importer_cannot_approve_their_own_entry(): void
    {
        $outcome = $this->engine()->approveVersion($this->version(7), 7, 'Checked against the printed result sheet.');

        $this->assertSame(AbstractLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertContains('APPROVER_IS_IMPORTER', $outcome['errors']);
        $this->assertFalse($outcome['published']);
    }

    public function test_a_version_with_no_recorded_importer_cannot_be_approved(): void
    {
        $version = $this->version(0);
        $version->forceFill(['imported_by' => null]);

        $outcome = $this->engine()->approveVersion($version, 8, 'Checked against the printed result sheet.');

        $this->assertContains('IMPORTER_UNKNOWN', $outcome['errors']);
        $this->assertFalse($outcome['published']);
    }

    public function test_a_reason_below_the_configured_floor_is_refused_for_operator_entries(): void
    {
        Config::set('weekly_lottery.sources.operator.min_reason_length', 10);

        $outcome = $this->engine()->approveVersion($this->version(7), 8, 'ok');

        $this->assertSame(AbstractLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertContains('APPROVAL_REASON_TOO_SHORT', $outcome['errors']);
        $this->assertFalse($outcome['published']);
    }

    public function test_an_unverified_version_cannot_be_approved(): void
    {
        $version = $this->version(7);
        $version->forceFill(['state' => ResultVersionState::Pending]);

        $outcome = $this->engine()->approveVersion($version, 8, 'Checked against the printed result sheet.');

        $this->assertSame(AbstractLotteryImportService::STATUS_REJECTED, $outcome['status']);
        $this->assertContains('VERSION_NOT_APPROVABLE', $outcome['errors']);
        $this->assertFalse($outcome['published']);
    }

    public function test_a_conflicting_version_is_refused_with_a_resolution_hint(): void
    {
        $version = $this->version(7);
        $version->forceFill(['state' => ResultVersionState::Conflict]);

        $outcome = $this->engine()->approveVersion($version, 8, 'Checked against the printed result sheet.');

        $this->assertContains('CONFLICT_VERSION_REQUIRES_RESOLUTION', $outcome['errors']);
        $this->assertFalse($outcome['published']);
    }
}
