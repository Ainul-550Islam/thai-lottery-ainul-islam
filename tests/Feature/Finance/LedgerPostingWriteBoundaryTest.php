<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use Closure;
use Illuminate\Support\Facades\DB;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/** Synthetic extension callbacks and disposable books, not provider transfers. */
final class LedgerPostingWriteBoundaryTest extends FinancialMasterTestCase
{
    public static function changedContexts(): array
    {
        $rows = [];
        foreach (['raw-commit', 'raw-rollback', 'framework-commit', 'framework-rollback', 'nested-begin', 'pdo-replace', 'default-switch'] as $kind) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                foreach ([1, 2] as $depth) {
                    $rows[$kind.'-'.$currency->value.'-'.($depth === 1 ? 'root' : 'savepoint')] = [$kind, $currency, $depth];
                }
            }
        }

        return $rows;
    }

    private function fixture(Currency $currency): array
    {
        [$tx, $pairs] = LedgerPostingCurrentReadTest::bookFixture();
        DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => $currency->value]);
        DB::table('ledger_accounts')->whereIn('id', array_column(array_merge(...$pairs), 'ledger_account_id'))->update(['currency' => $currency->value]);
        $tx->refresh();

        return [$tx, $pairs[0]];
    }

    private function serializationCallback(Closure $change): JsonSerializable
    {
        return new class($change) implements JsonSerializable
        {
            public int $calls = 0;

            public function __construct(private Closure $change) {}

            public function jsonSerialize(): mixed
            {
                $this->calls++;
                ($this->change)();

                return ['frozen' => true];
            }
        };
    }

    #[DataProvider('changedContexts')]
    public function test_serialization_cannot_change_the_write_context_and_then_post_money(string $kind, Currency $currency, int $depth): void
    {
        [$tx, $draft] = $this->fixture($currency);
        $name = DB::getDefaultConnection();
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $replacement = null;
        if ($kind === 'pdo-replace') {
            config(['database.connections.boundary_replacement' => config('database.connections.'.$name)]);
            $replacement = DB::connection('boundary_replacement')->getPdo();
        }
        $paper = LedgerPostingCurrentReadTest::book();
        $attributes = $tx->getAttributes();
        $marker = User::factory()->create();
        $connection->beginTransaction();
        DB::table('users')->where('id', $marker->id)->update(['name' => 'write boundary caller marker']);
        if ($depth === 2) {
            $connection->beginTransaction();
        }
        $callback = $this->serializationCallback(function () use ($kind, $connection, $pdo, $replacement, $depth) {
            match ($kind) {
                'raw-commit' => $pdo->commit(), 'raw-rollback' => $pdo->rollBack(),
                'framework-commit' => $connection->commit(), 'framework-rollback' => $connection->rollBack(),
                'nested-begin' => $connection->beginTransaction(),
                'default-switch' => DB::setDefaultConnection('unconfigured_callback_writer'),
                default => null,
            };
            if ($kind === 'pdo-replace') {
                $pdo->rollBack();
                $connection->setPdo($replacement);
                for ($i = 0; $i < $depth; $i++) {
                    $connection->beginTransaction();
                }
            }
        });
        $draft[1]['metadata'] = ['callback' => $callback];
        $error = null;
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            app(LedgerPostingService::class)->post($tx, $draft);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();
        // Observe the callback's state BEFORE owner cleanup. The service must not
        // undo, reconnect, repair counters, nest, release or commit on our behalf.
        $actualDepth = $connection->transactionLevel();
        $actualPdo = $connection->getRawPdo();
        $physical = $actualPdo->inTransaction();
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection($name);
        $staged = LedgerPostingCurrentReadTest::book();
        // Explicit test-owner cleanup, not a service guarantee. Raw rollback
        // already discarded earlier caller work; the guard cannot resurrect it.
        while ($connection->transactionLevel() > 0) {
            if ($connection->getRawPdo()->inTransaction()) {
                $connection->commit();
            } else {
                $connection->rollBack(0);
            }
        }
        $markerCommitted = (int) DB::table('users')->where('id', $marker->id)->where('name', 'write boundary caller marker')->exists();
        $diagnostic = json_encode(['exception' => $error === null ? null : $error::class, 'exception_code' => $error?->getCode(), 'committed_journal_rows' => DB::table('ledger_entries')->count(), 'caller_depth_after_callback' => $actualDepth, 'physical_after_callback' => $physical, 'pdo_replaced' => $actualPdo !== $pdo, 'committed_caller_marker_rows' => $markerCommitted], JSON_THROW_ON_ERROR);
        $reason = match ($kind) {
            'default-switch' => 'default_writer_changed', 'pdo-replace' => 'write_pdo_changed',
            'nested-begin', 'framework-commit', 'framework-rollback' => 'framework_depth_changed',
            default => 'physical_transaction_inactive',
        };
        $expectedDepth = match ($kind) {
            'framework-commit', 'framework-rollback' => $depth - 1, 'nested-begin' => $depth + 1, default => $depth,
        };
        $expectedPhysical = ! in_array($kind, ['raw-commit', 'raw-rollback'], true)
            && ! (in_array($kind, ['framework-commit', 'framework-rollback'], true) && $depth === 1);
        $this->assertInstanceOf(FinancialException::class, $error, $diagnostic);
        $this->assertSame('ledger_posting_write_context_changed', $error->errorCode(), $diagnostic);
        $this->assertSame(['stage' => 'payload_preflight', 'reason' => $reason], $error->context());
        $this->assertNull($error->getPrevious());
        $this->assertSame(1, $callback->calls);
        $this->assertSame($expectedDepth, $actualDepth);
        $this->assertSame($expectedPhysical, $physical);
        $this->assertSame($kind === 'pdo-replace', $actualPdo !== $pdo);
        $this->assertSame($kind === 'default-switch' ? 'unconfigured_callback_writer' : $name, $default);
        $this->assertSame($attributes, $tx->getAttributes());
        $this->assertSame($paper, $staged, $diagnostic);
        $this->assertSame($paper, LedgerPostingCurrentReadTest::book(), $diagnostic);
        $rolledBack = in_array($kind, ['raw-rollback', 'pdo-replace'], true) || ($kind === 'framework-rollback' && $depth === 1);
        $this->assertSame($rolledBack ? 0 : 1, $markerCommitted);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getRawPdo()->inTransaction());
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $query['query'], $diagnostic);
        }
    }

    public static function unchangedContexts(): array
    {
        $rows = [];
        foreach (['unchanged', 'balanced-savepoint', 'read-only'] as $kind) {
            foreach ([Currency::THB, Currency::BDT] as $currency) {
                foreach ([1, 2] as $depth) {
                    $rows[$kind.'-'.$currency->value.'-'.$depth] = [$kind, $currency, $depth];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('unchangedContexts')]
    public function test_unchanged_context_with_benign_callback_still_posts_and_commits(string $kind, Currency $currency, int $depth): void
    {
        [$tx, $draft] = $this->fixture($currency);
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        for ($i = 0; $i < $depth; $i++) {
            $connection->beginTransaction();
        }
        $callback = $this->serializationCallback(function () use ($kind, $connection) {
            if ($kind === 'balanced-savepoint') {
                $connection->beginTransaction();
                $connection->rollBack();
            }
            if ($kind === 'read-only') {
                DB::table('ledger_accounts')->count();
            }
        });
        $draft[1]['metadata'] = ['callback' => $callback];
        $rows = app(LedgerPostingService::class)->post($tx, $draft);
        $this->assertSame($depth, $connection->transactionLevel());
        $this->assertSame($pdo, $connection->getRawPdo());
        $this->assertTrue($pdo->inTransaction());
        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }
        $this->assertSame(1, $callback->calls);
        $this->assertCount(2, $rows);
        $this->assertSame(['callback' => ['frozen' => true]], $rows[1]->fresh()->metadata);
        $this->assertSame('10.00', $rows[1]->fresh()->amount);
        $this->assertSame($currency, $rows[1]->currency);
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx->fresh());
    }
}
