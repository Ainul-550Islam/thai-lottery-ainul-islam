<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\Models\Wallet;
use App\Services\Wallet\Conversion\ConversionWalletEligibility;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConversionWalletEligibilityFixture;

final class ConversionWalletEligibilityTest extends ConversionTestCase
{
    use ConversionWalletEligibilityFixture;

    public static function blockedProfiles(): array
    {
        return [['source-status', 'wallet_locked'], ['destination-status', 'wallet_locked'],
            ['source-lock', 'wallet_locked'], ['destination-lock', 'wallet_locked'],
            ['funds-spent', 'insufficient_balance'], ['funds-held', 'insufficient_balance'],
            ['source-deleted', 'wallet_locked'], ['destination-deleted', 'wallet_locked']];
    }

    #[DataProvider('blockedProfiles')]
    public function test_current_wallet_refusal_blocks_quote_and_money_without_consuming_original_offer(string $profile, string $code): void
    {
        $f = $this->slotFixture();
        $request = $this->slotRequest($f);
        $quote = $this->service()->quote($request);
        $this->walletChange($f, $profile);
        $before = $this->slotPaper();
        $this->slotRefuses(fn () => $this->service()->quote($request), $code);
        $this->slotRefuses(fn () => $this->service()->convert($request->withQuoteReference($quote->reference)), $code);
        $this->assertSame($before, $this->slotPaper());
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public static function statusProfiles(): array
    {
        $rows = [];
        foreach (['active', 'suspended', 'pending', 'locked', 'frozen', 'closed', 'invalid_status'] as $status) {
            $rows[] = ['cash', $status, $status === 'active'];
            $rows[] = ['win', $status, in_array($status, ['active', 'suspended'], true)];
        }

        return $rows;
    }

    #[DataProvider('statusProfiles')]
    public function test_raw_status_preserves_debit_and_credit_semantics_without_enum_cast_failures(string $role, string $status, bool $allowed): void
    {
        $f = $this->slotFixture();
        DB::table('wallets')->where('id', $f[$role]->id)->update(['status' => $status]);
        if ($allowed) {
            $this->assertNotEmpty($this->service()->quote($this->slotRequest($f))->reference);
        } else {
            $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'wallet_locked');
        }
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public static function transactionProfiles(): array
    {
        return [['none'], ['raw-pdo-only'], ['stale-framework-counter']];
    }

    #[DataProvider('transactionProfiles')]
    public function test_decisive_pair_requires_actual_and_framework_transactions(string $profile): void
    {
        $f = $this->slotFixture();
        if ($profile === 'raw-pdo-only') {
            DB::connection()->getPdo()->beginTransaction();
        } elseif ($profile === 'stale-framework-counter') {
            DB::beginTransaction();
            DB::connection()->getPdo()->commit();
        }
        try {
            $this->slotRefuses(fn () => app(ConversionWalletEligibility::class)->pairForExecution($this->slotRequest($f)), 'transaction_required');
            if ($profile === 'stale-framework-counter') {
                $this->slotRefuses(fn () => app(ConversionWalletEligibility::class)->assertAllowed($this->slotRequest($f)), 'transaction_required');
            }
        } finally {
            if (DB::connection()->getPdo()->inTransaction()) {
                DB::connection()->getPdo()->rollBack();
            }
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_advisory_is_nonlocking_and_current_pair_is_owner_first_and_id_ordered(): void
    {
        $f = $this->slotFixture();
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            if (str_starts_with(strtolower($q->sql), 'select')) {
                $queries[] = strtolower($q->sql);
            }
        });
        app(ConversionWalletEligibility::class)->assertAllowed($this->slotRequest($f));
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsString('for update', $queries[0]);
        $this->assertSame(0, DB::transactionLevel());
        $queries = [];
        $pair = DB::transaction(fn () => app(ConversionWalletEligibility::class)->pairForExecution($this->slotRequest($f)));
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('users', $queries[0]);
        $this->assertStringContainsString('wallets', $queries[1]);
        $this->assertMatchesRegularExpression('/order by [`"]?id[`"]? asc/', $queries[1]);
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertStringContainsString('for update', $queries[0]);
            $this->assertStringContainsString('for update', $queries[1]);
        }
        $this->assertSame($f['cash']->id, $pair['source']->id);
        $this->assertSame($f['win']->id, $pair['destination']->id);
    }

    public function test_policy_evaluate_remains_nonlocking_for_wallets_even_inside_a_managed_transaction(): void
    {
        $f = $this->slotFixture();
        $walletQueries = [];
        DB::listen(function ($event) use (&$walletQueries): void {
            if (str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, 'wallets')) {
                $walletQueries[] = strtolower($event->sql);
            }
        });
        $decision = DB::transaction(fn () => app(ConversionPolicyContract::class)->evaluate($this->slotRequest($f)));
        $this->assertTrue($decision->allowed);
        $this->assertCount(1, $walletQueries);
        $this->assertStringNotContainsString('for update', $walletQueries[0]);
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_missing_destination_is_created_only_by_execution_not_quote_or_advisory(): void
    {
        $f = $this->slotFixture();
        $f['win']->forceDelete();
        $request = $this->slotRequest($f);
        app(ConversionWalletEligibility::class)->assertAllowed($request);
        $quote = $this->service()->quote($request);
        $this->assertSame(1, Wallet::withTrashed()->where('user_id', $f['user']->id)->count());
        $result = $this->service()->convert($request->withQuoteReference($quote->reference));
        $this->assertFalse($result->replayed);
        $this->assertSame(2, Wallet::withTrashed()->where('user_id', $f['user']->id)->count());
        $this->assertTrue($this->ledgerIsBalanced()['balanced']);
    }

    public function test_stale_model_and_cached_available_balance_are_not_new_authority(): void
    {
        $f = $this->slotFixture();
        $this->assertSame('1000.00', $f['cash']->available_balance);
        $this->walletChange($f, 'funds-spent');
        $this->assertSame('1000.00', $f['cash']->available_balance);
        $this->slotRefuses(fn () => app(ConversionWalletEligibility::class)->assertAllowed($this->slotRequest($f)), 'insufficient_balance');
    }

    public function test_exact_available_boundary_uses_balance_minus_holds_not_total_or_floats(): void
    {
        $f = $this->slotFixture();
        $f['cash']->forceFill(['locked_balance' => '970.00'])->save();
        $request = $this->slotRequest($f);
        $this->assertNotEmpty($this->service()->quote($request)->reference);
        $f['cash']->forceFill(['locked_balance' => '970.01'])->save();
        $this->slotRefuses(fn () => $this->service()->quote($request), 'insufficient_balance');
        $this->assertSame(0, DB::table('financial_transactions')->count());
    }

    public function test_missing_source_cannot_be_replaced_by_another_owners_wallet(): void
    {
        $f = $this->slotFixture();
        $other = $this->slotFixture();
        $f['cash']->forceDelete();
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'wallet_locked');
        $this->assertSame('1000.00', $other['cash']->fresh()->balance);
    }

    public function test_exact_original_replay_does_not_require_new_wallet_eligibility_or_post_again(): void
    {
        $f = $this->slotFixture();
        $request = $this->slotRequest($f);
        $request = $request->withQuoteReference($this->service()->quote($request)->reference);
        $original = $this->service()->convert($request);
        $this->walletChange($f, 'exact-replay');
        $before = $this->slotPaper();
        $replay = $this->service()->convert($request);
        $this->assertTrue($replay->replayed);
        $this->assertSame($original->reference, $replay->reference);
        $this->assertSame($before, $this->slotPaper());
    }
}
