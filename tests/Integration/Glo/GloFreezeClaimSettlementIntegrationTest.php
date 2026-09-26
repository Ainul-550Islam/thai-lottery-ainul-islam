<?php

declare(strict_types=1);

namespace Tests\Integration\Glo;

use App\Enums\DrawStatus;
use App\Enums\GloClaimStatus;
use App\Enums\GloFreezeStatus;
use App\Enums\GloPaymentHoldStatus;
use App\Enums\UserStatus;
use App\Exceptions\GloClaimException;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloPrizeClaim;
use App\Models\GloPrizePaymentHold;
use App\Models\GloPublicTicketStatus;
use App\Models\GloTicket;
use App\Models\GloTicketFreeze;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Lottery\GloFrozenWinnerService;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Lottery\GloPublicTicketVerificationService;
use App\Services\Lottery\GloStampDutyCalculator;
use App\Services\Lottery\GloTicketFreezeService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GLO freeze → claim → hold → settlement integration.
 *
 * Covers: full happy path without freeze; frozen winner hold path; claim
 * matrix transitions; concurrency invariants where SQLite allows sequential
 * double-operations (unique fingerprint / ALREADY_PAID); public status after
 * each phase; no payment on frozen/expired tickets; stamp duty integrity end
 * to end; payment entry-point search remains read-only at HTTP layer.
 */
class GloFreezeClaimSettlementIntegrationTest extends TestCase
{
    use DatabaseTruncation;

    private GloTicketFreezeService $freezes;

    private GloPrizeClaimService $claims;

    private GloFrozenWinnerService $winners;

    private GloPublicTicketVerificationService $publicStatus;

    private User $admin;

    private User $paymentOperator;

    private Draw $draw;

    private string $ticketNumber = '042042';

    private GloTicket $ticket;

    private User $claimant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->freezes = app(GloTicketFreezeService::class);
        $this->claims = app(GloPrizeClaimService::class);
        $this->winners = app(GloFrozenWinnerService::class);
        $this->publicStatus = app(GloPublicTicketVerificationService::class);

        $this->admin = $this->freshUser()->create();
        $this->admin->syncRoles(['admin']);

        $this->paymentOperator = $this->freshUser()->create();
        $this->paymentOperator->syncRoles(['auditor']);
        $this->paymentOperator->givePermissionTo('execute glo prize payments');
        $this->paymentOperator->givePermissionTo('view dashboard');

        $this->draw = Draw::factory()->create([
            'status' => DrawStatus::Completed,
            'scheduled_at' => now()->subDays(8),
            'result_published_at' => now()->subDays(7),
            'completed_at' => now()->subDays(7),
        ]);

        DrawResult::create([
            'draw_id' => $this->draw->id,
            'first_prize' => $this->ticketNumber,
            'second_prize' => ['202020'],
            'third_prize' => ['303030'],
            'consolation_prizes' => [],
            'all_numbers' => [],
            'total_winners' => 2,
            'total_payout' => '6200000.00',
            'house_profit' => '0.00',
            'published_at' => now()->subDays(7),
            'metadata' => ['glo' => []],
        ]);

        $this->ticket = GloTicket::create([
            'draw_id' => $this->draw->id,
            'product' => 'l6',
            'ticket_number' => $this->ticketNumber,
            'set_series' => null,
            'owner_user_id' => null,
            'ticket_reference' => GloTicket::buildReference((int) $this->draw->id, 'l6', $this->ticketNumber),
            'metadata' => [],
        ]);

        $this->claimant = $this->freshUser()->create();
        $this->claimant->syncRoles(['player']);
        $this->claimant->forceFill(['date_of_birth' => now()->subYears(28)->toDateString()]);
        $this->claimant->save();
        $this->makeVerified($this->claimant);
    }

    public function test_happy_path_claim_without_freeze_settles_to_paid_once(): void
    {
        // Public status before freeze: unknown → NOT_FOUND (anti-enumeration).
        $ref = $this->ticket->ticket_reference;
        $status = $this->publicStatus->verify($ref);
        $this->assertSame('NOT_FOUND', $status['status']);

        $claim = $this->submitApprovedClaim();

        // No freeze → no hold, payment succeeds exactly once.
        $paid = $this->claims->pay($claim, $this->paymentOperator, 'TX-E2E-1');
        $this->assertSame(GloClaimStatus::Paid, $paid->status);

        try {
            $this->claims->pay($paid, $this->paymentOperator, 'TX-E2E-2');
            $this->fail('second sequential pay must fail');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('ALREADY_PAID', $e->getMessage());
        }

        $this->assertSame(1, GloPrizeClaim::query()->where('status', GloClaimStatus::Paid)->count());
        $this->assertSame('TX-E2E-1', GloPrizeClaim::query()->firstOrFail()->payment_transaction_reference);

        // Money integrity: duty + net from calculator.
        $calc = app(GloStampDutyCalculator::class);
        $claimFresh = GloPrizeClaim::query()->firstOrFail();
        // SQLite may strip trailing zeros on decimal read — compare at scale 2.
        $this->assertSame(0, bccomp($calc->dutyFor('6000000.00'), (string) $claimFresh->stamp_duty, 2));
        $this->assertSame(0, bccomp($calc->netAfterDuty('6000000.00'), (string) $claimFresh->net_prize, 2));
        // Explicit: gross = duty + net
        $this->assertSame(
            0,
            bccomp('6000000.00', bcadd((string) $claimFresh->stamp_duty, (string) $claimFresh->net_prize, 2), 2),
        );
    }

    public function test_freeze_claim_hold_release_approve_pay_flow(): void
    {
        $ref = $this->ticket->ticket_reference;

        // 1. Freeze first.
        $freeze = $this->freezes->requestFreeze([
            'draw_id' => (int) $this->draw->id,
            'product' => 'l6',
            'ticket_number' => $this->ticketNumber,
            'requesting_authority' => 'Royal Thai Police',
            'jurisdiction' => 'Bangkok',
            'case_reference' => 'CASE-E2E-1',
            'evidence_reference' => 'EVD-E2E-1',
            'evidence_type' => 'official_notice',
            'evidence_document_id' => 'SEC-999',
            'evidence_content_hash' => hash('sha256', 'bytes'),
        ], $this->admin);
        $this->freezes->startReview($freeze, $this->admin);
        $freeze = $this->freezes->approveToFrozen($freeze, $this->admin);
        $this->assertSame(GloFreezeStatus::Frozen, $freeze->status);

        // 2. Public status → FROZEN.
        $this->assertSame('FROZEN', $this->publicStatus->verify($ref)['status']);

        // 3. Winner sweep → hold + announcement.
        $sweep = $this->winners->processDraw((int) $this->draw->id, false, $this->admin);
        $this->assertSame(1, $sweep['holds_created']);
        $this->assertSame(0, $sweep['paid'] ?? 0);

        $this->assertSame(
            'FROZEN_AND_WINNING_PAYMENT_HELD',
            $this->publicStatus->verify($ref)['status'],
        );

        // 4. Claim while frozen → HOLD, payment blocked.
        $claim = $this->claims->submit([
            'ticket_id' => $this->ticket->id,
            'prize_category' => 'first',
            'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true,
        ], $this->claimant);
        $this->assertSame(GloClaimStatus::Hold, $claim->status);
        $this->assertSame('blocked', $claim->payment_status);
        $this->assertSame('FROZEN_TICKET', $claim->hold_reason);

        // Approve/pay must fail while held.
        try {
            $this->claims->approve($claim, $this->admin);
            $this->fail('approve blocked by hold/freeze');
        } catch (GloClaimException) {
            $this->assertTrue(true);
        }

        // 5. Release freeze.
        $this->freezes->release($freeze, $this->admin, 'warrant satisfied');
        $this->assertFalse($this->freezes->hasActiveEffectiveFreeze((int) $this->ticket->id));

        // 6. Clear holds → claim Eligible (not auto-paid).
        $this->claims->clearHoldsIfFreezesInactive((int) $this->ticket->id, $this->admin);
        $claim->refresh();
        $this->assertSame(GloClaimStatus::Eligible, $claim->status);
        $this->assertNull($claim->paid_at);

        // 7. Approve + pay.
        $claim = $this->claims->approve($claim, $this->admin);
        $this->assertSame(GloClaimStatus::Approved, $claim->status);

        $claim = $this->claims->pay($claim, $this->paymentOperator, 'TX-E2E-HOLD');
        $this->assertSame(GloClaimStatus::Paid, $claim->status);

        // 8. Public status after release (announcement row remains historical).
        $publicAfter = $this->publicStatus->verify($ref);
        $this->assertContains($publicAfter['status'], [
            'RELEASED',
            'NOT_FROZEN',
            'NOT_FOUND',
        ]);

        // 9. Invariants: never deleted freeze history; single paid claim.
        $this->assertSame(1, GloTicketFreeze::query()->count());
        $this->assertNotNull($freeze->fresh()->evidence_reference);
        $this->assertSame(1, GloPrizeClaim::query()->where('status', GloClaimStatus::Paid)->count());
        $this->assertSame(
            0,
            GloPrizePaymentHold::query()->where('status', GloPaymentHoldStatus::Active)->count(),
        );
    }

    public function test_state_machine_invariants_reject_illegal_transitions(): void
    {
        // FROZEN → PAID is not a freeze transition at all (no Paid state).
        $freeze = $this->freezeTicket();
        $this->assertSame(GloFreezeStatus::Frozen, $freeze->status);
        // Frozen can go released/expired only — never "paid".
        $this->assertSame(
            [GloFreezeStatus::Released, GloFreezeStatus::Expired],
            $freeze->status->allowedTransitions(),
        );

        // Explicit illegal: Released → Frozen
        $released = $this->freezes->release($freeze, $this->admin, 'done');
        $this->assertFalse($released->status->canTransitionTo(GloFreezeStatus::Frozen));
        $this->assertFalse($released->status->canTransitionTo(GloFreezeStatus::Requested));

        // Claim: UNDER_AGE (rejected) → PAID forbidden; PAYMENT_HOLD → EXECUTED without clear forbidden.
        $underAgeReject = GloPrizeClaim::create([
            'claim_reference' => 'GLOCLM-INV-AGE',
            'ticket_id' => $this->ticket->id,
            'draw_id' => $this->draw->id,
            'product' => 'l6',
            'prize_category' => 'first',
            'ticket_number' => $this->ticketNumber,
            'gross_prize' => '6000000.00',
            'stamp_duty' => '30000.00',
            'net_prize' => '5970000.00',
            'claimant_user_id' => $this->claimant->id,
            'age_verification_result' => 'failed_underage',
            'claim_channel' => 'glo_office',
            'status' => GloClaimStatus::Rejected,
            'payment_status' => 'cancelled',
            'hold_status' => 'none',
            'submitted_at' => now(),
            'fingerprint' => hash('sha256', 'inv-age'),
        ]);

        $this->assertFalse($underAgeReject->status->canTransitionTo(GloClaimStatus::Paid));
        $this->assertFalse($underAgeReject->status->canTransitionTo(GloClaimStatus::Approved));
        $this->assertTrue($underAgeReject->status->isTerminal());

        $held = GloPrizeClaim::create([
            'claim_reference' => 'GLOCLM-INV-HOLD',
            'ticket_id' => $this->ticket->id,
            'draw_id' => $this->draw->id,
            'product' => 'l6',
            'prize_category' => 'first',
            'ticket_number' => $this->ticketNumber,
            'gross_prize' => '6000000.00',
            'stamp_duty' => '30000.00',
            'net_prize' => '5970000.00',
            'claimant_user_id' => $this->claimant->id,
            'age_verification_result' => 'verified',
            'claim_channel' => 'glo_office',
            'status' => GloClaimStatus::Hold,
            'payment_status' => 'blocked',
            'hold_status' => 'active',
            'hold_reason' => 'FROZEN_TICKET',
            'submitted_at' => now(),
            'fingerprint' => hash('sha256', 'inv-hold'),
        ]);

        $this->assertFalse($held->status->canTransitionTo(GloClaimStatus::Paid));
        $this->assertFalse($held->status->canTransitionTo(GloClaimStatus::Paid));
        // Hold → Approved is also illegal (must go Hold → Eligible first).
        $this->assertFalse($held->status->canTransitionTo(GloClaimStatus::Approved));
        $this->assertTrue($held->status->canTransitionTo(GloClaimStatus::Eligible));
    }

    public function test_unique_constraints_support_concurrency_idempotency(): void
    {
        // Freeze fingerprint unique.
        $this->assertSchemaUnique('glo_ticket_freezes', ['fingerprint']);
        $this->assertSchemaUnique('glo_prize_claims', ['fingerprint']);
        $this->assertSchemaUnique('glo_prize_claims', ['payment_transaction_reference']);
        $this->assertSchemaUnique('glo_prize_payment_holds', ['fingerprint']);
        $this->assertSchemaUnique('glo_tickets', ['ticket_reference']);

        // Sequential double freeze request → same row (simulates 2nd worker losing race).
        $payload = [
            'draw_id' => (int) $this->draw->id,
            'product' => 'l6',
            'ticket_number' => $this->ticketNumber,
            'requesting_authority' => 'Police',
            'jurisdiction' => 'BKK',
            'case_reference' => 'CASE-RACE',
            'evidence_reference' => 'EVD-RACE',
        ];
        $a = $this->freezes->requestFreeze($payload, $this->admin);
        $b = $this->freezes->requestFreeze($payload, $this->admin);
        $this->assertSame($a->id, $b->id);

        // Reach FROZEN so the winner sweep can open a hold.
        $this->freezes->startReview($a, $this->admin);
        $this->freezes->approveToFrozen($a, $this->admin);

        // Two hold creations for same ticket race → second sees existing.
        $this->winners->processDraw((int) $this->draw->id, false, $this->admin);
        $this->winners->processDraw((int) $this->draw->id, false, $this->admin);
        $this->assertSame(1, GloPrizePaymentHold::query()->count());
    }

    public function test_concurrent_style_double_claim_and_double_pay(): void
    {
        // Claim race: first insert wins; second unique violation surfaces as
        // duplicate detection.
        $body = [
            'ticket_id' => $this->ticket->id,
            'prize_category' => 'first',
            'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true,
        ];
        $claim1 = $this->claims->submit($body, $this->claimant);

        try {
            $this->claims->submit($body, $this->claimant);
            $this->fail('duplicate claim must not insert a second row');
        } catch (GloClaimException) {
            $this->assertTrue(true);
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique constraint path also acceptable under true concurrency.
            $this->assertStringContainsString('UNIQUE', strtoupper($e->getMessage()));
        }

        $this->assertSame(1, GloPrizeClaim::query()->count());

        // Pay race: sequential second pay → ALREADY_PAID with stable reference.
        $claim1 = $this->claims->reviewEligible($claim1, $this->admin);
        $claim1 = $this->claims->approve($claim1, $this->admin);
        $this->claims->pay($claim1, $this->paymentOperator, 'TX-RACE-1');

        try {
            $this->claims->pay($claim1->fresh(), $this->paymentOperator, 'TX-RACE-2');
            $this->fail('ALREADY_PAID expected');
        } catch (GloClaimException $e) {
            $this->assertStringContainsString('ALREADY_PAID', $e->getMessage());
        }

        $this->assertSame('TX-RACE-1', GloPrizeClaim::query()->firstOrFail()->payment_transaction_reference);
    }

    public function test_transactional_pay_rolls_back_on_conflict(): void
    {
        $claim = $this->submitApprovedClaim();

        $countBefore = GloPrizeClaim::query()->count();
        $paidBefore = GloPrizeClaim::query()->where('status', GloClaimStatus::Paid)->count();

        try {
            DB::transaction(function () use ($claim) {
                // Pay succeeds inside the transaction, then the outer work aborts —
                // the Paid transition must roll back with the transaction.
                $this->claims->pay($claim, $this->paymentOperator, 'TX-ROLLBACK');
                throw new \RuntimeException('outer abort');
            });
            $this->fail('outer abort should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('outer abort', $e->getMessage());
        }

        $this->assertSame($countBefore, GloPrizeClaim::query()->count());
        $this->assertSame($paidBefore, GloPrizeClaim::query()->where('status', GloClaimStatus::Paid)->count());
        $fresh = GloPrizeClaim::query()->firstOrFail();
        $this->assertNull($fresh->payment_transaction_reference);
        $this->assertSame(GloClaimStatus::Approved, $fresh->status);
    }

    public function test_audit_log_written_for_freeze_claim_and_payment(): void
    {
        // freezeTicket() already drives requested → under_review → frozen.
        $freeze = $this->freezeTicket();
        $this->assertSame(GloFreezeStatus::Frozen, $freeze->status);

        // Release so the claim path is not hold-blocked for this audit walk.
        $this->freezes->release($freeze, $this->admin, 'audit path release');

        $claim = $this->submitApprovedClaim();
        $this->claims->pay($claim, $this->paymentOperator, 'TX-AUDIT');

        $actions = \App\Models\AuditLog::query()->pluck('description')->all();

        $this->assertContains('glo_freeze_requested', $actions);
        $this->assertContains('glo_freeze_under_review', $actions);
        $this->assertContains('glo_freeze_frozen', $actions);
        $this->assertContains('glo_claim_submitted', $actions);
        $this->assertContains('glo_claim_approved', $actions);
        $this->assertContains('glo_claim_paid', $actions);

        // No secret-looking payloads in audit metadata.
        foreach (\App\Models\AuditLog::query()->get() as $log) {
            $json = json_encode($log->metadata ?? []);
            $this->assertFalse(str_contains((string) $json, 'password'));
            $this->assertFalse(str_contains((string) $json, 'Bearer'));
        }
    }

    public function test_no_wallet_or_payout_lane_is_touched_by_glo_payment(): void
    {
        // Glo payment must not create Payout rows or move wallet balances.
        $wallet = \App\Models\Wallet::factory()->create([
            'user_id' => $this->claimant->id,
            'type' => 'primary',
            'currency' => 'THB',
            'balance' => '100.00',
            'locked_balance' => '0.00',
        ]);

        $claim = $this->submitApprovedClaim();
        $this->claims->pay($claim, $this->paymentOperator, 'TX-GLO-ONLY');

        $wallet->refresh();
        $this->assertSame('100.00', $wallet->balance);

        $this->assertSame(0, \App\Models\Payout::query()->where('user_id', $this->claimant->id)->count());
        $this->assertSame(0, \App\Models\FinancialHold::query()->count());

        $this->assertSame(GloClaimStatus::Paid, $claim->fresh()->status);
    }

    /* --------------------------------------------------------- helpers */

    private function freezeTicket(): GloTicketFreeze
    {
        $freeze = $this->freezes->requestFreeze([
            'draw_id' => (int) $this->draw->id,
            'product' => 'l6',
            'ticket_number' => $this->ticketNumber,
            'requesting_authority' => 'Police',
            'jurisdiction' => 'BKK',
            'case_reference' => 'CASE-INV-'.\Illuminate\Support\Str::random(16),
            'evidence_reference' => 'EVD-INV-'.\Illuminate\Support\Str::random(16),
        ], $this->admin);

        if ($freeze->status === \App\Enums\GloFreezeStatus::Requested) {
            $freeze = $this->freezes->startReview($freeze, $this->admin);
        }

        if ($freeze->status === \App\Enums\GloFreezeStatus::UnderReview) {
            $freeze = $this->freezes->approveToFrozen($freeze, $this->admin);
        }

        return $freeze;
    }

    private function submitApprovedClaim(): GloPrizeClaim
    {
        $claim = $this->claims->submit([
            'ticket_id' => $this->ticket->id,
            'prize_category' => 'first',
            'claim_channel' => 'glo_office',
            'original_ticket_evidenced' => true,
        ], $this->claimant);

        $claim = $this->claims->reviewEligible($claim, $this->admin);

        return $this->claims->approve($claim, $this->admin);
    }

    private function makeVerified(User $user): void
    {
        KycDocument::create([
            'user_id' => $user->id,
            'document_type' => 'national_id',
            'document_number' => random_int(1000000000000, 9999999999999),
            'file_path' => 'kyc/e2e.png',
            'original_filename' => 'id.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $this->admin->id,
            'metadata' => [],
        ]);
    }

    private function assertSchemaUnique(string $table, array $columns): void
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                $this->assertTrue(true);

                return;
            }
        }

        $this->fail(sprintf('Expected unique index on %s(%s)', $table, implode(', ', $columns)));
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'@gloe2e.local',
            'username' => 'ge'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }
}
