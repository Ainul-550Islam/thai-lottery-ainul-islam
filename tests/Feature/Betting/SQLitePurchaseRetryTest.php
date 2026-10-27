<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\Currency;
use App\Exceptions\BetPurchaseConcurrencyException;
use App\Exceptions\BetPurchaseException;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\NumberLimit;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Betting\BetPurchaseService;
use App\Services\Finance\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Finance\FinancialMasterTestCase;

/** Fault-injected SQLite rollback/retry contracts, separate from server row locks. */
final class SQLitePurchaseRetryTest extends FinancialMasterTestCase
{
    public static function busyCodes(): array
    {
        return ['busy' => [5], 'locked' => [6], 'busy snapshot' => [517]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This suite specifically exercises SQLite driver-code retry handling.');
        }
        Config::set('finance.locking.retry_delay_milliseconds', 1);
    }

    #[DataProvider('busyCodes')]
    public function test_busy_after_capacity_and_bet_write_rolls_back_then_retries_once(int $code): void
    {
        $f = $this->purchaseFixture();
        $attempts = 0;
        $original = Bet::getEventDispatcher();
        Bet::setEventDispatcher(clone $original);
        Bet::created(function () use (&$attempts, $code): void {
            $attempts++;
            if ($attempts === 1) {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $this->assertSame('10.00', (string) NumberLimit::query()->sole()->current_amount);
                $this->assertSame(1, Bet::count());
                throw $this->driverError($code);
            }
        });
        try {
            $result = app(BetPurchaseService::class)->purchase($f['data']);
        } finally {
            Bet::setEventDispatcher($original);
        }
        $this->assertSame(2, $attempts);
        $this->assertSame('purchased', $result->status->value);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(1, Bet::count());
        $this->assertSame(1, BetItem::count());
        $this->assertSame(1, Ticket::count());
        $this->assertSame(2, FinancialTransaction::count()); // Fixture credit + single purchase debit.
        $this->assertSame(4, LedgerEntry::count());
        $this->assertSame('90.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame('10.00', (string) NumberLimit::query()->sole()->current_amount);
    }

    public function test_exhausted_busy_budget_is_a_named_refusal_with_no_partial_purchase(): void
    {
        Config::set('finance.locking.max_retries', 2);
        $f = $this->purchaseFixture();
        $attempts = 0;
        $original = Bet::getEventDispatcher();
        Bet::setEventDispatcher(clone $original);
        Bet::created(function () use (&$attempts): void {
            $attempts++;
            throw $this->driverError(5);
        });
        try {
            try {
                app(BetPurchaseService::class)->purchase($f['data']);
                $this->fail('An exhausted busy budget must refuse, never report success.');
            } catch (BetPurchaseConcurrencyException $error) {
                $this->assertSame('bet_purchase_deadlock', $error->errorCode());
                $this->assertInstanceOf(QueryException::class, $error->getPrevious());
            }
        } finally {
            Bet::setEventDispatcher($original);
        }
        $this->assertSame(2, $attempts);
        $this->assertRolledBack($f);
    }

    public function test_nonbusy_database_error_is_not_retried_or_swallowed(): void
    {
        $f = $this->purchaseFixture();
        $attempts = 0;
        $original = Bet::getEventDispatcher();
        Bet::setEventDispatcher(clone $original);
        Bet::created(function () use (&$attempts): void {
            $attempts++;
            throw $this->driverError(1);
        });
        try {
            try {
                app(BetPurchaseService::class)->purchase($f['data']);
                $this->fail('A nonbusy database failure must propagate.');
            } catch (QueryException $error) {
                $this->assertSame(1, $error->errorInfo[1]);
            }
        } finally {
            Bet::setEventDispatcher($original);
        }
        $this->assertSame(1, $attempts);
        $this->assertRolledBack($f);
    }

    public function test_caller_owned_transaction_is_still_refused(): void
    {
        $f = $this->purchaseFixture();
        DB::beginTransaction();
        try {
            try {
                app(BetPurchaseService::class)->purchase($f['data']);
                $this->fail('A caller-owned transaction must not enter the retry loop.');
            } catch (BetPurchaseException $error) {
                $this->assertSame('bet_purchase_invariant_violated', $error->errorCode());
                $this->assertSame(1, DB::transactionLevel());
            }
        } finally {
            DB::rollBack();
        }
        $this->assertRolledBack($f);
    }

    private function purchaseFixture(): array
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '100.00', 'SQLite retry fixture');
        $selection = new BulkBetSelectionData(marketKey: '3d_direct', number: '007', stake: '10.00');
        $draw = $this->openBulkDrawForTest([$selection]);
        $data = new BetPurchaseData(userId: $user->id, drawId: $draw->id, marketKey: '3d_direct', rawNumber: '007', rawStake: '10.00', idempotencyKey: 'sqlite-retry-boundary');

        return compact('wallet', 'data');
    }

    private function driverError(int $code): QueryException
    {
        $previous = new PDOException('Injected SQLite fault');
        $previous->errorInfo = ['HY000', $code, 'Injected SQLite fault'];

        return new QueryException('sqlite', 'injected after bet insertion', [], $previous);
    }

    private function assertRolledBack(array $f): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->assertSame(0, Bet::count());
        $this->assertSame(0, BetItem::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(1, FinancialTransaction::count());
        $this->assertSame(2, LedgerEntry::count());
        $this->assertSame('100.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', (string) NumberLimit::query()->sole()->current_amount);
    }
}
