<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

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
use App\Models\Wallet;
use App\Services\Betting\BetPurchaseService;
use App\Services\Betting\BetPurchaseTransactionService;
use App\Services\Betting\BetPurchaseValidator;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\ResponsibleGaming\SelfExclusionService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class PlayerWagerAdmissionServerTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;
    use RunsServerFinancialRaces;

    private function limit(array $f, ResponsibleGamingLimitType $type, string $amount): void
    {
        app(ResponsibleGamingLimitService::class)->pronounce(ResponsibleGamingLimitData::fromInput([
            'user_id' => $f['user']->id, 'limit_type' => $type->value, 'amount' => $amount, 'currency' => 'THB',
        ]));
    }

    private function secondWallet(array $f): Wallet
    {
        $wallet = Wallet::factory()->create(['user_id' => $f['user']->id, 'currency' => Currency::THB, 'type' => WalletType::Bonus]);
        $this->creditFixture($wallet, '100.00', 'Other wager wallet');

        return $wallet;
    }

    public function test_distinct_wallets_wait_on_the_player_and_cannot_jointly_exceed_daily_wagering(): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $other = $this->secondWallet($f);
        $this->assertNotSame($f['wallet']->id, $other->id);
        $this->limit($f, ResponsibleGamingLimitType::DailyWagering, '25.00');
        $base = ['action' => 'wager_context', 'user_id' => $f['user']->id, 'draw_id' => $f['draw']->id];
        $results = $this->serverRace([$base + ['wallet_id' => $f['wallet']->id, 'key' => 'wager-wallet-one'],
            $base + ['wallet_id' => $other->id, 'key' => 'wager-wallet-two']], 'users', $f['user']->id);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame(['purchased', 'refused'], $outcomes, json_encode($results));
        $refused = array_values(array_filter($results, fn ($r): bool => $r['outcome'] === 'refused'))[0];
        $this->assertSame('RGL_INVALID_AMOUNT', $refused['code']);
        $this->assertSame('180.00', bcadd($f['wallet']->fresh()->balance, $other->fresh()->balance, 2));
        $this->assertSame(2, DB::table('bets')->count());
        $this->assertSame(2, DB::table('bet_purchase_receipts')->count());
        $this->assertSame('20.00', DB::table('number_limits')->where('id', $f['claim']->number_limit_id)->value('current_amount'));
    }

    public static function policies(): array
    {
        return [['limit'], ['exclude']];
    }

    #[DataProvider('policies')]
    public function test_policy_writer_and_new_purchase_contend_on_the_same_decisive_player_row(string $policy): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $writer = ['action' => 'wager_policy', 'policy' => $policy, 'user_id' => $f['user']->id];
        if ($policy === 'exclude') {
            $row = app(SelfExclusionService::class)->request(new SelfExclusionData(userId: $f['user']->id,
                durationDays: 7, reason: 'protection'));
            $writer['exclusion_id'] = $row->id;
        }
        $buyer = ['action' => 'wager_context', 'user_id' => $f['user']->id, 'draw_id' => $f['draw']->id,
            'wallet_id' => $f['wallet']->id, 'key' => 'wager-policy-race'];
        $results = $this->serverRace([$buyer, $writer], 'users', $f['user']->id);
        $this->assertSame('policy_changed', $results[1]['outcome'], json_encode($results));
        $this->assertContains($results[0]['outcome'], ['purchased', 'refused']);
        $charged = $results[0]['outcome'] === 'purchased';
        if (! $charged) {
            $this->assertSame($policy === 'limit' ? 'RGL_INVALID_AMOUNT' : 'SE_ACTIVE_EXCLUSION', $results[0]['code']);
        }
        $this->assertSame($charged ? '80.00' : '90.00', $f['wallet']->fresh()->balance);
        $this->assertSame($charged ? 2 : 1, DB::table('bet_purchase_receipts')->count());
        // After either ordering, this is NEW risk and the now-current policy must refuse it.
        try {
            app(BetPurchaseService::class)->purchase(new BetPurchaseData($f['user']->id,
                $f['draw']->id, '3d_direct', '007', '10.00', 'after-policy-race'));
            $this->fail('A committed protection policy did not bind a later wager.');
        } catch (ResponsibleGamingLimitException|SelfExclusionException|BetPurchaseException $e) {
            $this->assertContains($e::class, [ResponsibleGamingLimitException::class, SelfExclusionException::class, BetPurchaseException::class]);
        }
        $this->assertSame($charged ? '80.00' : '90.00', $f['wallet']->fresh()->balance);
    }

    public static function stalePolicies(): array
    {
        return array_map(fn ($s): array => [$s], ['account', 'legacy', 'cool', 'new', 'single_bet', 'daily_wagering', 'daily_loss']);
    }

    #[DataProvider('stalePolicies')]
    public function test_real_purchase_rechecks_current_policy_and_ledger_despite_an_older_repeatable_read_snapshot(string $policy): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $other = $this->secondWallet($f);
        $context = app(BetPurchaseValidator::class)->validate(new BetPurchaseData($f['user']->id, $f['draw']->id,
            '3d_direct', '007', '10.00', 'stale-policy-purchase'));
        if ($policy === 'daily_loss') {
            $this->limit($f, ResponsibleGamingLimitType::DailyLoss, '25.00');
        }
        $name = DB::getDefaultConnection();
        $peerName = 'wager_admission_peer';
        config(['database.connections.'.$peerName => config('database.connections.'.$name)]);
        DB::purge($peerName);
        $reader = DB::connection($name);
        $peer = DB::connection($peerName);
        $reader->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $mode = null;
        if (str_contains(strtolower($reader->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $mode = (int) $reader->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $reader->statement('SET SESSION innodb_snapshot_isolation = OFF');
            $peer->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $this->assertNotSame($reader->selectOne('SELECT CONNECTION_ID() AS id')->id, $peer->selectOne('SELECT CONNECTION_ID() AS id')->id);
        $armed = true;
        $observed = false;
        DB::listen(function (QueryExecuted $q) use (&$armed, &$observed, $policy, $f, $other, $reader, $peer, $name, $peerName): void {
            if (! $armed || ! str_contains($q->sql, 'from `wallets`') || ! str_contains($q->sql, 'for update')) {
                return;
            }
            $armed = false;
            $this->assertSame(1, $reader->transactionLevel());
            $before = [$reader->table('users')->where('id', $f['user']->id)->value('status'),
                $reader->table('responsible_gaming_limits')->count(), $reader->table('responsible_gaming_limit_versions')->count(),
                $reader->table('self_exclusions')->count(), $reader->table('financial_transactions')->count()];
            DB::setDefaultConnection($peerName);
            try {
                match ($policy) {
                    'account' => $peer->table('users')->where('id', $f['user']->id)->update(['status' => 'banned']),
                    'legacy', 'cool' => ResponsibleGamingLimit::query()->create(['user_id' => $f['user']->id,
                        $policy === 'cool' ? 'cool_off_until' : 'self_excluded_until' => now()->addDay()]),
                    'new' => app(SelfExclusionService::class)->activate(app(SelfExclusionService::class)->request(
                        new SelfExclusionData(userId: $f['user']->id, durationDays: 7, reason: 'protection'))),
                    'single_bet' => $this->limit($f, ResponsibleGamingLimitType::SingleBet, '5.00'),
                    'daily_wagering' => $this->limit($f, ResponsibleGamingLimitType::DailyWagering, '15.00'),
                    'daily_loss' => app(WalletService::class)->debit($other->fresh(), Money::of('12.00', Currency::THB),
                        FinancialTransactionType::BetDebit, idempotencyKey: 'peer-second-wallet-loss'),
                };
            } finally {
                DB::setDefaultConnection($name);
            }
            $this->assertSame($before, [$reader->table('users')->where('id', $f['user']->id)->value('status'),
                $reader->table('responsible_gaming_limits')->count(), $reader->table('responsible_gaming_limit_versions')->count(),
                $reader->table('self_exclusions')->count(), $reader->table('financial_transactions')->count()],
                'The root ordinary snapshot must ACTUALLY remain old, not just a cached model.');
            $observed = true;
        });
        try {
            try {
                app(BetPurchaseTransactionService::class)->execute($context);
                $this->fail('Old ordinary reads granted a new paid wager.');
            } catch (BetPurchaseException|ResponsibleGamingLimitException|SelfExclusionException $e) {
                $this->assertSame(match ($policy) {
                    'account' => BetPurchaseException::class,
                    'legacy', 'cool', 'new' => SelfExclusionException::class,
                    default => ResponsibleGamingLimitException::class,
                }, $e::class);
            }
            $this->assertTrue($observed);
            $this->assertSame(0, $reader->transactionLevel());
            $this->assertSame('90.00', $f['wallet']->fresh()->balance);
            $this->assertSame(1, DB::table('bets')->count());
            $this->assertSame(1, DB::table('bet_purchase_receipts')->count());
            $this->assertSame('10.00', DB::table('number_limits')->where('id', $f['claim']->number_limit_id)->value('current_amount'));
        } finally {
            $armed = false;
            DB::setDefaultConnection($name);
            if ($mode !== null) {
                $reader->statement('SET SESSION innodb_snapshot_isolation = '.($mode ? 'ON' : 'OFF'));
            }
            DB::purge($peerName);
        }
    }
}
