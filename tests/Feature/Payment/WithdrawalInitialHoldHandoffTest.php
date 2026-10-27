<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Models\Withdrawal;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalInitialHoldService;
use App\Services\Finance\WithdrawalIntegrityGuard;
use App\Services\Finance\WithdrawalRequestWithHoldService;
use App\Services\Finance\WithdrawalReservationService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\StripeRefundWagerFixture;

final class WithdrawalInitialHoldHandoffTest extends FinancialMasterTestCase
{
    use StripeRefundWagerFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.withdrawal.min' => '1.00']);
    }

    private function request(array $p, string $key = 'initial-handoff-request'): Withdrawal
    {
        return app(WithdrawalRequestWithHoldService::class)->request($p['wallet']->fresh(), Money::of('10', Currency::THB), PaymentMethod::Manual, $key);
    }

    private function paper(): array
    {
        $out = $this->moneyPaper();
        foreach (['withdrawals', 'withdrawal_initial_holds', 'withdrawal_reservations'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $out;
    }

    private function refused(callable $call): void
    {
        $before = $this->paper();
        try {
            $call();
            $this->fail('Unproved ownership or spending a protected initial hold must refuse.');
        } catch (FinancialException $e) {
            $this->assertNotEmpty($e->errorCode());
        }
        $this->assertSame($before, $this->paper());
    }

    public function test_first_request_creates_sealed_owned_initial_hold_not_approved_payout_authority(): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $row = $this->request($p);
        $this->assertSame('100.00', $p['wallet']->fresh()->balance);
        $this->assertSame('10.00', $p['wallet']->fresh()->locked_balance);
        $this->assertSame(WithdrawalStatus::Pending, $row->status);
        $this->assertDatabaseHas('withdrawal_initial_holds', ['withdrawal_id' => $row->id, 'state' => 'held']);
        $this->assertDatabaseCount('withdrawal_reservations', 0);
        $this->refused(fn () => app(WithdrawalCompletionService::class)->complete($row));
        Http::assertNothingSent();
    }

    public function test_api_request_approve_complete_and_exact_replays_hold_and_debit_only_once(): void
    {
        $p = $this->createAdmittedPlayer('100', Currency::THB);
        $this->actingAs($p['user'], 'sanctum');
        $payload = ['amount' => '10.00', 'currency' => 'THB', 'method' => 'manual', 'idempotency_key' => 'initial-http-round-trip'];
        $result = $this->postJson('/api/v1/withdrawals', $payload)->assertCreated();
        $row = Withdrawal::findOrFail($result->json('data.withdrawal.id'));
        $this->postJson('/api/v1/withdrawals', $payload)->assertCreated();
        app(WithdrawalApprovalService::class)->approve($row);
        $this->assertSame('10.00', $p['wallet']->fresh()->locked_balance);
        $initial = DB::table('withdrawal_initial_holds')->sole();
        $this->assertSame('transferred', $initial->state);
        $this->assertSame((int) $initial->id, (int) DB::table('withdrawal_reservations')->sole()->initial_hold_id);
        app(WithdrawalCompletionService::class)->complete($row->fresh());
        $before = $this->paper();
        app(WithdrawalCompletionService::class)->complete($row->fresh());
        $this->postJson('/api/v1/withdrawals', $payload)->assertCreated();
        $this->assertSame($before, $this->paper());
        $this->assertSame('90.00', $p['wallet']->fresh()->balance);
        $this->assertSame('0.00', $p['wallet']->fresh()->locked_balance);
        $this->assertSame('10.00', $p['wallet']->fresh()->total_withdrawn);
        $this->assertDatabaseHas('withdrawal_initial_holds', ['state' => 'consumed']);
        Http::assertNothingSent();
    }

    public function test_approval_uses_the_original_hold_even_when_available_balance_is_zero(): void
    {
        $p = $this->createPlayer('10', Currency::THB);
        $row = $this->request($p);
        app(WithdrawalApprovalService::class)->approve($row);
        $this->assertSame('10.00', $p['wallet']->fresh()->locked_balance);
        $this->assertSame('10.00', $p['wallet']->fresh()->balance);
        $this->assertDatabaseCount('withdrawal_reservations', 1);
        Http::assertNothingSent();
    }

    public static function closeCases(): array
    {
        return [['cancel', false], ['reject', false], ['fail', false], ['cancel', true], ['reject', true], ['fail', true]];
    }

    #[DataProvider('closeCases')]
    public function test_pending_and_approved_terminal_actions_release_the_same_original_once(string $action, bool $approve): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $row = $this->request($p);
        if ($approve) {
            app(WithdrawalApprovalService::class)->approve($row);
        }
        $this->close($row->fresh(), $action);
        $this->assertSame('100.00', $p['wallet']->fresh()->balance);
        $this->assertSame('0.00', $p['wallet']->fresh()->locked_balance);
        $this->assertDatabaseHas('withdrawal_initial_holds', ['state' => 'released']);
        $before = $this->paper();
        try {
            $this->close($row->fresh(), $action);
        } catch (FinancialException) {
            // Existing terminal workflow semantics may refuse a repeated decision.
        }
        $this->assertSame($before, $this->paper());
        $this->request($p);
        $this->assertSame($before, $this->paper());
        Http::assertNothingSent();
    }

    private function close(Withdrawal $row, string $action): void
    {
        if ($action === 'cancel') {
            app(WithdrawalService::class)->cancel($row, 'Synthetic cancellation.');
        } elseif ($action === 'reject') {
            app(WithdrawalApprovalService::class)->reject($row, 'Synthetic rejection.');
        } else {
            app(WithdrawalApprovalService::class)->markFailed($row, 'Synthetic failure.');
        }
    }

    public function test_kyc_detention_retains_protected_initial_funds_but_can_cancel_them(): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $row = $this->request($p);
        DB::transaction(fn () => app(FinancialStateTransitionService::class)->transitionWithdrawal($row, WithdrawalStatus::KycRequired));
        $this->refused(fn () => app(WalletHoldService::class)->release($p['wallet']->fresh(), Money::of('1', Currency::THB)));
        app(WithdrawalService::class)->cancel($row->fresh());
        $this->assertSame('0.00', $p['wallet']->fresh()->locked_balance);
    }

    public function test_initial_and_canonical_floors_add_instead_of_sharing_the_same_funds(): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $this->request($p);
        $other = app(WithdrawalService::class)->request($p['wallet']->fresh(), Money::of('20', Currency::THB), PaymentMethod::Manual, 'other-canonical-request');
        app(WithdrawalApprovalService::class)->approve($other);
        $this->assertSame('30.00', $p['wallet']->fresh()->locked_balance);
        $this->refused(fn () => app(WalletHoldService::class)->release($p['wallet']->fresh(), Money::of('1', Currency::THB)));
        $this->refused(fn () => app(WalletService::class)->debit($p['wallet']->fresh(), Money::of('71', Currency::THB)));
    }

    public function test_handoff_insert_failure_can_be_caught_by_an_ambient_caller_without_leaking_the_transfer(): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $row = $this->request($p);
        $before = $this->paper();
        $armed = true;
        DB::listen(function ($event) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($event->sql), 'insert ') && str_contains($event->sql, 'withdrawal_reservations')) {
                $armed = false;
                throw WithdrawalException::withCode('synthetic_handoff_failure', 'Synthetic handoff failure.');
            }
        });
        DB::transaction(function () use ($row, $p): void {
            try {
                app(WithdrawalReservationService::class)->reserve($row, $p['wallet']->fresh());
            } catch (FinancialException) {
                // Outer root commits: the handoff unit must have rolled back its savepoint.
            }
        });
        $this->assertFalse($armed);
        $this->assertSame($before, $this->paper());
        app(WithdrawalApprovalService::class)->approve($row);
        $this->assertSame('10.00', $p['wallet']->fresh()->locked_balance);
    }

    public static function corruptions(): array
    {
        return [['cipher'], ['amount'], ['hash'], ['state'], ['missing_canonical'], ['missing_pointer']];
    }

    #[DataProvider('corruptions')]
    public function test_corrupt_or_unbound_initial_ownership_never_authorizes_repair_or_approval(string $mode): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $row = $this->request($p);
        if (in_array($mode, ['missing_canonical', 'missing_pointer'], true)) {
            app(WithdrawalApprovalService::class)->approve($row);
            if ($mode === 'missing_canonical') {
                DB::table('withdrawal_reservations')->delete();
            } else {
                DB::table('withdrawal_reservations')->update(['initial_hold_id' => null]);
            }
            $this->refused(fn () => app(WalletHoldService::class)->release($p['wallet']->fresh(), Money::of('1', Currency::THB)));
        } else {
            DB::table('withdrawal_initial_holds')->update(match ($mode) {
                'cipher' => ['origin_cipher' => 'not-a-sealed-origin'], 'amount' => ['amount' => '9.00'],
                'hash' => ['terms_hash' => str_repeat('0', 64)], default => ['state' => 'released', 'closed_at' => now()],
            });
            $this->refused(fn () => app(WithdrawalApprovalService::class)->approve($row));
        }
        $this->refused(fn () => $this->request($p));
    }

    public function test_old_anonymous_hold_is_not_inferred_as_a_new_owned_initial_source_on_replay(): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $row = app(WithdrawalService::class)->request($p['wallet'], Money::of('10', Currency::THB), PaymentMethod::Manual, 'initial-handoff-request');
        $legacyHash = hash('sha256', json_encode([$row->reference_number, (int) $row->user_id, (int) $row->wallet_id,
            $row->currency->value, (string) $row->amount, (string) $row->fee, (string) $row->net_amount,
            $row->method->value, $row->payout_details, data_get($row->metadata, 'outbound_provider_account')], JSON_THROW_ON_ERROR));
        $this->assertSame($legacyHash, app(WithdrawalIntegrityGuard::class)->termsFingerprint($row));
        app(WalletHoldService::class)->hold($p['wallet'], Money::of('10', Currency::THB));
        $before = $this->paper();
        $this->assertSame($row->id, $this->request($p)->id);
        $this->assertSame($before, $this->paper());
        $this->assertDatabaseCount('withdrawal_initial_holds', 0);
    }

    public function test_refund_publication_blocks_new_handoff_but_not_release_of_the_existing_initial_hold(): void
    {
        $f = $this->wagerRefundFixture();
        $row = $this->request($f);
        $this->publishPendingRefund($f);
        $this->refused(fn () => app(WithdrawalApprovalService::class)->approve($row));
        app(WithdrawalService::class)->cancel($row);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseHas('stripe_refund_recovery_intents', ['deposit_id' => $f['deposit']->id]);
    }

    public function test_nonempty_initial_ownership_blocks_schema_rollback_without_dropping_history(): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $this->request($p);
        $before = $this->paper();
        try {
            (require database_path('migrations/2026_10_18_000700_create_withdrawal_initial_holds.php'))->down();
            $this->fail('Nonempty source history must survive rollback.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Preserve', $e->getMessage());
        }
        $this->assertSame($before, $this->paper());
    }

    public function test_missing_required_initial_row_cannot_fall_back_to_a_second_hold_or_no_release(): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $row = $this->request($p);
        DB::table('withdrawal_initial_holds')->delete();
        $this->refused(fn () => app(WithdrawalApprovalService::class)->approve($row));
        $this->refused(fn () => app(WithdrawalService::class)->cancel($row));
        $this->refused(fn () => $this->request($p));
        $this->assertSame('10.00', $p['wallet']->fresh()->locked_balance);
    }

    public static function markerChanges(): array
    {
        return [[null], ['other-version']];
    }

    #[DataProvider('markerChanges')]
    public function test_marker_removal_or_replacement_breaks_original_terms_instead_of_enrolling_history(?string $marker): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $row = $this->request($p);
        $metadata = $row->metadata;
        unset($metadata[WithdrawalInitialHoldService::ORIGIN_KEY]);
        if ($marker !== null) {
            $metadata[WithdrawalInitialHoldService::ORIGIN_KEY] = $marker;
        }
        $row->metadata = $metadata;
        $row->save();
        $this->refused(fn () => app(WithdrawalApprovalService::class)->approve($row));
        $this->refused(fn () => $this->request($p));
    }

    public function test_member_cancellation_endpoint_releases_pending_initial_funds_once(): void
    {
        $p = $this->createAdmittedPlayer('100', Currency::THB);
        $this->actingAs($p['user'], 'sanctum');
        $response = $this->postJson('/api/v1/withdrawals', ['amount' => '10.00', 'currency' => 'THB', 'method' => 'manual', 'idempotency_key' => 'http-cancel-initial-hold'])->assertCreated();
        $id = $response->json('data.withdrawal.id');
        $this->postJson('/api/v1/withdrawals/'.$id.'/cancel', ['reason' => 'Owner cancellation.'])->assertOk();
        $before = $this->paper();
        $this->postJson('/api/v1/withdrawals/'.$id.'/cancel', ['reason' => 'Retry.'])->assertStatus(422);
        $this->assertSame($before, $this->paper());
        $this->assertSame('0.00', $p['wallet']->fresh()->locked_balance);
        $this->assertSame('100.00', $p['wallet']->fresh()->balance);
    }

    public function test_initial_owner_insert_failure_rolls_back_the_request_and_real_hold_in_an_ambient_root(): void
    {
        $p = $this->createPlayer('100', Currency::THB);
        $before = $this->paper();
        $armed = true;
        DB::listen(function ($event) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($event->sql), 'insert ') && str_contains($event->sql, 'withdrawal_initial_holds')) {
                $armed = false;
                throw WithdrawalException::withCode('synthetic_initial_insert_failure', 'Synthetic owner insertion failure.');
            }
        });
        DB::transaction(function () use ($p): void {
            try {
                $this->request($p);
            } catch (FinancialException) {
            }
        });
        $this->assertFalse($armed);
        $this->assertSame($before, $this->paper());
        $this->assertDatabaseCount('withdrawals', 0);
        $this->assertSame('0.00', $p['wallet']->fresh()->locked_balance);
    }

    public function test_refund_arriving_after_handoff_does_not_block_the_already_owned_settlement(): void
    {
        $f = $this->wagerRefundFixture();
        $row = $this->request($f);
        app(WithdrawalApprovalService::class)->approve($row);
        $this->publishPendingRefund($f);
        app(WithdrawalCompletionService::class)->complete($row->fresh());
        $this->assertSame('80.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseHas('withdrawal_initial_holds', ['state' => 'consumed']);
        $this->assertDatabaseCount('stripe_refund_recoveries', 0);
    }
}
