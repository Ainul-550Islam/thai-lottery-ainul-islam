<?php

declare(strict_types=1);

namespace Tests\Standalone;

use App\Enums\ConversionFeeBearer;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionReconciliationState;
use App\Enums\WalletConversionRejectionReason;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletType;
use App\Models\WalletConversion;
use App\Models\WalletConversionAudit;
use App\Models\WalletConversionQuote;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Tests only these foundations in an isolated SQLite database, not the application. */
final class ConversionFoundationTest extends TestCase
{
    private Capsule $database;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 06:00:00 UTC');
        $container = new Container;
        $this->database = new Capsule($container);
        $this->database->addConnection([
            'driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $this->database->setEventDispatcher(new Dispatcher($container));
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->bind('db.schema', fn () => $this->database->getConnection()->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        Capsule::schema()->create('users', function (Blueprint $table): void {
            $table->id();
        });
        Capsule::schema()->create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('balance', 20, 2)->default(0);
        });
        Capsule::schema()->create('financial_transactions', function (Blueprint $table): void {
            $table->id();
        });
        Capsule::table('users')->insert([['id' => 1], ['id' => 2]]);
        Capsule::table('wallets')->insert([
            ['id' => 1, 'user_id' => 1, 'balance' => '1000.00'],
            ['id' => 2, 'user_id' => 1, 'balance' => '0.00'],
            ['id' => 3, 'user_id' => 2, 'balance' => '1000.00'],
            ['id' => 4, 'user_id' => 2, 'balance' => '0.00'],
        ]);
        $this->migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_13_000200_create_wallet_conversion_tables.php';
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conversion(array $overrides = []): WalletConversion
    {
        return WalletConversion::query()->create(array_replace([
            'reference' => 'CV-'.bin2hex(random_bytes(8)), 'user_id' => 1,
            'direction' => 'cash_to_win', 'currency' => 'THB',
            'from_wallet_id' => 1, 'to_wallet_id' => 2,
            'amount_in' => '100.00', 'fee_amount' => '1.00', 'amount_out' => '99.00',
            'fee_rate' => '0.01000000', 'fee_bearer' => 'source',
            'fee_rule_key' => 'fees.categories.cash_to_win', 'fee_rule_version' => '2',
            'channel' => 'api', 'idempotency_key' => 'test-key-'.bin2hex(random_bytes(12)),
        ], $overrides));
    }

    private function quote(array $overrides = []): WalletConversionQuote
    {
        return WalletConversionQuote::query()->create(array_replace([
            'reference' => 'CQ-'.bin2hex(random_bytes(8)), 'user_id' => 1,
            'direction' => 'cash_to_win', 'currency' => 'THB',
            'amount_in' => '100.00', 'fee_amount' => '1.00', 'amount_out' => '99.00',
            'rate' => '0.01000000', 'fee_bearer' => 'source',
            'fee_rule_key' => 'fees.categories.cash_to_win', 'fee_rule_version' => '2',
            'limits' => ['min_amount' => '10.00'],
            'issued_at' => Carbon::now(), 'expires_at' => Carbon::now()->addSeconds(120),
        ], $overrides));
    }

    private function completed(): WalletConversion
    {
        $row = $this->conversion();
        $row->status = WalletConversionStatus::Processing;
        $row->save();
        $row->forceFill([
            'status' => WalletConversionStatus::Completed, 'completed_at' => Carbon::now(),
            'from_balance_after' => '900.00', 'to_balance_after' => '99.00',
        ])->save();

        return $row;
    }

    public function test_direction_maps_primary_and_win_without_enabling_direct_win_withdrawal(): void
    {
        self::assertSame(WalletType::Primary, WalletConversionDirection::CashToWin->fromType());
        self::assertSame(WalletType::Win, WalletConversionDirection::CashToWin->toType());
        self::assertSame(WalletConversionDirection::WinToCash, WalletConversionDirection::CashToWin->opposite());
        self::assertSame(WalletType::Primary, WalletConversionDirection::WinToCash->toType());
        self::assertFalse(WalletType::Win->canWithdraw());
        self::assertFalse(WalletType::Win->canDeposit());
        self::assertFalse(WalletType::Win->canBet());
        self::assertTrue(WalletType::Primary->canBet());
    }

    public function test_direction_parser_and_fee_paths_match_existing_contract(): void
    {
        self::assertSame(WalletConversionDirection::CashToWin, WalletConversionDirection::tryFromString(' CASH_TO_WIN '));
        self::assertNull(WalletConversionDirection::tryFromString(null));
        self::assertNull(WalletConversionDirection::tryFromString('other'));
        self::assertSame('fees.categories.win_to_cash', WalletConversionDirection::WinToCash->feeConfigPath());
    }

    public function test_enum_status_transition_graph_is_one_way(): void
    {
        self::assertTrue(WalletConversionStatus::Pending->canTransitionTo(WalletConversionStatus::Processing));
        self::assertFalse(WalletConversionStatus::Pending->canTransitionTo(WalletConversionStatus::Completed));
        self::assertTrue(WalletConversionStatus::Completed->canTransitionTo(WalletConversionStatus::Reversed));
        self::assertFalse(WalletConversionStatus::Reversed->canTransitionTo(WalletConversionStatus::Completed));
        self::assertFalse(WalletConversionStatus::Failed->isSuccessful());
        self::assertFalse(WalletConversionStatus::Reversed->isSuccessful());
        self::assertSame([WalletConversionStatus::Completed, WalletConversionStatus::Reversed], WalletConversionStatus::settledStates());
    }

    public function test_rejection_http_codes_and_channel_metadata(): void
    {
        self::assertSame(503, WalletConversionRejectionReason::FeatureDisabled->httpStatus());
        self::assertSame(409, WalletConversionRejectionReason::IdempotencyConflict->httpStatus());
        self::assertSame(403, WalletConversionRejectionReason::KycNotVerified->httpStatus());
        self::assertSame(429, WalletConversionRejectionReason::RateLimited->httpStatus());
        self::assertFalse(WalletConversionChannel::Api->isOperator());
        self::assertTrue(WalletConversionChannel::Admin->isOperator());
        self::assertSame('source', ConversionFeeBearer::Source->value);
        self::assertFalse(WalletConversionReconciliationState::Pending->isHealthy());
        foreach (WalletConversionRejectionReason::cases() as $reason) {
            self::assertNotSame('', $reason->label());
        }
    }

    public function test_migration_creates_all_three_tables_without_changing_wallet_balances(): void
    {
        foreach (['wallet_conversions', 'wallet_conversion_quotes', 'wallet_conversion_audits'] as $table) {
            self::assertTrue(Capsule::schema()->hasTable($table));
        }
        self::assertEquals(1000, Capsule::table('wallets')->where('id', 1)->value('balance'));
        self::assertEquals(0, Capsule::table('wallets')->where('id', 2)->value('balance'));
    }

    public function test_creation_defaults_and_decimal_casts(): void
    {
        $row = $this->conversion()->fresh();
        self::assertSame(WalletConversionStatus::Pending, $row->status);
        self::assertSame('primary', $row->from_wallet_type);
        self::assertSame('win', $row->to_wallet_type);
        self::assertSame('100.00', $row->amount_in);
        self::assertSame('1.00', $row->fee_amount);
    }

    public function test_same_wallet_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->conversion(['to_wallet_id' => 1]);
    }

    public function test_inconsistent_money_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->conversion(['amount_out' => '100.00']);
    }

    public function test_excess_decimal_precision_is_refused_before_casting(): void
    {
        $this->expectException(DomainException::class);
        $this->conversion(['amount_in' => '100.001']);
    }

    public function test_negative_fee_is_refused_for_non_reversal(): void
    {
        $this->expectException(DomainException::class);
        $this->conversion(['fee_amount' => '-1.00', 'amount_out' => '101.00']);
    }

    public function test_wallet_type_direction_mismatch_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->conversion(['from_wallet_type' => 'win']);
    }

    public function test_completed_creation_is_refused(): void
    {
        $this->expectException(DomainException::class);
        $this->conversion(['status' => 'completed', 'completed_at' => Carbon::now()]);
    }

    public function test_pending_cannot_skip_processing(): void
    {
        $row = $this->conversion();
        $this->expectException(DomainException::class);
        $row->forceFill(['status' => 'completed', 'completed_at' => Carbon::now()])->save();
    }

    public function test_completed_row_money_cannot_be_edited(): void
    {
        $row = $this->completed();
        self::assertTrue($row->canBeReversed());
        $this->expectException(DomainException::class);
        $row->forceFill(['fee_amount' => '2.00', 'amount_out' => '98.00'])->save();
    }

    public function test_conversion_cannot_be_deleted(): void
    {
        $row = $this->conversion();
        $this->expectException(DomainException::class);
        $row->delete();
    }

    public function test_database_refuses_duplicate_owner_key(): void
    {
        $this->conversion(['idempotency_key' => 'same-key-00000000000001']);
        $this->expectException(QueryException::class);
        $this->conversion(['idempotency_key' => 'same-key-00000000000001']);
    }

    public function test_same_key_is_scoped_to_each_owner(): void
    {
        $key = 'same-key-00000000000001';
        $this->conversion(['idempotency_key' => $key]);
        $this->conversion(['user_id' => 2, 'from_wallet_id' => 3, 'to_wallet_id' => 4, 'idempotency_key' => $key]);
        self::assertSame(1, WalletConversion::query()->forUser(1)->count());
        self::assertSame(1, WalletConversion::query()->forUser(2)->count());
    }

    public function test_foreign_keys_restrict_financial_owner_deletion(): void
    {
        $this->conversion();
        $this->expectException(QueryException::class);
        Capsule::table('users')->where('id', 1)->delete();
    }

    public function test_reversal_window_excludes_future_completion_and_exact_cutoff(): void
    {
        $row = $this->completed();
        self::assertTrue($row->isInsideReversalWindow(24));
        self::assertFalse($row->isInsideReversalWindow(0));
        $row->completed_at = Carbon::now()->addMinute();
        self::assertFalse($row->isInsideReversalWindow(24));
        $row->completed_at = Carbon::now()->subHours(24);
        self::assertFalse($row->isInsideReversalWindow(24));
    }

    public function test_reversal_row_can_represent_an_explicit_fee_refund(): void
    {
        $original = $this->completed();
        $refund = $this->conversion([
            'direction' => 'win_to_cash', 'from_wallet_id' => 2, 'to_wallet_id' => 1,
            'amount_in' => '99.00', 'fee_amount' => '-1.00', 'amount_out' => '100.00',
            'reversal_of_id' => $original->id,
        ]);
        self::assertSame('-1.00', $refund->fresh()->fee_amount);
        self::assertFalse($refund->canBeReversed());
    }

    public function test_quote_is_open_then_expires_at_exact_cutoff(): void
    {
        $row = $this->quote();
        self::assertTrue($row->isOpen());
        Carbon::setTestNow(Carbon::now()->addSeconds(120));
        self::assertTrue($row->isExpired());
        self::assertFalse($row->isOpen());
        self::assertSame(1, WalletConversionQuote::query()->open()->count());
    }

    public function test_quote_price_is_immutable(): void
    {
        $row = $this->quote();
        $this->expectException(DomainException::class);
        $row->forceFill(['fee_amount' => '2.00', 'amount_out' => '98.00'])->save();
    }

    public function test_quote_can_be_consumed_once_and_not_reopened(): void
    {
        $conversion = $this->conversion();
        $row = $this->quote();
        $row->forceFill(['status' => 'consumed', 'converted_id' => $conversion->id, 'consumed_at' => Carbon::now()])->save();
        self::assertFalse($row->fresh()->isOpen());
        $this->expectException(DomainException::class);
        $row->forceFill(['status' => 'open', 'converted_id' => null, 'consumed_at' => null])->save();
    }

    public function test_expired_quote_cannot_be_consumed(): void
    {
        $conversion = $this->conversion();
        $row = $this->quote();
        Carbon::setTestNow(Carbon::now()->addSeconds(121));
        $this->expectException(DomainException::class);
        $row->forceFill(['status' => 'consumed', 'converted_id' => $conversion->id, 'consumed_at' => Carbon::now()])->save();
    }

    public function test_audit_records_rejection_without_conversion_row(): void
    {
        $row = WalletConversionAudit::query()->create([
            'event' => 'rejected', 'user_id' => 1, 'channel' => 'api',
            'direction' => 'cash_to_win', 'error_code' => 'kyc_not_verified',
            'checks' => ['kyc' => false],
        ]);
        self::assertNull($row->wallet_conversion_id);
        self::assertSame(['kyc' => false], $row->fresh()->checks);
        self::assertNotNull($row->recorded_at);
    }

    public function test_audit_update_is_refused(): void
    {
        $row = WalletConversionAudit::query()->create(['event' => 'rejected', 'channel' => 'api']);
        $this->expectException(DomainException::class);
        $row->event = 'completed';
        $row->save();
    }

    public function test_audit_delete_is_refused(): void
    {
        $row = WalletConversionAudit::query()->create(['event' => 'rejected', 'channel' => 'api']);
        $this->expectException(DomainException::class);
        $row->delete();
    }

    public function test_empty_schema_can_roll_back_in_dependency_order(): void
    {
        $this->migration->down();
        self::assertFalse(Capsule::schema()->hasTable('wallet_conversions'));
        self::assertTrue(Capsule::schema()->hasTable('wallets'));
    }

    public function test_populated_schema_rollback_is_refused_without_deleting_tables(): void
    {
        $this->quote();
        try {
            $this->migration->down();
            self::fail('Populated financial history must block rollback.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Refusing to drop', $error->getMessage());
            self::assertTrue(Capsule::schema()->hasTable('wallet_conversion_quotes'));
            self::assertSame(1, WalletConversionQuote::query()->count());
        }
    }
}
