<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionStatus;
use App\Exceptions\Wallet\ConversionException;
use App\Models\WalletConversion;
use App\Services\Wallet\Conversion\ConversionIntegrityGuard;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConversionOriginalProofTest extends ConversionTestCase
{
    private const KEY = 'original-proof-conversion-key-0001';

    private const REVERSE_KEY = 'original-proof-reversal-key-0001';

    private const REASON = 'Controlled original conversion correction.';

    private function original(): array
    {
        $f = $this->makeFundedMember();
        $quote = $this->quoteFor($f['user']);
        $request = $this->request($f['user'], idempotencyKey: self::KEY, quoteReference: $quote->reference);
        $result = $this->service()->convert($request);
        $row = WalletConversion::query()->where('reference', $result->reference)->sole();
        $operator = $this->makeOperator();

        return $f + ['request' => $request, 'row' => $row,
            'context' => new ConversionAuditContextData(WalletConversionChannel::Admin, $operator->id)];
    }

    private function paper(): array
    {
        $paper = [];
        foreach (['wallets', 'wallet_conversions', 'wallet_conversion_quotes', 'financial_transactions', 'ledger_entries', 'ledger_accounts'] as $table) {
            $paper[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $paper;
    }

    private function refuses(callable $action, string $code): void
    {
        try {
            $action();
            $this->fail('Unproved accounting must never authorize a result or movement.');
        } catch (ConversionException $error) {
            $this->assertSame($code, $error->errorCode());
            $this->assertSame(422, $error->httpStatus());
        }
    }

    public static function damage(): array
    {
        $cases = [];
        foreach (['user_id' => 'operator', 'wallet_id' => null, 'currency' => 'USD', 'type' => 'deposit',
            'amount' => '101.00', 'fee' => '2.00', 'idempotency_key' => 'unrelated-key',
            'reference_type' => 'unrelated', 'reference_id' => 999999, 'description' => 'unrelated',
            'metadata' => '{}', 'processed_at' => null, 'deleted_at' => '2026-10-19 00:00:00',
            'status' => 'processing'] as $field => $value) {
            $cases['transaction-'.$field] = ['financial_transactions', $field, $value, 'transaction'];
        }
        foreach (['amount' => '98.00', 'currency' => 'USD', 'posted_at' => null,
            'deleted_at' => '2026-10-19 00:00:00', 'reference_type' => 'unrelated',
            'reference_id' => 999999, 'balance_after' => '98.00'] as $field => $value) {
            $cases['wallet-leg-'.$field] = ['ledger_entries', $field, $value, 'credit'];
        }
        $cases['fee-side'] = ['ledger_entries', 'type', 'debit', 'fee'];
        $cases['fee-wallet-is-not-null'] = ['ledger_entries', 'wallet_id', 'cash', 'fee'];
        $cases['fee-snapshot-is-not-null'] = ['ledger_entries', 'balance_after', '1.00', 'fee'];
        foreach (['code' => 'unrelated', 'currency' => 'USD', 'type' => 'asset',
            'is_active' => false, 'deleted_at' => '2026-10-19 00:00:00'] as $field => $value) {
            $cases['chart-'.$field] = ['ledger_accounts', $field, $value, 'account'];
        }
        foreach (['from_balance_before' => '1001.00', 'to_balance_after' => '100.00',
            'from_wallet_type' => 'win', 'completed_at' => null, 'status' => 'invented'] as $field => $value) {
            $cases['conversion-'.$field] = ['wallet_conversions', $field, $value, 'conversion'];
        }
        $cases['deleted-surplus-leg'] = ['ledger_entries', 'surplus', null, 'credit'];
        $cases['missing-leg'] = ['ledger_entries', 'missing', null, 'credit'];

        return $cases;
    }

    #[DataProvider('damage')]
    public function test_independent_source_corruption_refuses_replay_and_authorized_reversal_without_repair(string $table, string $field, mixed $value, string $target): void
    {
        $f = $this->original();
        $row = $f['row'];
        $credit = DB::table('ledger_entries')->where('financial_transaction_id', $row->financial_transaction_id)->where('wallet_id', $f['win']->id)->sole();
        $id = match ($target) {
            'transaction' => $row->financial_transaction_id,
            'conversion' => $row->id,
            'credit' => $credit->id,
            'account' => $credit->ledger_account_id,
            'fee' => DB::table('ledger_entries')->where('financial_transaction_id', $row->financial_transaction_id)->whereNull('wallet_id')->sole()->id,
        };
        if ($field === 'missing') {
            DB::table($table)->where('id', $id)->delete();
        } elseif ($field === 'surplus') {
            $extra = (array) $credit;
            unset($extra['id']);
            $extra['deleted_at'] = now();
            $extra['ledger_account_id'] = DB::table('ledger_accounts')->where('code', '1000')->value('id');
            DB::table($table)->insert($extra);
        } else {
            DB::table($table)->where('id', $id)->update([$field => match ($value) {
                'cash' => $f['cash']->id, 'operator' => $f['context']->actorUserId, default => $value
            }]);
        }
        $before = $this->paper();
        $this->refuses(fn () => $this->service()->convert($f['request']), 'conversion_original_unproven');
        $this->assertSame($before, $this->paper());
        $this->refuses(fn () => $this->service()->reverse($row->id, $f['context'], self::REASON, self::REVERSE_KEY), 'reversal_unavailable');
        $this->assertSame($before, $this->paper());
    }

    public function test_proved_history_replays_after_later_movement_wallet_lock_and_policy_change(): void
    {
        $f = $this->original();
        $this->convert($f['user'], amount: '10.00', idempotencyKey: 'later-legitimate-conversion-0001');
        DB::table('wallets')->where('id', $f['cash']->id)->update(['locked_at' => now()]);
        config(['conversions.enabled' => false, 'conversions.require_kyc' => true]);
        $before = $this->paper();
        $result = $this->service()->convert($f['request']);
        $this->assertTrue($result->replayed);
        $this->assertSame($f['row']->reference, $result->reference);
        $this->assertSame($before, $this->paper());
    }

    public static function linkDamage(): array
    {
        return [['parent-marker'], ['child-reason'], ['child-input'], ['child-fee'], ['child-actor'], ['child-transaction'], ['hidden-child-leg'], ['completed-parent-with-child']];
    }

    #[DataProvider('linkDamage')]
    public function test_both_replay_paths_require_one_reciprocal_proved_inverse(string $damage): void
    {
        $f = $this->original();
        $result = $this->service()->reverse($f['row']->id, $f['context'], self::REASON, self::REVERSE_KEY);
        $child = WalletConversion::query()->where('reference', $result->reference)->sole();
        $before = $this->paper();
        $this->assertTrue($this->service()->convert($f['request'])->replayed);
        $this->assertTrue($this->service()->reverse($f['row']->id, $f['context'], self::REASON, self::REVERSE_KEY)->replayed);
        $this->assertSame($before, $this->paper());
        match ($damage) {
            'parent-marker' => DB::table('wallet_conversions')->where('id', $f['row']->id)->update(['reversed_by_id' => null]),
            'completed-parent-with-child' => DB::table('wallet_conversions')->where('id', $f['row']->id)->update(['status' => 'completed']),
            'child-reason' => DB::table('wallet_conversions')->where('id', $child->id)->update(['reversal_reason' => 'Different operator reason.']),
            'child-input' => DB::table('wallet_conversions')->where('id', $child->id)->update(['amount_in' => '98.00']),
            'child-fee' => DB::table('wallet_conversions')->where('id', $child->id)->update(['fee_amount' => '0.00']),
            'child-actor' => DB::table('wallet_conversions')->where('id', $child->id)->update(['actor_user_id' => null]),
            'child-transaction' => DB::table('financial_transactions')->where('id', $child->financial_transaction_id)->update(['amount' => '98.00']),
            'hidden-child-leg' => DB::table('ledger_entries')->where('financial_transaction_id', $child->financial_transaction_id)->update(['deleted_at' => now()]),
        };
        $before = $this->paper();
        $this->refuses(fn () => $this->service()->convert($f['request']), 'conversion_original_unproven');
        $this->refuses(fn () => $this->service()->reverse($f['row']->id, $f['context'], self::REASON, self::REVERSE_KEY), 'conversion_original_unproven');
        $this->assertSame($before, $this->paper());
    }

    public static function stages(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('stages')]
    public function test_post_movement_proof_failure_rolls_back_even_if_ambient_caller_commits(bool $reverse, bool $ambient): void
    {
        $f = $this->original();
        $quote = $this->quoteFor($f['user'], idempotencyKey: 'post-proof-fault-quote-0001');
        $request = $this->request($f['user'], idempotencyKey: 'post-proof-fault-convert-0001', quoteReference: $quote->reference);
        $before = $this->paper();
        $events = WalletConversion::getEventDispatcher();
        WalletConversion::setEventDispatcher(clone $events);
        WalletConversion::updated(function (WalletConversion $row) use ($reverse): void {
            if ((! $reverse && $row->status === WalletConversionStatus::Completed) || ($reverse && $row->status === WalletConversionStatus::Reversed)) {
                DB::table('ledger_entries')->where('financial_transaction_id', $row->financial_transaction_id)->update(['posted_at' => null]);
            }
        });
        if ($ambient) {
            DB::beginTransaction();
        }
        try {
            $this->refuses($reverse
                ? fn () => $this->service()->reverse($f['row']->id, $f['context'], self::REASON, self::REVERSE_KEY)
                : fn () => $this->service()->convert($request), 'conversion_original_unproven');
            if ($ambient) {
                DB::commit();
            }
        } finally {
            WalletConversion::setEventDispatcher($events);
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame($before, $this->paper());
    }

    public function test_http_replay_returns_safe_422_for_wrong_transaction_amount(): void
    {
        $f = $this->original();
        DB::table('financial_transactions')->where('id', $f['row']->financial_transaction_id)->update(['amount' => '101.00']);
        $before = $this->paper();
        Sanctum::actingAs($f['user']);
        $this->postJson('/api/v1/wallet/conversions', ['direction' => 'cash_to_win', 'amount' => '100.00', 'idempotency_key' => self::KEY, 'quote_reference' => $f['request']->quoteReference])
            ->assertStatus(422)->assertJsonPath('error.code', 'conversion_original_unproven');
        $this->assertSame($before, $this->paper());
    }

    public function test_proof_requires_a_physical_transaction_without_writing(): void
    {
        $f = $this->original();
        $before = $this->paper();
        $this->refuses(fn () => app(ConversionIntegrityGuard::class)->assertSettled($f['row']), 'conversion_original_unproven');
        $this->assertSame($before, $this->paper());
    }
}
