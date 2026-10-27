<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Withdrawal;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\LifecycleBoundaryOperation;

final class FinancialIdentifierRoutingTest extends FinancialMasterTestCase
{
    public static function refusedRoutes(): array
    {
        $rows = [];
        foreach (['credit', 'relock', 'hold', 'release', 'release-if-held', 'consume', 'user-wallet', 'deposit-lock', 'withdrawal-lock', 'transaction-lock', 'mark-failed', 'reverse', 'lock-many'] as $route) {
            foreach (['fraction', 'exponent', 'suffix', 'leading-zero', 'space', 'boolean', 'overflow', 'null'] as $form) {
                $rows[$route.'-'.$form] = [$route, $form];
            }
        }

        return $rows;
    }

    #[DataProvider('refusedRoutes')]
    public function test_malformed_identity_cannot_route_to_another_live_financial_record(string $route, string $form): void
    {
        $wallet = $this->createPlayer('0.00')['wallet'];
        $this->creditFixture($wallet, '500.00', 'canonical identifier funding');
        $transaction = FinancialTransaction::query()->sole();
        $fields = ['wallet_id' => $wallet->id, 'user_id' => $wallet->user_id];
        $deposit = Deposit::factory()->create($fields);
        $withdrawal = Withdrawal::factory()->create($fields);
        $user = $wallet->user;
        $id = (int) $wallet->id;
        $bad = match ($form) {
            'fraction' => $id + 0.75,
            'exponent' => $id.'e0',
            'suffix' => $id.'suffix',
            'leading-zero' => '0'.$id,
            'space' => $id.' ',
            'boolean' => true,
            'overflow' => (string) PHP_INT_MAX.'0',
            'null' => null,
        };
        foreach ([$wallet, $transaction, $deposit, $withdrawal, $user] as $model) {
            $model->id = $bad;
        }
        $memory = array_map(fn ($model) => $model->getAttributes(), [$wallet, $transaction, $deposit, $withdrawal, $user]);
        $paper = LifecycleBoundaryOperation::paper();
        $amount = Money::of('10.00', $wallet->currency);
        $locks = app(WalletLockService::class);
        $holds = app(WalletHoldService::class);
        $states = app(FinancialStateTransitionService::class);
        $db = DB::connection();
        $db->beginTransaction();
        $db->enableQueryLog();
        $db->flushQueryLog();
        try {
            match ($route) {
                'credit' => app(WalletService::class)->credit($wallet, $amount, idempotencyKey: 'must-not-credit-malformed-key'),
                'relock' => $locks->relock($wallet),
                'hold' => $holds->hold($wallet, $amount),
                'release' => $holds->release($wallet, $amount),
                'release-if-held' => $holds->releaseIfHeld($wallet, $amount),
                'consume' => $holds->consume($wallet, $amount),
                'user-wallet' => app(WalletService::class)->getOrCreateWallet($user),
                'deposit-lock' => $states->lockDeposit($deposit),
                'withdrawal-lock' => $states->lockWithdrawal($withdrawal),
                'transaction-lock' => $states->lockFinancialTransaction($transaction),
                'mark-failed' => app(FinancialTransactionService::class)->markFailed($transaction, 'must not touch another row'),
                'reverse' => app(FinancialReversalService::class)->reverse($transaction),
                'lock-many' => $locks->lockMany([$id, $bad]),
            };
            $this->fail('Malformed identity reached a financial route.');
        } catch (FinancialException $exception) {
            $code = match ($route) {
                'lock-many' => 'wallet_identifier_invalid',
                'user-wallet' => 'wallet_user_not_persisted',
                'deposit-lock', 'withdrawal-lock', 'transaction-lock', 'mark-failed' => 'financial_entity_not_persisted',
                'reverse' => 'reversal_transaction_not_persisted',
                default => 'wallet_not_persisted',
            };
            $this->assertSame($code, $exception->errorCode());
            $this->assertSame([], $db->getQueryLog(), 'Validate the entire identity set before routing, locking or idempotency SQL.');
            $this->assertSame(1, $db->transactionLevel());
            $this->assertTrue($db->getPdo()->inTransaction());
            $this->assertSame($memory, array_map(fn ($model) => $model->getAttributes(), [$wallet, $transaction, $deposit, $withdrawal, $user]));
        } finally {
            $db->disableQueryLog();
            $db->rollBack(0);
        }
        $this->assertSame($paper, LifecycleBoundaryOperation::paper());
    }

    public function test_valid_string_model_keys_preserve_credit_replay_holds_and_balanced_posting(): void
    {
        $wallet = $this->createPlayer('0.00')['wallet'];
        $this->creditFixture($wallet, '500.00', 'canonical string funding');
        $wallet->id = (string) $wallet->id;
        $service = app(WalletService::class);
        $amount = Money::of('10.00', $wallet->currency);
        $first = $service->credit($wallet, $amount, idempotencyKey: 'canonical-string-credit');
        $replay = $service->credit($wallet, $amount, idempotencyKey: 'canonical-string-credit');
        $this->assertSame($first->id, $replay->id);
        $this->assertSame('510.00', $wallet->fresh()->balance);
        app(WalletHoldService::class)->hold($wallet, $amount);
        $this->assertSame('10.00', $wallet->fresh()->locked_balance);
        app(WalletHoldService::class)->release($wallet, $amount);
        $this->assertSame('0.00', $wallet->fresh()->locked_balance);
        $this->assertSame(2, FinancialTransaction::query()->count());
        $this->assertSame(4, DB::table('ledger_entries')->count());
        foreach (FinancialTransaction::query()->get() as $row) {
            app(LedgerBalanceValidator::class)->assertTransactionBalanced($row);
        }
        $this->assertSame('510.00', $wallet->fresh()->balance);
    }

    public function test_canonical_many_keys_are_deduplicated_and_sorted_after_validation(): void
    {
        $one = $this->createPlayer('0.00')['wallet'];
        $two = $this->createPlayer('0.00')['wallet'];
        DB::transaction(function () use ($one, $two): void {
            $locked = app(WalletLockService::class)->lockMany([(string) $two->id, $one->id, (string) $one->id]);
            $this->assertSame([$one->id, $two->id], array_keys($locked));
        });
    }
}
