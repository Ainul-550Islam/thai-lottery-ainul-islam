<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\DTOs\BetPurchaseData;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Exceptions\RiskException;
use App\Models\NumberLimit;
use App\Models\Payout;
use App\Models\User;
use App\Services\Betting\BetPurchaseService;
use App\Services\Betting\BetPurchaseTransactionService;
use App\Services\Betting\BetPurchaseValidator;
use App\Services\Draw\DrawCancellationService;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Finance\Money;
use App\Services\Risk\DrawRiskAdmission;
use App\Services\Risk\NumberLimitEngine;
use App\Services\Risk\RiskAssessmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Throwable;

final class DrawRiskAdmissionTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;

    private function state(): array
    {
        $out = [];
        foreach (['draws', 'number_limits', 'wallets', 'bets', 'bet_items', 'tickets', 'bet_purchase_ownerships',
            'bet_purchase_receipts', 'bet_commission_ownerships', 'financial_transactions', 'ledger_entries', 'audit_logs',
            'draw_cancellation_ownerships'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
        }

        return $out;
    }

    private function reserve(array $f, string $path = 'single'): array
    {
        $engine = app(NumberLimitEngine::class);
        $entry = ['number' => '007', 'stake' => Money::of('10.00', $f['wallet']->currency), 'multiplier' => '1'];

        return match ($path) {
            'single' => $engine->reserve($f['draw']->id, BetType::ThreeD, '007', $entry['stake'], '1'),
            'many' => $engine->reserveMany($f['draw']->id, BetType::ThreeD, [$entry]),
            'facade' => app(RiskAssessmentService::class)->reserveBet($f['draw']->id, BetType::ThreeD,
                [['number' => '007', 'stake' => '10.00', 'multiplier' => '1']]),
        };
    }

    private function refusesWithoutWrites(callable $operation, ?string $reason = null): void
    {
        $before = $this->state();
        try {
            $operation();
            $this->fail('Unadmitted risk was accepted.');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
            if ($reason !== null) {
                $this->assertInstanceOf(RiskException::class, $e);
                $this->assertSame('DRAW_RISK_ADMISSION_REFUSED', $e->errorCode());
                $this->assertSame($reason, $e->contextValue('reason'));
            }
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->state());
    }

    public static function paths(): array
    {
        return [['single'], ['many'], ['facade']];
    }

    #[DataProvider('paths')]
    public function test_open_draw_reservation_changes_only_risk_not_money_or_original_ownership(string $path): void
    {
        $f = $this->purchaseFixture();
        $before = $this->state();
        DB::transaction(fn () => $this->reserve($f, $path));
        $after = $this->state();
        $this->assertSame('20.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        unset($before['number_limits'], $before['audit_logs'], $after['number_limits'], $after['audit_logs']);
        $this->assertSame($before, $after);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    public static function blockedStates(): array
    {
        $out = [];
        foreach (['single', 'many', 'facade'] as $path) {
            foreach ([DrawStatus::Scheduled, DrawStatus::Closed, DrawStatus::Drawing, DrawStatus::ResultPublished,
                DrawStatus::Completed, DrawStatus::Cancelled] as $status) {
                $out[$path.'-'.$status->value] = [$path, $status];
            }
        }

        return $out;
    }

    #[DataProvider('blockedStates')]
    public function test_no_write_ingress_accepts_nonopen_draw_even_when_preview_policy_is_disabled(string $path, DrawStatus $status): void
    {
        $f = $this->purchaseFixture();
        $f['draw']->forceFill(['status' => $status])->save();
        config(['lottery.betting.require_open_draw' => false]);
        $this->refusesWithoutWrites(fn () => DB::transaction(fn () => $this->reserve($f, $path)), 'draw_not_open');
    }

    public static function clocksAndFacts(): array
    {
        return [['future_open'], ['expired_close'], ['missing_open'], ['missing_close'],
            ['closed_at'], ['drawn_at'], ['result_published_at'], ['completed_at']];
    }

    #[DataProvider('clocksAndFacts')]
    public function test_open_label_does_not_override_current_window_or_prior_lifecycle_facts(string $fault): void
    {
        $f = $this->purchaseFixture();
        $patch = match ($fault) {
            'future_open' => ['betting_open_at' => now()->addDay()],
            'expired_close' => ['betting_close_at' => now()],
            'missing_open' => ['betting_open_at' => null],
            'missing_close' => ['betting_close_at' => null],
            default => [$fault => now()],
        };
        $f['draw']->forceFill($patch)->save();
        $this->refusesWithoutWrites(fn () => DB::transaction(fn () => $this->reserve($f)), 'draw_not_open');
    }

    public static function ownerStates(): array
    {
        return [['preparing'], ['refunded'], ['completed'], ['unknown']];
    }

    #[DataProvider('ownerStates')]
    public function test_any_durable_cancellation_namespace_blocks_admission_not_just_a_completed_label(string $state): void
    {
        $f = $this->purchaseFixture();
        DB::table('draw_cancellation_ownerships')->insert(['draw_id' => $f['draw']->id, 'actor_user_id' => $f['user']->id,
            'previous_status' => 'open', 'reason' => 'Synthetic unresolved cancellation', 'participants' => '[]',
            'terms_hash' => str_repeat('0', 64), 'state' => $state, 'created_at' => now(), 'updated_at' => now()]);
        $this->refusesWithoutWrites(fn () => DB::transaction(fn () => $this->reserve($f)), 'draw_cancellation_exists');
    }

    public function test_missing_draw_and_transaction_are_not_implicit_admission(): void
    {
        $f = $this->purchaseFixture();
        $this->refusesWithoutWrites(fn () => $this->reserve($f));
        $this->refusesWithoutWrites(fn () => app(DrawRiskAdmission::class)->lock($f['draw']->id), 'transaction_required');
        $this->refusesWithoutWrites(fn () => DB::transaction(fn () => app(DrawRiskAdmission::class)->lock(PHP_INT_MAX)), 'draw_missing');
    }

    public function test_existing_prize_history_blocks_a_reopened_status(): void
    {
        $f = $this->purchaseFixture();
        Payout::factory()->create(['draw_id' => $f['draw']->id, 'bet_id' => $f['bet']->id, 'user_id' => $f['user']->id]);
        $this->refusesWithoutWrites(fn () => DB::transaction(fn () => $this->reserve($f)), 'draw_settlement_history');
    }

    public function test_prevalidated_purchase_context_cannot_debit_or_create_a_bet_after_the_draw_closes(): void
    {
        $f = $this->purchaseFixture();
        $context = app(BetPurchaseValidator::class)->validate(new BetPurchaseData($f['user']->id, $f['draw']->id,
            '3d_direct', '007', '10.00', 'prevalidated-before-draw-close'));
        app(DrawLifecycleService::class)->close($f['draw']);
        $this->refusesWithoutWrites(fn () => app(BetPurchaseTransactionService::class)->execute($context));
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    public function test_completed_owned_cancellation_cannot_be_reopened_to_accept_an_old_validated_purchase(): void
    {
        $f = $this->purchaseFixture();
        $context = app(BetPurchaseValidator::class)->validate(new BetPurchaseData($f['user']->id, $f['draw']->id,
            '3d_direct', '007', '10.00', 'prevalidated-before-draw-refund'));
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        app(DrawCancellationService::class)->cancel($actor->id, $f['draw']->id, 'Synthetic current admission fixture');
        $f['draw']->fresh()->forceFill(['status' => DrawStatus::Open])->save();
        $this->refusesWithoutWrites(fn () => app(BetPurchaseTransactionService::class)->execute($context));
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
    }

    public static function batchPaths(): array
    {
        return [['many'], ['facade']];
    }

    #[DataProvider('batchPaths')]
    public function test_later_reservation_failure_rolls_back_the_whole_batch_even_when_caller_catches_and_commits(string $path): void
    {
        $f = $this->purchaseFixture();
        $this->provisionBetLimitForTest($f['draw'], '3d_direct', '008');
        DB::table('number_limits')->where('draw_id', $f['draw']->id)->where('number', '008')->update(['max_amount' => '5.00']);
        $before = $this->state();
        DB::transaction(function () use ($f, $path): void {
            DB::table('users')->where('id', $f['user']->id)->update(['name' => 'Caller work retained']);
            try {
                if ($path === 'many') {
                    app(NumberLimitEngine::class)->reserveMany($f['draw']->id, BetType::ThreeD, [
                        ['number' => '007', 'stake' => Money::of('10.00', Currency::THB), 'multiplier' => '1'],
                        ['number' => '008', 'stake' => Money::of('10.00', Currency::THB), 'multiplier' => '1']]);
                } else {
                    app(RiskAssessmentService::class)->reserveBet($f['draw']->id, BetType::ThreeD, [
                        ['number' => '007', 'stake' => '10.00', 'multiplier' => '1'], ['number' => '008', 'stake' => '10.00', 'multiplier' => '1']]);
                }
                $this->fail('Later batch refusal did not occur.');
            } catch (RiskException) {
                $this->assertSame(1, DB::transactionLevel());
            }
        });
        $this->assertSame('Caller work retained', $f['user']->fresh()->name);
        $this->assertSame($before, $this->state());
    }

    public static function postWriteFailures(): array
    {
        return [['single_reserve'], ['single_release'], ['facade_release']];
    }

    #[DataProvider('postWriteFailures')]
    public function test_post_sql_failure_cannot_escape_an_operation_savepoint_when_caller_commits(string $path): void
    {
        $f = $this->purchaseFixture();
        if ($path !== 'single_reserve') {
            DB::transaction(fn () => $this->reserve($f));
        }
        $before = $this->state();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && preg_match('/^update ["`]?number_limits["`]? /i', $query->sql)) {
                $armed = false;
                throw new RuntimeException('Synthetic fault AFTER the actual counter SQL write.');
            }
        });
        DB::transaction(function () use ($f, $path): void {
            DB::table('users')->where('id', $f['user']->id)->update(['name' => 'Caller survives operation rollback']);
            try {
                match ($path) {
                    'single_reserve' => $this->reserve($f),
                    'single_release' => app(NumberLimitEngine::class)->release($f['draw']->id, BetType::ThreeD, '007', Money::of('10.00', Currency::THB), '1'),
                    'facade_release' => app(RiskAssessmentService::class)->releaseBet($f['draw']->id, BetType::ThreeD,
                        [['number' => '007', 'stake' => '10.00', 'multiplier' => '1']]),
                };
                $this->fail('Post-write fault was not reached.');
            } catch (RuntimeException $e) {
                $this->assertSame('Synthetic fault AFTER the actual counter SQL write.', $e->getMessage());
                $this->assertSame(1, DB::transactionLevel());
            }
        });
        $this->assertFalse($armed, 'The actual SQL counter write must have happened before the injected fault.');
        $this->assertSame('Caller survives operation rollback', $f['user']->fresh()->name);
        $this->assertSame($before, $this->state());
    }

    public static function duplicatePaths(): array
    {
        return [['many'], ['facade'], ['release']];
    }

    #[DataProvider('duplicatePaths')]
    public function test_keyed_batches_refuse_canonical_duplicates_instead_of_underreporting_a_mutation(string $path): void
    {
        $f = $this->purchaseFixture();
        if ($path === 'release') {
            DB::transaction(fn () => $this->reserve($f));
            DB::transaction(fn () => $this->reserve($f));
        }
        $before = $this->state();
        try {
            DB::transaction(function () use ($f, $path): void {
                $selection = ['number' => '007', 'stake' => '10.00', 'multiplier' => '1'];
                match ($path) {
                    'many' => app(NumberLimitEngine::class)->reserveMany($f['draw']->id, BetType::ThreeD, [
                        [...$selection, 'stake' => Money::of('10.00', Currency::THB)],
                        [...$selection, 'stake' => Money::of('10.00', Currency::THB)]]),
                    'facade' => app(RiskAssessmentService::class)->reserveBet($f['draw']->id, BetType::ThreeD, [$selection, $selection]),
                    'release' => app(RiskAssessmentService::class)->releaseBet($f['draw']->id, BetType::ThreeD, [$selection, $selection]),
                };
            });
            $this->fail('Duplicate batch silently collapsed its report.');
        } catch (RiskException $e) {
            $this->assertSame('RISK_DUPLICATE_SELECTION', $e->errorCode());
        }
        $this->assertSame($before, $this->state());
    }

    public function test_later_owned_floor_failure_rolls_back_all_prior_facade_releases_when_caller_commits(): void
    {
        $f = $this->purchaseFixture();
        $this->provisionBetLimitForTest($f['draw'], '3d_direct', '008');
        app(BetPurchaseService::class)->purchase(new BetPurchaseData($f['user']->id, $f['draw']->id,
            '3d_direct', '008', '10.00', 'second-owned-risk-floor'));
        DB::transaction(fn () => $this->reserve($f));
        $before = $this->state();
        DB::transaction(function () use ($f): void {
            DB::table('users')->where('id', $f['user']->id)->update(['name' => 'Caller release work retained']);
            try {
                app(RiskAssessmentService::class)->releaseBet($f['draw']->id, BetType::ThreeD, [
                    ['number' => '007', 'stake' => '10.00', 'multiplier' => '1'],
                    ['number' => '008', 'stake' => '10.00', 'multiplier' => '1']]);
                $this->fail('Owned floor was consumed.');
            } catch (RiskException) {
                $this->assertSame(1, DB::transactionLevel());
            }
        });
        $this->assertSame('Caller release work retained', $f['user']->fresh()->name);
        $this->assertSame($before, $this->state());
    }

    public function test_closed_draw_can_release_anonymous_surplus_without_consuming_owned_risk(): void
    {
        $f = $this->purchaseFixture();
        DB::transaction(fn () => $this->reserve($f));
        app(DrawLifecycleService::class)->close($f['draw']);
        DB::transaction(fn () => app(RiskAssessmentService::class)->releaseBet($f['draw']->id, BetType::ThreeD,
            [['number' => '007', 'stake' => '10.00', 'multiplier' => '1']]));
        $this->assertSame('10.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->refusesWithoutWrites(fn () => DB::transaction(fn () => app(NumberLimitEngine::class)->release(
            $f['draw']->id, BetType::ThreeD, '007', Money::of('1.00', Currency::THB), '1')));
    }
}
