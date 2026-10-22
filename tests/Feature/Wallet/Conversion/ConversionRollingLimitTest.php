<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Enums\Currency;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionLimitExceededException;
use App\Services\Wallet\Conversion\ConversionLimitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConversionRollingLimitFixture;

final class ConversionRollingLimitTest extends ConversionTestCase
{
    use ConversionRollingLimitFixture;

    private function limits(): ConversionLimitService
    {
        return app(ConversionLimitService::class);
    }

    public function test_exact_cents_survive_multiple_batches_without_sql_sum_or_float_coercion(): void
    {
        $f = $this->rollingFixture();
        for ($i = 0; $i < 751; $i++) {
            $this->syntheticAllowance($f, '100000000000.01');
        }
        $this->assertSame('75100000000007.51', $this->limits()->dailyTotal($f['user']->id));
        $this->assertSame('75100000000007.51', $this->limits()->monthlyTotal($f['user']->id));
    }

    public static function windows(): array
    {
        return [['now', '80.00', '80.00'], ['-24 hours', '80.00', '80.00'], ['-24 hours -1 second', '0.00', '80.00'], ['-30 days', '0.00', '80.00'], ['-30 days -1 second', '0.00', '0.00'], ['+1 second', '0.00', '0.00']];
    }

    #[DataProvider('windows')]
    public function test_inclusive_windows_share_one_second_resolution_cutoff(string $relative, string $daily, string $monthly): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00.900000'));
        try {
            $f = $this->rollingFixture();
            $this->syntheticAllowance($f, at: Carbon::now()->startOfSecond()->modify($relative));
            $this->assertSame($daily, $this->limits()->dailyTotal($f['user']->id));
            $this->assertSame($monthly, $this->limits()->monthlyTotal($f['user']->id));
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function excluded(): array
    {
        return [[['status' => 'pending']], [['status' => 'failed']], [['status' => 'quoted']], [['currency' => 'USD']], [['completed_at' => null]]];
    }

    #[DataProvider('excluded')]
    public function test_only_eligible_persisted_originals_spend_allowance(array $overrides): void
    {
        $f = $this->rollingFixture();
        $this->syntheticAllowance($f, overrides: $overrides);
        $this->assertSame('0.00', $this->limits()->monthlyTotal($f['user']->id));
    }

    public function test_other_owner_and_inverse_child_are_excluded_but_reversed_original_counts_once(): void
    {
        $f = $this->rollingFixture();
        $other = $this->rollingFixture();
        $this->syntheticAllowance($other);
        $id = $this->syntheticAllowance($f, overrides: ['status' => 'reversed']);
        $this->syntheticAllowance($f, '79.20', overrides: ['reversal_of_id' => $id]);
        $this->assertSame('80.00', $this->limits()->dailyTotal($f['user']->id));
        $this->assertSame('80.00', $this->limits()->monthlyTotal($f['user']->id));
    }

    public static function malformed(): array
    {
        return [['0.00'], ['-1.00']];
    }

    #[DataProvider('malformed')]
    public function test_unproven_eligible_amount_refuses_money_without_repair_or_partial_writes(string $amount): void
    {
        $f = $this->rollingFixture();
        $request = $this->rollingRequest($f);
        $this->syntheticAllowance($f, $amount);
        $before = $this->rollingPaper();
        try {
            $this->service()->convert($request);
            $this->fail('Unproven allowance admitted money.');
        } catch (ConversionException $e) {
            $this->assertSame('conversion_limits_unproven', $e->errorCode());
        }
        $this->assertSame($before, $this->rollingPaper());
    }

    public function test_execution_requires_a_real_transaction_but_advisory_compatibility_remains(): void
    {
        $f = $this->rollingFixture();
        $this->limits()->assertAllowed($f['user']->id, '30.00', Currency::THB);
        try {
            $this->limits()->assertAllowedForExecution($f['user']->id, '30.00', Currency::THB);
            $this->fail('Execution accepted no physical transaction.');
        } catch (ConversionException $e) {
            $this->assertSame('transaction_required', $e->errorCode());
        }
        DB::transaction(fn () => $this->limits()->assertAllowedForExecution($f['user']->id, '30.00', Currency::THB));
        $this->assertSame('0.00', $this->limits()->dailyTotal($f['user']->id));
    }

    public static function ceilings(): array
    {
        return [['daily', 'now'], ['monthly', '-2 days']];
    }

    #[DataProvider('ceilings')]
    public function test_daily_and_monthly_refusal_preserves_all_economic_rows(string $window, string $relative): void
    {
        $f = $this->rollingFixture();
        $request = $this->rollingRequest($f);
        $this->syntheticAllowance($f, at: Carbon::now()->modify($relative));
        config(['conversions.limits.'.$window.'_limit' => '100.00']);
        $before = $this->rollingPaper();
        try {
            $this->service()->convert($request);
            $this->fail('Exhausted allowance admitted money.');
        } catch (ConversionLimitExceededException $e) {
            $this->assertSame('conversions.limits.'.$window.'_limit', $e->limitKey());
            $this->assertSame('110.00', $e->attempted());
        }
        $this->assertSame($before, $this->rollingPaper());
    }

    public function test_exact_boundary_admits_real_posting_then_original_replay_ignores_exhausted_allowance(): void
    {
        $f = $this->rollingFixture();
        $request = $this->rollingRequest($f);
        $this->syntheticAllowance($f, '70.00');
        config(['conversions.limits.daily_limit' => '100.00']);
        $result = $this->service()->convert($request);
        $this->assertSame('100.00', $this->limits()->dailyTotal($f['user']->id));
        config(['conversions.limits.daily_limit' => '0.00']);
        $before = $this->rollingPaper();
        $replay = $this->service()->convert($request);
        $this->assertSame($result->conversionId, $replay->conversionId);
        $this->assertSame($before, $this->rollingPaper());
    }

    public function test_framework_counter_cannot_replace_physical_transaction(): void
    {
        $f = $this->rollingFixture();
        DB::beginTransaction();
        DB::connection()->getPdo()->commit();
        try {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->limits()->assertAllowed($f['user']->id, '30.00', Currency::THB);
            $this->fail('Framework counter authorized execution without SQL transaction.');
        } catch (ConversionException $e) {
            $this->assertSame('transaction_required', $e->errorCode());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_member_api_maps_unproven_allowance_to_safe_refusal_without_economic_mutation(): void
    {
        $f = $this->rollingFixture();
        $request = $this->rollingRequest($f);
        $this->syntheticAllowance($f, '0.00');
        $before = $this->rollingPaper();
        Sanctum::actingAs($f['user']);
        $this->postJson('/api/v1/wallet/conversions', ['direction' => $request->direction->value, 'amount' => $request->amount, 'quote_reference' => $request->quoteReference], ['Idempotency-Key' => $request->idempotencyKey])
            ->assertStatus(422)->assertJsonPath('error.code', 'conversion_limits_unproven');
        $this->assertSame($before, $this->rollingPaper());
    }

    public function test_disabled_window_enforcement_preserves_exact_advisory_totals(): void
    {
        $f = $this->rollingFixture();
        $request = $this->rollingRequest($f);
        $this->syntheticAllowance($f, '80.00');
        config(['conversions.limits.daily_limit' => '100.00', 'conversions.limits.monthly_limit' => '100.00',
            'conversions.limits.enforce_daily' => false, 'conversions.limits.enforce_monthly' => false]);
        $this->service()->convert($request);
        $this->assertSame('110.00', $this->limits()->dailyTotal($f['user']->id));
        $this->assertSame('110.00', $this->limits()->monthlyTotal($f['user']->id));
    }
}
