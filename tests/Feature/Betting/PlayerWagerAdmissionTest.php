<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\BetPurchaseContext;
use App\DTOs\BetPurchaseData;
use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\WalletType;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Models\ResponsibleGamingLimit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Betting\BetPurchaseAdmission;
use App\Services\Betting\BetPurchaseService;
use App\Services\Betting\BetPurchaseTransactionService;
use App\Services\Betting\BetPurchaseValidator;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\ResponsibleGaming\ResponsibleGamingPlayerLock;
use App\Services\ResponsibleGaming\SelfExclusionService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;

final class PlayerWagerAdmissionTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;

    private function context(array $f): BetPurchaseContext
    {
        return app(BetPurchaseValidator::class)->validate(new BetPurchaseData($f['user']->id, $f['draw']->id,
            '3d_direct', '007', '10.00', 'new-player-admission'));
    }

    private function state(): array
    {
        $out = [];
        foreach (['users', 'wallets', 'bets', 'bet_items', 'tickets', 'number_limits', 'financial_transactions', 'ledger_entries',
            'bet_purchase_ownerships', 'bet_purchase_receipts', 'bet_commission_ownerships', 'audit_logs',
            'responsible_gaming_limits', 'responsible_gaming_limit_versions', 'self_exclusions'] as $t) {
            $out[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
        }

        return $out;
    }

    private function limit(array $f, ResponsibleGamingLimitType $type, string $amount, ?Currency $currency = null): void
    {
        app(ResponsibleGamingLimitService::class)->pronounce(ResponsibleGamingLimitData::fromInput([
            'user_id' => $f['user']->id, 'limit_type' => $type->value, 'amount' => $amount,
            'currency' => ($currency ?? $f['wallet']->currency)->value,
        ]));
    }

    private function exclude(array $f, string $mode): void
    {
        if ($mode === 'new') {
            $svc = app(SelfExclusionService::class);
            $svc->activate($svc->request(new SelfExclusionData(userId: $f['user']->id, durationDays: 7, reason: 'protection')));
        } else {
            ResponsibleGamingLimit::query()->create(['user_id' => $f['user']->id,
                $mode === 'cool' ? 'cool_off_until' : 'self_excluded_until' => now()->addDay()]);
        }
    }

    private function refuses(callable $call, string $class): void
    {
        $before = $this->state();
        try {
            $call();
            $this->fail('Obsolete permission granted a fresh paid wager.');
        } catch (BetPurchaseException|SelfExclusionException|ResponsibleGamingLimitException $e) {
            $this->assertInstanceOf($class, $e);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->state(), 'No domain row may change on current admission refusal.');
    }

    public static function accounts(): array
    {
        $out = [];
        foreach ([Currency::THB, Currency::USD, Currency::BDT] as $c) {
            foreach (['inactive', 'suspended', 'banned', 'pending_verification', 'deleted'] as $status) {
                $out[$c->value.'-'.$status] = [$c, $status];
            }
        }

        return $out;
    }

    #[DataProvider('accounts')]
    public function test_current_account_refuses_an_earlier_validated_context(Currency $currency, string $status): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $context = $this->context($f);
        $status === 'deleted' ? $f['user']->delete() : $f['user']->forceFill(['status' => $status])->save();
        $this->refuses(fn () => app(BetPurchaseTransactionService::class)->execute($context), BetPurchaseException::class);
    }

    public static function exclusions(): array
    {
        $out = [];
        foreach ([Currency::THB, Currency::USD, Currency::BDT] as $c) {
            foreach (['legacy', 'cool', 'new'] as $mode) {
                $out[$c->value.'-'.$mode] = [$c, $mode];
            }
        }

        return $out;
    }

    #[DataProvider('exclusions')]
    public function test_current_exclusion_or_cool_off_refuses_an_earlier_context(Currency $currency, string $mode): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $context = $this->context($f);
        $this->exclude($f, $mode);
        $this->refuses(fn () => app(BetPurchaseTransactionService::class)->execute($context), SelfExclusionException::class);
    }

    public static function caps(): array
    {
        $out = [];
        foreach ([Currency::THB, Currency::USD, Currency::BDT] as $c) {
            foreach ([ResponsibleGamingLimitType::SingleBet, ResponsibleGamingLimitType::StakeLimit,
                ResponsibleGamingLimitType::DailyWagering, ResponsibleGamingLimitType::DailyLoss,
                ResponsibleGamingLimitType::WeeklyLoss, ResponsibleGamingLimitType::MonthlyLoss] as $type) {
                $out[$c->value.'-'.$type->value] = [$c, $type];
            }
        }

        return $out;
    }

    #[DataProvider('caps')]
    public function test_current_currency_specific_ceiling_refuses_an_earlier_context(Currency $currency, ResponsibleGamingLimitType $type): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $context = $this->context($f);
        $this->limit($f, $type, $type->measure() === 'per_act_stake' ? '5.00' : '15.00');
        $this->refuses(fn () => app(BetPurchaseTransactionService::class)->execute($context), ResponsibleGamingLimitException::class);
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_equal_headroom_is_allowed_and_other_currency_limits_do_not_replace_it(Currency $currency): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $context = $this->context($f);
        $this->limit($f, ResponsibleGamingLimitType::DailyWagering, '20.00');
        $other = $currency === Currency::USD ? Currency::THB : Currency::USD;
        $this->limit($f, ResponsibleGamingLimitType::DailyWagering, '1.00', $other);
        $result = app(BetPurchaseTransactionService::class)->execute($context);
        $this->assertFalse($result->isReplay());
        $this->assertSame('80.00', $f['wallet']->fresh()->balance);
        $this->assertSame(2, DB::table('responsible_gaming_limit_versions')->where('limit_status', 'active')->count());
    }

    #[DataProvider('currencies')]
    public function test_loss_counts_completed_real_debits_on_every_wallet_in_the_selected_currency(Currency $currency): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $second = Wallet::factory()->create(['user_id' => $f['user']->id, 'currency' => $currency, 'type' => WalletType::Bonus]);
        $this->creditFixture($second, '100.00', 'Second owned wallet');
        app(WalletService::class)->debit($second, Money::of('12.00', $currency), FinancialTransactionType::BetDebit,
            idempotencyKey: 'second-wallet-stake');
        $this->assertSame('22.00', app(ResponsibleGamingEnforcementService::class)->rollingLoss($f['user'], 1, $currency->value));
        $context = $this->context($f);
        $this->limit($f, ResponsibleGamingLimitType::DailyLoss, '25.00');
        $this->refuses(fn () => app(BetPurchaseTransactionService::class)->execute($context), ResponsibleGamingLimitException::class);
    }

    public static function replayRestrictions(): array
    {
        return [['account'], ['exclusion'], ['limit']];
    }

    #[DataProvider('replayRestrictions')]
    public function test_original_funding_replay_is_not_new_stake_permission(string $mode): void
    {
        $f = $this->purchaseFixture();
        match ($mode) {
            'account' => $f['user']->forceFill(['status' => 'banned'])->save(),
            'exclusion' => $this->exclude($f, 'new'),
            'limit' => $this->limit($f, ResponsibleGamingLimitType::SingleBet, '1.00'),
        };
        $before = $this->state();
        $result = app(BetPurchaseService::class)->purchase(new BetPurchaseData($f['user']->id, $f['draw']->id,
            '3d_direct', '007', '10.00', 'owned-cancellation-fixture'));
        $this->assertTrue($result->isReplay());
        $this->assertSame($f['result']->transaction->id, $result->transaction->id);
        $this->assertSame($before, $this->state());
    }

    public function test_current_wallet_owner_is_revalidated_before_risk_or_money(): void
    {
        $f = $this->purchaseFixture();
        $context = $this->context($f);
        $other = User::factory()->create();
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['user_id' => $other->id]);
        $this->refuses(fn () => app(BetPurchaseTransactionService::class)->execute($context), BetPurchaseException::class);
    }

    public function test_policy_mutex_and_purchase_admission_cannot_run_without_a_real_transaction(): void
    {
        $f = $this->purchaseFixture();
        $context = $this->context($f);
        foreach ([fn () => app(ResponsibleGamingPlayerLock::class)->lock($f['user']->id),
            fn () => app(BetPurchaseAdmission::class)->assertAllowed($context, $f['wallet'])] as $call) {
            $this->refuses($call, BetPurchaseException::class);
        }
    }

    public function test_expired_exclusions_do_not_authorize_a_new_limit_bypass_or_block_valid_stakes(): void
    {
        $f = $this->purchaseFixture();
        $context = $this->context($f);
        ResponsibleGamingLimit::query()->create(['user_id' => $f['user']->id,
            'self_excluded_until' => now()->subDay(), 'cool_off_until' => now()->subDay()]);
        $this->limit($f, ResponsibleGamingLimitType::SingleBet, '10.00');
        $result = app(BetPurchaseTransactionService::class)->execute($context);
        $this->assertFalse($result->isReplay());
        $this->assertSame('80.00', $f['wallet']->fresh()->balance);
    }

    public function test_soft_hidden_protection_and_completed_funding_are_not_permission_to_stake(): void
    {
        $f = $this->purchaseFixture();
        $context = $this->context($f);
        $row = ResponsibleGamingLimit::query()->create(['user_id' => $f['user']->id, 'cool_off_until' => now()->addDay()]);
        $row->delete();
        $f['result']->transaction->delete();
        $this->assertSame('10.00', app(ResponsibleGamingEnforcementService::class)->rollingLoss($f['user'], 1, 'THB'));
        $this->refuses(fn () => app(BetPurchaseTransactionService::class)->execute($context), SelfExclusionException::class);
    }

    #[DataProvider('currencies')]
    public function test_refund_reduces_loss_but_never_reduces_daily_wagering(Currency $currency): void
    {
        $f = $this->purchaseFixture(currency: $currency);
        $context = $this->context($f);
        app(WalletService::class)->credit($f['wallet'], Money::of('5.00', $currency), FinancialTransactionType::BetRefund,
            idempotencyKey: 'known-completed-refund');
        $this->assertSame('5.00', app(ResponsibleGamingEnforcementService::class)->rollingLoss($f['user'], 1, $currency->value));
        $this->limit($f, ResponsibleGamingLimitType::DailyWagering, '15.00');
        $this->refuses(fn () => app(BetPurchaseTransactionService::class)->execute($context), ResponsibleGamingLimitException::class);
    }
}
