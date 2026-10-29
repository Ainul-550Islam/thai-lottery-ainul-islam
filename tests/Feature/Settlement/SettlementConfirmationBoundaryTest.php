<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\DrawConfirmationStatus;
use App\Enums\DrawLifecycleState;
use App\Exceptions\DrawResultException;
use App\Models\AuditLog;
use App\Models\DrawResult;
use App\Models\User;
use App\Models\WinningNumber;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use RuntimeException;

/** The repaired fixtures must not disguise a weakened publication boundary. */
final class SettlementConfirmationBoundaryTest extends SettlementTestCase
{
    private function pendingCard(string $source = 'internal', ?array $card = null): array
    {
        $fixture = $this->fixture('1000.00');
        $draw = $this->advanceToResultPending($fixture['draw']);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $card ??= ['first_prize' => self::FIRST_PRIZE, 'bottom_two' => self::BOTTOM_TWO];
        app(DrawResultIngestionService::class)->ingest((int) $draw->id, $card, $source, (int) $maker->id);

        return $fixture + ['maker' => $maker, 'checker' => $checker, 'card' => $card];
    }

    private function refusal(callable $operation, string $code): void
    {
        try {
            $operation();
        } catch (DrawResultException $error) {
            $this->assertSame($code, $error->errorCode());

            return;
        }
        $this->fail('Unproved result publication or confirmation was accepted.');
    }

    private function assertStillPending(array $fixture): void
    {
        $drawId = (int) $fixture['draw']->id;
        $record = app(DrawResultIngestionService::class)->currentIngestion($drawId);
        $this->assertSame(DrawConfirmationStatus::Pending->value, $record['status']);
        $this->assertSame(DrawLifecycleState::ResultPending, $this->lifecycle()->currentState($fixture['draw']->fresh()));
        $this->assertSame(0, DrawResult::query()->where('draw_id', $drawId)->count());
        $this->assertSame(0, WinningNumber::query()->where('draw_id', $drawId)->count());
    }

    public function test_absent_confirmation_cannot_publish_even_in_result_pending(): void
    {
        $fixture = $this->fixture('1000.00');
        $draw = $this->advanceToResultPending($fixture['draw']);
        $before = $this->financeSnapshot();
        $this->refusal(fn () => $this->publication()->publish((int) $draw->id, [
            'first_prize' => self::FIRST_PRIZE, 'bottom_two' => self::BOTTOM_TWO,
        ]), DrawResultException::CODE_PUBLISH_REQUIRES_CONFIRMED);
        $this->assertSame(0, DrawResult::query()->count());
        $this->assertSame(0, WinningNumber::query()->count());
        $this->assertFinanceUnchanged($before, 'absent confirmation was refused');
    }

    public function test_pending_ingestion_is_not_publication_authority(): void
    {
        $f = $this->pendingCard();
        $before = $this->financeSnapshot();
        $this->refusal(fn () => $this->publication()->publish((int) $f['draw']->id, $f['card']),
            DrawResultException::CODE_PUBLISH_REQUIRES_CONFIRMED);
        $this->assertStillPending($f);
        $this->assertFinanceUnchanged($before, 'pending evidence is not confirmed evidence');
    }

    public function test_same_persisted_operator_cannot_be_maker_and_checker(): void
    {
        $f = $this->pendingCard();
        $before = $this->financeSnapshot();
        $this->refusal(fn () => app(DrawResultConfirmationService::class)->confirm(
            (int) $f['draw']->id, (int) $f['maker']->id, $f['card'],
        ), DrawResultException::CODE_CONFIRMATION_FORBIDDEN);
        $this->assertStillPending($f);
        $this->assertFinanceUnchanged($before, 'maker cannot confirm their own result');
    }

    public function test_checker_must_confirm_the_actual_ingested_prize_facts(): void
    {
        $f = $this->pendingCard();
        $before = $this->financeSnapshot();
        $different = ['first_prize' => '999999', 'bottom_two' => self::BOTTOM_TWO];
        $this->refusal(fn () => app(DrawResultConfirmationService::class)->confirm(
            (int) $f['draw']->id, (int) $f['checker']->id, $different,
        ), DrawResultException::CODE_MISMATCH_CONFIRMED);
        $this->assertStillPending($f);
        $this->assertFinanceUnchanged($before, 'checker claimed different numbers');
    }

    public function test_distinct_checker_does_not_make_unknown_provenance_trusted(): void
    {
        $f = $this->pendingCard('unregistered-test-source');
        $before = $this->financeSnapshot();
        $this->refusal(fn () => app(DrawResultConfirmationService::class)->confirm(
            (int) $f['draw']->id, (int) $f['checker']->id, $f['card'],
        ), DrawResultException::CODE_PROVENANCE_FORBIDDEN);
        $this->assertStillPending($f);
        $this->assertFinanceUnchanged($before, 'unregistered provenance cannot be published');
    }

    public function test_fixture_provenance_is_refused_if_config_marks_the_app_as_production(): void
    {
        $f = $this->pendingCard('glo_import:fixture');
        $before = $this->financeSnapshot();
        config(['app.env' => 'production']);

        $this->refusal(fn () => app(DrawResultConfirmationService::class)->confirm(
            (int) $f['draw']->id, (int) $f['checker']->id, $f['card'],
        ), DrawResultException::CODE_PROVENANCE_FORBIDDEN);
        $this->assertStillPending($f);
        $this->assertFinanceUnchanged($before, 'fixture provenance cannot publish in production');
    }

    public function test_disabling_the_manual_confirmation_policy_cannot_enable_publication(): void
    {
        $f = $this->pendingCard();
        $before = $this->financeSnapshot();
        config(['lottery.results.require_admin_confirmation' => false]);

        $this->refusal(fn () => app(DrawResultConfirmationService::class)->confirm(
            (int) $f['draw']->id, (int) $f['checker']->id, $f['card'],
        ), DrawResultException::CODE_CONFIRMATION_POLICY_DISABLED);
        $this->assertStillPending($f);
        $this->assertFinanceUnchanged($before, 'disabled confirmation policy must never permit publication');

        config(['lottery.results.require_admin_confirmation' => true]);
        $confirmed = app(DrawResultConfirmationService::class)->confirm(
            (int) $f['draw']->id, (int) $f['checker']->id, $f['card'],
        );
        $this->assertTrue($confirmed['published']);
    }

    public function test_confirmation_audit_failure_rolls_back_publication_and_confirmation_stamp(): void
    {
        $f = $this->pendingCard();
        $before = $this->financeSnapshot();
        $events = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $events);
        AuditLog::creating(function (AuditLog $audit): void {
            if (($audit->metadata['confirmation_status'] ?? null) === DrawConfirmationStatus::Confirmed->value) {
                throw new RuntimeException('injected confirmed-result audit failure');
            }
        });
        try {
            try {
                app(DrawResultConfirmationService::class)->confirm(
                    (int) $f['draw']->id, (int) $f['checker']->id, $f['card'],
                );
                $this->fail('Failed required confirmation audit must roll back publication.');
            } catch (RuntimeException $error) {
                $this->assertSame('injected confirmed-result audit failure', $error->getMessage());
            }
        } finally {
            AuditLog::setEventDispatcher($events);
        }
        $this->assertStillPending($f);
        $record = app(DrawResultIngestionService::class)->currentIngestion((int) $f['draw']->id);
        $this->assertArrayNotHasKey('confirmed_by', $record);
        $this->assertFinanceUnchanged($before, 'required audit failed after publication work');
    }

    public function test_real_confirmation_publishes_exact_independent_digit_strings_without_money(): void
    {
        $f = $this->pendingCard('internal', ['first_prize' => '000007', 'bottom_two' => '05']);
        $before = $this->financeSnapshot();
        $confirmed = app(DrawResultConfirmationService::class)->confirm(
            (int) $f['draw']->id, (int) $f['checker']->id, $f['card'],
        );
        $this->assertTrue($confirmed['published']);
        $record = app(DrawResultIngestionService::class)->currentIngestion((int) $f['draw']->id);
        $this->assertSame(DrawConfirmationStatus::Confirmed->value, $record['status']);
        $this->assertSame((int) $f['maker']->id, $record['ingested_by']);
        $this->assertSame((int) $f['checker']->id, $record['confirmed_by']);
        $this->assertNotSame($record['ingested_by'], $record['confirmed_by']);
        $result = DrawResult::query()->where('draw_id', $f['draw']->id)->firstOrFail();
        $this->assertSame($result->id, $record['draw_result_id']);
        $this->assertSame('000007', $result->first_prize);
        $this->assertNotNull($result->published_at);
        $this->assertSame(DrawLifecycleState::ResultPublished, $this->lifecycle()->currentState($f['draw']->fresh()));
        $this->assertContains('007', WinningNumber::query()->where('draw_id', $f['draw']->id)->pluck('number')->all());
        $this->assertContains('05', WinningNumber::query()->where('draw_id', $f['draw']->id)->pluck('number')->all());
        $this->assertFinanceUnchanged($before, 'confirmed result publication itself does not pay prizes');
    }

    public function test_different_publication_payload_cannot_replace_confirmed_facts(): void
    {
        $published = $this->publishResult($this->fixture()['draw']);
        $before = $this->financeSnapshot();
        $this->refusal(fn () => $this->publication()->publish((int) $published['draw']->id, [
            'first_prize' => '999999', 'bottom_two' => '99',
        ]), DrawResultException::CODE_MALFORMED);
        $this->assertSame(self::FIRST_PRIZE, $published['result']->fresh()->first_prize);
        $this->assertSame(1, DrawResult::query()->where('draw_id', $published['draw']->id)->count());
        $this->assertSame(6, WinningNumber::query()->where('draw_id', $published['draw']->id)->count());
        $this->assertFinanceUnchanged($before, 'different unconfirmed facts cannot replace the published card');
    }
}
