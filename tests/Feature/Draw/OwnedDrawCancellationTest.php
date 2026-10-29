<?php

declare(strict_types=1);

namespace Tests\Feature\Draw;

use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BetCancellationData;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DrawLifecycleState;
use App\Enums\DrawStatus;
use App\Enums\UserStatus;
use App\Enums\WalletStatus;
use App\Exceptions\DrawLifecycleException;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\Payout;
use App\Models\User;
use App\Services\Betting\BetCancellationService;
use App\Services\Betting\BetPurchaseService;
use App\Services\Draw\DrawCancellationService;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Finance\WalletService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Throwable;

final class OwnedDrawCancellationTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;

    private function operator(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('admin');

        return $actor;
    }

    private function secondMember(array $f): array
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, $f['wallet']->currency);
        $this->creditFixture($wallet, '100.00', 'second owned draw member');
        $result = app(BetPurchaseService::class)->purchase(new BetPurchaseData($user->id, $f['draw']->id,
            '3d_direct', '007', '10.00', 'second-owned-draw-member'));

        return ['user' => $user, 'wallet' => $wallet, 'bet' => $result->bet, 'result' => $result, 'draw' => $f['draw']];
    }

    private function state(): array
    {
        $out = [];
        foreach (['draws', 'wallets', 'bets', 'bet_items', 'tickets', 'number_limits', 'bet_purchase_ownerships',
            'bet_purchase_receipts', 'bet_commission_ownerships', 'agent_commissions', 'draw_cancellation_ownerships',
            'financial_transactions', 'ledger_entries', 'ledger_accounts', 'audit_logs'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
        }

        return $out;
    }

    private function cancel(User $actor, Draw $draw, string $reason = 'Synthetic local operator cancellation'): array
    {
        return app(DrawCancellationService::class)->cancel($actor->id, $draw->id, $reason);
    }

    private function refusesWithoutWrites(callable $operation, ?string $code = null): void
    {
        $before = $this->state();
        try {
            $operation();
            $this->fail('Unsafe cancellation was accepted.');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
            if ($code !== null) {
                $this->assertInstanceOf(DrawLifecycleException::class, $e);
                $this->assertSame($code, $e->errorCode());
            }
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->state());
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::BDT], [Currency::USD]];
    }

    #[DataProvider('currencies')]
    public function test_all_original_members_refund_atomically_then_terminal_replay_is_read_only(Currency $currency): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $second = $this->secondMember($f);
        $actor = $this->operator();
        $result = $this->cancel($actor, $f['draw']);
        $this->assertFalse($result['replayed']);
        $this->assertSame(2, $result['bet_count']);
        $this->assertSame(2, $result['newly_refunded_count']);
        $this->assertCount(2, array_unique($result['refund_transaction_ids']));
        $this->assertSame('original_owned_refunds', $result['proof_scope']);
        $this->assertSame(DrawStatus::Cancelled, $f['draw']->fresh()->status);
        foreach ([$f, $second] as $member) {
            $this->assertSame('100.00', $member['wallet']->fresh()->balance);
            $this->assertSame(BetStatus::Cancelled, $member['bet']->fresh()->status);
            $this->assertSame('draw_cancelled', $member['bet']->fresh()->cancelled_reason);
            $claim = DB::table('bet_purchase_ownerships')->where('bet_id', $member['bet']->id)->sole();
            $this->assertSame('cancelled', $claim->state);
            $this->assertContains((int) $claim->refund_transaction_id, $result['refund_transaction_ids']);
        }
        $this->assertSame('0.00', number_format((float) DB::table('number_limits')->where('draw_id', $f['draw']->id)->value('current_payout_exposure'), 2, '.', ''));
        $this->assertSame([$actor->id], DB::table('audit_logs')->where('action', 'cancel_bet')->distinct()->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
        config(['lottery.draw_cancellation.enabled' => false, 'lottery.cancellation.enabled' => false, 'lottery.draw_cancellation.max_bets' => 1]);
        $before = $this->state();
        $replay = $this->cancel($actor, $f['draw']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(0, $replay['newly_refunded_count']);
        $this->assertSame($result['refund_transaction_ids'], $replay['refund_transaction_ids']);
        $this->assertSame($result['ownership_id'], $replay['ownership_id']);
        $this->assertSame($before, $this->state());
    }

    public function test_json_object_key_order_does_not_change_a_seal_but_changed_terms_are_refused(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $result = $this->cancel($actor, $f['draw']);
        $header = DB::table('draw_cancellation_ownerships')->where('id', $result['ownership_id']);
        $saved = $header->first();
        $participants = json_decode($saved->participants, true, 512, JSON_THROW_ON_ERROR);
        $outcomes = json_decode($saved->outcomes, true, 512, JSON_THROW_ON_ERROR);
        $participants = array_map(fn (array $item): array => array_reverse($item, true), $participants);
        $outcomes = array_map(fn (array $item): array => array_reverse($item, true), $outcomes);
        $header->update([
            'participants' => json_encode($participants, JSON_THROW_ON_ERROR),
            'outcomes' => json_encode($outcomes, JSON_THROW_ON_ERROR),
        ]);

        $before = $this->state();
        $this->assertTrue($this->cancel($actor, $f['draw'])['replayed']);
        $this->assertSame($before, $this->state());

        $participants[0]['wallet_id']++;
        $header->update(['participants' => json_encode($participants, JSON_THROW_ON_ERROR)]);
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']), 'draw_cancellation_terms_unproven');
    }

    public function test_exactly_valid_legacy_v1_seals_replay_without_being_rewritten(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $result = $this->cancel($actor, $f['draw']);
        $header = DB::table('draw_cancellation_ownerships')->where('id', $result['ownership_id']);
        $saved = $header->first();
        $participants = json_decode($saved->participants, true, 512, JSON_THROW_ON_ERROR);
        $outcomes = json_decode($saved->outcomes, true, 512, JSON_THROW_ON_ERROR);
        // Reconstruct the old pre-v2 receipt format exactly, including its
        // original explicitly ordered outcome fields, not an unverified bypass.
        $draw = $f['draw']->fresh();
        $terms = hash('sha256', json_encode(['owned-draw-cancellation-v1', (int) $draw->id,
            $draw->uuid, $draw->draw_number, $draw->currency->value,
            (int) $saved->actor_user_id, $saved->previous_status, $saved->reason, $participants], JSON_THROW_ON_ERROR));
        $outcomes = array_map(fn (array $item): array => [
            'bet_id' => $item['bet_id'], 'ownership_id' => $item['ownership_id'],
            'refund_transaction_id' => $item['refund_transaction_id'], 'refund_hash' => $item['refund_hash'],
        ], $outcomes);
        $seal = hash('sha256', json_encode([$terms, $outcomes, $saved->completed_at], JSON_THROW_ON_ERROR));
        $header->update(['terms_hash' => $terms, 'outcome_hash' => $seal]);

        $before = $this->state();
        $this->assertTrue($this->cancel($actor, $f['draw'])['replayed']);
        $this->assertSame($before, $this->state());
        $header->update(['reason' => 'Different legacy reason']);
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']), 'draw_cancellation_terms_unproven');
    }

    public function test_closed_draw_and_disabled_player_cancellation_do_not_block_authorized_operator_refunds(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        app(DrawLifecycleService::class)->close($f['draw']);
        $f['draw']->forceFill(['betting_close_at' => now()->subDay()])->save();
        config(['lottery.cancellation.enabled' => false]);
        $this->assertSame(1, $this->cancel($actor, $f['draw'])['newly_refunded_count']);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
    }

    public function test_previously_player_cancelled_original_is_proven_but_never_refunded_again(): void
    {
        $f = $this->purchaseFixture();
        $old = app(BetCancellationService::class)->cancel($this->cancelData($f));
        $this->secondMember($f);
        $actor = $this->operator();
        $result = $this->cancel($actor, $f['draw']);
        $this->assertSame(1, $result['newly_refunded_count']);
        $this->assertContains((int) $old->refund->id, $result['refund_transaction_ids']);
        $this->assertSame('player_request', $f['bet']->fresh()->cancelled_reason);
        $before = $this->state();
        $this->assertTrue($this->cancel($actor, $f['draw'])['replayed']);
        $this->assertSame($before, $this->state());
    }

    public function test_later_member_accounting_failure_rolls_back_earlier_refund_risk_audit_and_header(): void
    {
        $f = $this->purchaseFixture();
        $second = $this->secondMember($f);
        $actor = $this->operator();
        DB::table('bet_purchase_ownerships')->where('bet_id', $second['bet']->id)->update(['original_hash' => str_repeat('0', 64)]);
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']));
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame(DrawStatus::Open, $f['draw']->fresh()->status);
        $this->assertSame(0, DB::table('draw_cancellation_ownerships')->count());
    }

    public function test_later_frozen_wallet_rolls_back_the_entire_draw(): void
    {
        $f = $this->purchaseFixture();
        $second = $this->secondMember($f);
        $actor = $this->operator();
        $second['wallet']->forceFill(['status' => WalletStatus::Frozen])->save();
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']));
    }

    public static function authorityFailures(): array
    {
        return [['player'], ['auditor'], ['suspended'], ['deleted'], ['revoked'], ['super-admin-without-duty']];
    }

    #[DataProvider('authorityFailures')]
    public function test_current_explicit_operator_authority_cannot_be_replaced_by_stale_models_or_super_admin_bypass(string $mode): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $actor->load('roles', 'permissions');
        match ($mode) {
            'player' => $actor->syncRoles(['player']),
            'auditor' => $actor->syncRoles(['auditor']),
            'suspended' => DB::table('users')->where('id', $actor->id)->update(['status' => UserStatus::Suspended->value]),
            'deleted' => $actor->delete(),
            'revoked' => DB::table('role_has_permissions')->whereIn('role_id', $actor->roles->modelKeys())->delete(),
            'super-admin-without-duty' => $this->stripSuperDuty($actor),
        };
        $before = $this->state();
        try {
            $this->cancel($actor, $f['draw']);
            $this->fail('Missing current authority was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->state());
        }
    }

    private function stripSuperDuty(User $actor): void
    {
        $actor->syncRoles(['super-admin']);
        DB::table('role_has_permissions')->whereIn('role_id', $actor->fresh()->roles->modelKeys())->delete();
    }

    public function test_direct_staff_duty_with_staff_role_is_accepted_but_player_with_same_duty_is_refused(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        DB::table('role_has_permissions')->whereIn('role_id', $actor->roles->modelKeys())->delete();
        $actor->givePermissionTo('manage draws');
        $this->assertFalse($this->cancel($actor, $f['draw'])['replayed']);
        $actor->syncRoles(['player']);
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']));
    }

    public function test_all_bet_history_requires_coordinator_even_when_already_cancelled_or_client_context_claims_refunded(): void
    {
        $f = $this->purchaseFixture();
        app(BetCancellationService::class)->cancel($this->cancelData($f));
        $this->refusesWithoutWrites(fn () => app(DrawLifecycleService::class)->transitionTo($f['draw'], DrawLifecycleState::Cancelled,
            ['refunded' => true, 'force' => true, 'draw_cancellation_id' => 1]), 'draw_cancellation_coordinator_required');
    }

    public function test_inactive_service_object_cannot_be_used_as_a_bet_or_lifecycle_dispatch_capability(): void
    {
        $f = $this->purchaseFixture();
        $service = app(DrawCancellationService::class);
        $this->refusesWithoutWrites(fn () => app(BetCancellationService::class)->cancel(new BetCancellationData(
            $f['user']->id, (string) $f['bet']->id, BetCancellationReason::DrawCancelled), $service), 'draw_cancellation_dispatch_refused');
        $this->refusesWithoutWrites(fn () => app(DrawLifecycleService::class)->transitionTo($f['draw'], DrawLifecycleState::Cancelled,
            [], $service), 'draw_cancellation_dispatch_refused');
    }

    public function test_empty_draw_cancels_and_replays_without_inventing_any_wallet_or_financial_transaction(): void
    {
        $draw = Draw::factory()->create();
        $actor = $this->operator();
        $before = DB::table('financial_transactions')->count();
        $result = $this->cancel($actor, $draw);
        $this->assertSame(0, $result['bet_count']);
        $this->assertSame([], $result['refund_transaction_ids']);
        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame($before, DB::table('financial_transactions')->count());
        $snapshot = $this->state();
        $this->assertTrue($this->cancel($actor, $draw)['replayed']);
        $this->assertSame($snapshot, $this->state());
    }

    public function test_unowned_and_hidden_histories_are_not_silently_adopted(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $f['bet']->delete();
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']), 'draw_cancellation_unowned_history');
        $f['bet']->restore();
        DB::table('bet_purchase_receipts')->where('purchase_ownership_id', $f['claim']->id)->delete();
        DB::table('bet_commission_ownerships')->where('purchase_ownership_id', $f['claim']->id)->delete();
        DB::table('bet_purchase_ownerships')->where('id', $f['claim']->id)->delete();
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']), 'draw_cancellation_unowned_history');
    }

    public function test_capacity_and_disabled_switch_refuse_before_any_partial_refund(): void
    {
        $f = $this->purchaseFixture();
        $this->secondMember($f);
        $actor = $this->operator();
        config(['lottery.draw_cancellation.max_bets' => 1]);
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']), 'draw_cancellation_capacity_review_required');
        config(['lottery.draw_cancellation.max_bets' => 100, 'lottery.draw_cancellation.enabled' => false]);
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']), 'draw_cancellation_disabled');
    }

    public function test_existing_transaction_is_never_committed_or_used_as_root_authority(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $before = $this->state();
        DB::beginTransaction();
        try {
            $this->cancel($actor, $f['draw']);
            $this->fail('Ambient transaction accepted.');
        } catch (DrawLifecycleException $e) {
            $this->assertSame('draw_cancellation_root_required', $e->errorCode());
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->state());
    }

    public static function corruptions(): array
    {
        return array_map(fn ($v): array => [$v], ['actor', 'reason', 'previous_status', 'participants', 'outcomes', 'outcome_hash',
            'terms_hash', 'preparing', 'refunded', 'completed_at', 'missing_completed_at', 'missing_header',
            'refund_status', 'refund_journal', 'original_journal', 'draw_currency', 'draw_status']);
    }

    #[DataProvider('corruptions')]
    public function test_terminal_replay_needs_original_member_accounting_and_sealed_header_not_saved_labels(string $mode): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $result = $this->cancel($actor, $f['draw']);
        $id = $result['ownership_id'];
        $header = DB::table('draw_cancellation_ownerships')->where('id', $id);
        match ($mode) {
            'actor' => $header->update(['actor_user_id' => $f['user']->id]),
            'reason' => $header->update(['reason' => 'different reason']),
            'previous_status' => $header->update(['previous_status' => 'closed']),
            'participants' => $header->update(['participants' => '[]']),
            'outcomes' => $header->update(['outcomes' => '[]']),
            'outcome_hash' => $header->update(['outcome_hash' => str_repeat('0', 64)]),
            'terms_hash' => $header->update(['terms_hash' => str_repeat('0', 64)]),
            'preparing', 'refunded' => $header->update(['state' => $mode]),
            'completed_at' => $header->update(['completed_at' => '2000-01-01 00:00:00']),
            'missing_completed_at' => $header->update(['completed_at' => null]),
            'missing_header' => $header->delete(),
            'refund_status' => DB::table('financial_transactions')->where('id', $result['refund_transaction_ids'][0])->update(['status' => 'pending']),
            'refund_journal' => DB::table('ledger_entries')->where('financial_transaction_id', $result['refund_transaction_ids'][0])->delete(),
            'original_journal' => DB::table('ledger_entries')->where('financial_transaction_id', $f['result']->transaction->id)->delete(),
            'draw_currency' => $f['draw']->fresh()->forceFill(['currency' => Currency::USD])->save(),
            'draw_status' => $f['draw']->fresh()->forceFill(['status' => DrawStatus::Open])->save(),
        };
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']));
    }

    public function test_changed_reason_conflicts_but_a_second_current_operator_can_prove_the_original_refunds(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $this->cancel($actor, $f['draw']);
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw'], 'A different cancellation intent'), 'draw_cancellation_review_required');
        $other = User::factory()->create();
        $other->assignRole('admin');
        $before = $this->state();
        $this->assertTrue($this->cancel($other, $f['draw'])['replayed']);
        $this->assertSame($before, $this->state());
        $this->assertSame($actor->id, (int) DB::table('draw_cancellation_ownerships')->sole()->actor_user_id);
    }

    public static function unmanagedRisk(): array
    {
        return [['current_amount'], ['current_payout_exposure']];
    }

    #[DataProvider('unmanagedRisk')]
    public function test_unmanaged_residual_reservations_are_not_swept_or_left_behind_a_cancelled_draw(string $column): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $limit = DB::table('number_limits')->where('id', $f['claim']->number_limit_id)->sole();
        DB::table('number_limits')->where('id', $limit->id)->update([$column => bcadd((string) $limit->{$column}, '1.00', 2)]);
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']), 'draw_cancellation_unmanaged_risk_review_required');
    }

    public function test_empty_draw_with_anonymous_risk_cannot_bypass_the_coordinator_or_invent_a_refund_recipient(): void
    {
        $draw = Draw::factory()->create();
        $actor = $this->operator();
        NumberLimit::factory()->create(['draw_id' => $draw->id, 'current_amount' => '1.00', 'current_payout_exposure' => '0.00']);
        $this->refusesWithoutWrites(fn () => app(DrawLifecycleService::class)->cancel($draw), 'draw_cancellation_coordinator_required');
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $draw), 'draw_cancellation_unmanaged_risk_review_required');
    }

    public static function terminalStates(): array
    {
        return [[DrawStatus::ResultPublished], [DrawStatus::Completed], [DrawStatus::Cancelled]];
    }

    #[DataProvider('terminalStates')]
    public function test_published_settled_and_unowned_cancelled_draws_cannot_enter_new_refund_dispatch(DrawStatus $status): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $f['draw']->forceFill(['status' => $status])->save();
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']), 'draw_cancellation_state_refused');
    }

    public function test_any_existing_prize_history_requires_controlled_review_before_refunding_active_owned_bets(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        Payout::factory()->create(['draw_id' => $f['draw']->id, 'bet_id' => $f['bet']->id, 'user_id' => $f['user']->id]);
        $this->refusesWithoutWrites(fn () => $this->cancel($actor, $f['draw']), 'draw_cancellation_settlement_review_required');
    }

    public function test_populated_migration_down_preserves_financial_history(): void
    {
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $this->cancel($actor, $f['draw']);
        $migration = require database_path('migrations/2026_10_17_000300_create_draw_cancellation_ownerships_table.php');
        $this->refusesWithoutWrites(fn () => $migration->down());
    }
}
