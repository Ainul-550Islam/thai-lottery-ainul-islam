<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\BetPurchaseData;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use App\Enums\WalletStatus;
use App\Exceptions\BetDomainException;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\User;
use App\Services\Betting\BetCancellationService;
use App\Services\Betting\BetPurchaseIdempotencyService;
use App\Services\Betting\BetPurchaseReceipt;
use App\Services\Betting\BetPurchaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;

final class OwnedPurchaseReplayTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;

    protected function command(array $f, string $stake = '10.00', string $number = '007', string $market = '3d_direct'): BetPurchaseData
    {
        return new BetPurchaseData($f['user']->id, $f['draw']->id, $market, $number, $stake, 'owned-cancellation-fixture');
    }

    protected function state(): array
    {
        $out = [];
        foreach (['wallets', 'bets', 'bet_items', 'tickets', 'number_limits', 'bet_purchase_ownerships', 'bet_purchase_receipts',
            'bet_commission_ownerships', 'agent_commissions', 'financial_transactions', 'ledger_entries', 'ledger_accounts', 'audit_logs'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $out;
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::BDT], [Currency::USD]];
    }

    #[DataProvider('currencies')]
    public function test_purchase_existing_and_direct_replay_return_exact_owned_debit_without_writes(Currency $currency): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $before = $this->state();
        $purchase = app(BetPurchaseService::class);
        foreach ([$purchase->purchase($this->command($f)), $purchase->existingPurchaseFor($this->command($f)),
            app(BetPurchaseIdempotencyService::class)->replayResultFor($f['bet'])] as $result) {
            $this->assertTrue($result->isReplay());
            $this->assertSame($f['result']->transaction->id, $result->transaction->id);
            $this->assertSame($f['result']->ledgerEntryIds, $result->ledgerEntryIds);
            $this->assertSame([], $result->riskReservation);
            $this->assertSame('original_funding_only', $result->context['proof_scope']);
        }
        $this->assertSame($before, $this->state());
    }

    public function test_cancelled_purchase_replays_original_debit_not_the_refund_or_a_new_purchase(): void
    {
        $f = $this->purchaseFixture();
        $refund = app(BetCancellationService::class)->cancel($this->cancelData($f));
        $f['draw']->forceFill(['betting_close_at' => now()->subDay()])->save();
        config(['lottery.betting.currency' => 'USD']);
        $before = $this->state();
        $replay = app(BetPurchaseService::class)->purchase($this->command($f));
        $this->assertSame(BetStatus::Cancelled, $replay->bet->status);
        $this->assertSame($f['result']->transaction->id, $replay->transaction->id);
        $this->assertNotSame($refund->refund->id, $replay->transaction->id);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame($before, $this->state());
    }

    public function test_winning_fields_and_frozen_wallet_do_not_reauthorize_or_invalidate_original_funding(): void
    {
        $f = $this->purchaseFixture();
        DB::table('bet_items')->where('bet_id', $f['bet']->id)->update(['is_winner' => true, 'actual_payout' => '50.00']);
        $f['bet']->forceFill(['status' => BetStatus::Won, 'actual_payout' => '50.00'])->save();
        $f['wallet']->forceFill(['status' => WalletStatus::Frozen])->save();
        $before = $this->state();
        $this->assertTrue(app(BetPurchaseService::class)->purchase($this->command($f))->isReplay());
        $this->assertSame($before, $this->state());
    }

    public static function invalidStakes(): array
    {
        return [['10.001'], ['10.000'], ['1e1'], ['+10'], ['-10'], ['0'], ['10.01']];
    }

    #[DataProvider('invalidStakes')]
    public function test_malformed_or_different_stake_is_not_rounded_into_replay(string $stake): void
    {
        $f = $this->purchaseFixture();
        $before = $this->state();
        $this->refused(fn () => app(BetPurchaseService::class)->purchase($this->command($f, $stake)));
        $this->assertSame($before, $this->state());
    }

    public function test_decimal_equivalence_does_not_reapply_current_sales_gates(): void
    {
        $f = $this->purchaseFixture();
        $f['draw']->forceFill(['betting_close_at' => now()->subDay()])->save();
        $before = $this->state();
        foreach (['10', '10.0', '0010.00'] as $stake) {
            $this->assertTrue(app(BetPurchaseService::class)->purchase($this->command($f, $stake))->isReplay());
        }
        $this->assertSame($before, $this->state());
    }

    public static function corruptions(): array
    {
        return array_map(fn ($x) => [$x], ['missing_receipt', 'missing_owner', 'receipt_hash', 'receipt_key', 'ticket_uuid', 'ticket_number',
            'ticket_user', 'ticket_draw', 'ticket_total', 'ticket_metadata', 'ticket_confirmed', 'ticket_deleted', 'extra_item', 'deleted_item',
            'original_amount', 'original_key', 'original_deleted', 'journal_amount', 'journal_deleted', 'journal_extra', 'ownership_hash', 'risk_binding', 'bet_deleted']);
    }

    #[DataProvider('corruptions')]
    public function test_partial_historical_or_corrupted_receipt_never_replays_repairs_or_charges(string $case): void
    {
        $f = $this->purchaseFixture();
        $p = $f['claim'];
        switch ($case) {
            case 'missing_receipt': DB::table('bet_purchase_receipts')->delete();
                break;
            case 'missing_owner': DB::table('bet_purchase_receipts')->delete();
                DB::table('bet_purchase_ownerships')->delete();
                break;
            case 'receipt_hash': DB::table('bet_purchase_receipts')->update(['receipt_hash' => str_repeat('0', 64)]);
                break;
            case 'receipt_key': DB::table('bet_purchase_receipts')->update(['original_financial_key' => 'changed-original-key']);
                break;
            case 'ticket_uuid': DB::table('tickets')->update(['uuid' => (string) Str::uuid()]);
                break;
            case 'ticket_number': DB::table('tickets')->update(['ticket_number' => 'CHANGED-RECEIPT']);
                break;
            case 'ticket_user': DB::table('tickets')->update(['user_id' => User::factory()->create()->id]);
                break;
            case 'ticket_draw': DB::table('tickets')->update(['draw_id' => Draw::factory()->create()->id]);
                break;
            case 'ticket_total': DB::table('tickets')->update(['total_amount' => '11.00']);
                break;
            case 'ticket_metadata': DB::table('tickets')->update(['metadata' => json_encode(['number' => '008'])]);
                break;
            case 'ticket_confirmed': DB::table('tickets')->update(['confirmed_at' => null]);
                break;
            case 'ticket_deleted': DB::table('tickets')->update(['deleted_at' => now()]);
                break;
            case 'extra_item': $item = $f['result']->item->replicate();
                $item->save();
                break;
            case 'deleted_item': DB::table('bet_items')->update(['deleted_at' => now()]);
                break;
            case 'original_amount': DB::table('financial_transactions')->where('id', $p->original_transaction_id)->update(['amount' => '11.00']);
                break;
            case 'original_key': DB::table('financial_transactions')->where('id', $p->original_transaction_id)->update(['idempotency_key' => 'changed-original-key']);
                break;
            case 'original_deleted': DB::table('financial_transactions')->where('id', $p->original_transaction_id)->update(['deleted_at' => now()]);
                break;
            case 'journal_amount': DB::table('ledger_entries')->where('financial_transaction_id', $p->original_transaction_id)->update(['amount' => '11.00']);
                break;
            case 'journal_deleted': DB::table('ledger_entries')->where('financial_transaction_id', $p->original_transaction_id)->limit(1)->update(['deleted_at' => now()]);
                break;
            case 'journal_extra': $entry = $f['result']->transaction->ledgerEntries()->first()->replicate();
                $entry->type = LedgerEntryType::Credit;
                $entry->save();
                break;
            case 'ownership_hash': DB::table('bet_purchase_ownerships')->update(['terms_hash' => str_repeat('0', 64)]);
                break;
            case 'risk_binding': $limit = NumberLimit::whereKey($p->number_limit_id)->firstOrFail()->replicate();
                $limit->number = '008';
                $limit->save();
                DB::table('bet_purchase_ownerships')->update(['number_limit_id' => $limit->id]);
                break;
            case 'bet_deleted': DB::table('bets')->update(['deleted_at' => now()]);
                break;
        }
        $before = $this->state();
        $this->refused(fn () => app(BetPurchaseService::class)->purchase($this->command($f)));
        $this->refused(fn () => app(BetPurchaseService::class)->existingPurchaseFor($this->command($f)));
        $this->refused(fn () => app(BetPurchaseIdempotencyService::class)->replayResultFor($f['bet']));
        $this->assertSame($before, $this->state());
    }

    public function test_ambient_replay_and_late_receipt_insert_failure_cannot_hide_a_partial_commit(): void
    {
        $f = $this->purchaseFixture();
        $before = $this->state();
        $this->refused(fn () => DB::transaction(fn () => app(BetPurchaseService::class)->purchase($this->command($f))));
        $this->refused(fn () => DB::transaction(fn () => app(BetPurchaseService::class)->existingPurchaseFor($this->command($f))));
        $this->assertSame($before, $this->state());
        $armed = true;
        DB::listen(static function ($query) use (&$armed): void {
            if ($armed && preg_match('/\Ainsert into [`"]?bet_purchase_receipts[`"]?/', strtolower($query->sql))) {
                $armed = false;
                throw new RuntimeException('Synthetic late receipt failure.');
            }
        });
        try {
            app(BetPurchaseService::class)->purchase(new BetPurchaseData($f['user']->id, $f['draw']->id, '3d_direct', '007', '10.00', 'second-purchase-late-failure'));
            $this->fail('Late failure expected.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic late receipt failure.', $error->getMessage());
        } finally {
            $armed = false;
        }
        $this->assertSame($before, $this->state());
    }

    public function test_loaded_purchase_cannot_mint_a_receipt_and_populated_schema_cannot_be_erased(): void
    {
        $f = $this->purchaseFixture();
        $before = $this->state();
        $this->refused(fn () => DB::transaction(fn () => app(BetPurchaseReceipt::class)->record($f['bet']->fresh(), $f['wallet']->fresh(), $f['result']->transaction->fresh(), $f['result']->ticket->fresh())));
        $migration = require base_path('database/migrations/2026_10_17_000200_create_bet_purchase_receipts_table.php');
        try {
            $migration->down();
            $this->fail('Owned receipts must survive rollback.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Refusing to erase', $error->getMessage());
        }
        $this->assertSame($before, $this->state());
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Owned funding proof should refuse.');
        } catch (BetDomainException $error) {
            $this->assertNotEmpty($error->errorCode());
        }
    }
}
