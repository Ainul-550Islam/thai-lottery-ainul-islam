<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Enums\WalletConversionDirection;
use App\Models\WalletConversionQuote;
use App\Repositories\Wallet\WalletConversionQuoteRepository;
use App\Services\Wallet\Conversion\ConversionQuoteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConversionQuoteSlotFixture;

final class ConversionQuoteSlotTest extends ConversionTestCase
{
    use ConversionQuoteSlotFixture;

    public static function transactionGuards(): array
    {
        $rows = [];
        foreach (['absent', 'stale-framework-counter', 'untracked-pdo'] as $state) {
            foreach (['slot', 'lookup', 'consume', 'usable', 'optional-usable'] as $path) {
                $rows[$state.'-'.$path] = [$state, $path];
            }
        }

        return $rows;
    }

    #[DataProvider('transactionGuards')]
    public function test_decisive_quote_paths_require_both_framework_and_physical_transaction(string $state, string $path): void
    {
        $f = $this->slotFixture();
        $q = $this->quoteFor($f['user'], amount: '30.00');
        $row = WalletConversionQuote::query()->where('reference', $q->reference)->firstOrFail();
        $request = $this->slotRequest($f)->withQuoteReference($q->reference);
        $repository = app(WalletConversionQuoteRepository::class);
        $before = $this->slotPaper();
        if ($state === 'stale-framework-counter') {
            DB::beginTransaction();
            DB::connection()->getPdo()->commit();
        } elseif ($state === 'untracked-pdo') {
            DB::connection()->getPdo()->beginTransaction();
        }
        try {
            $this->slotRefuses(function () use ($path, $f, $row, $request, $repository): void {
                match ($path) {
                    'slot' => $repository->assertOpenSlotAvailableForExecution($f['user']->id, 5, Carbon::now()),
                    'lookup' => $repository->findByReferenceForUpdate($row->reference),
                    'consume' => $repository->consume($row, 99999999),
                    'usable' => app(ConversionQuoteService::class)->assertUsable($request),
                    'optional-usable' => $this->optionalQuoteCheck($f),
                };
            }, 'transaction_required');
        } finally {
            if ($state === 'stale-framework-counter') {
                DB::rollBack();
            } elseif (DB::connection()->getPdo()->inTransaction()) {
                DB::connection()->getPdo()->rollBack();
            }
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->slotPaper());
    }

    private function optionalQuoteCheck(array $f): void
    {
        config(['conversions.quotes.require_for_execution' => false, 'conversions.directions.cash_to_win.requires_quote' => false]);
        app(ConversionQuoteService::class)->assertUsable($this->slotRequest($f));
    }

    public static function ceilings(): array
    {
        return [[0, 1], [-5, 1], [1, 1], [2, 2]];
    }

    #[DataProvider('ceilings')]
    public function test_effective_ceiling_is_preserved_and_refusal_writes_no_new_quote_or_money(int $configured, int $effective): void
    {
        $f = $this->slotFixture();
        config(['conversions.quotes.max_open_per_user' => $configured]);
        for ($i = 0; $i < $effective; $i++) {
            $this->service()->quote($this->slotRequest($f, 'slot-ceiling-request-000'.$i));
        }
        $before = $this->slotPaper();
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'rate_limited');
        $this->assertSame($before, $this->slotPaper());
        $this->assertSame($effective, app(WalletConversionQuoteRepository::class)->countOpenForUser($f['user']->id));
    }

    public static function expiries(): array
    {
        return [[59, false], [60, true], [61, true]];
    }

    #[DataProvider('expiries')]
    public function test_expiry_boundary_frees_slot_without_relabelling_or_deleting_history(int $seconds, bool $allowed): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00'));
        try {
            $f = $this->slotFixture();
            config(['conversions.quotes.max_open_per_user' => 1, 'conversions.quotes.ttl_seconds' => 60]);
            $q = $this->service()->quote($this->slotRequest($f));
            $old = DB::table('wallet_conversion_quotes')->where('reference', $q->reference)->first();
            Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
            if ($allowed) {
                $this->service()->quote($this->slotRequest($f, 'slot-expiry-request-00002'));
                $this->assertDatabaseCount('wallet_conversion_quotes', 2);
            } else {
                $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'rate_limited');
                $this->assertDatabaseCount('wallet_conversion_quotes', 1);
            }
            $this->assertEquals($old, DB::table('wallet_conversion_quotes')->where('reference', $q->reference)->first());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_issued_expiry_and_admission_cutoff_use_the_same_stored_second_precision(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 12:00:00.900000'));
        try {
            $f = $this->slotFixture();
            config(['conversions.quotes.ttl_seconds' => 60]);
            $q = $this->service()->quote($this->slotRequest($f));
            $stored = WalletConversionQuote::query()->where('reference', $q->reference)->firstOrFail();
            $this->assertSame('000000', $q->issuedAt->format('u'));
            $this->assertSame('000000', $q->expiresAt->format('u'));
            $this->assertSame($q->issuedAt->format('Y-m-d H:i:s.u'), $stored->issued_at->format('Y-m-d H:i:s.u'));
            $this->assertSame(60, $q->expiresAt->getTimestamp() - $q->issuedAt->getTimestamp());
        } finally {
            Carbon::setTestNow();
        }
    }

    public static function closedLifecycle(): array
    {
        return [['consumed'], ['expired'], ['consumed-pointer'], ['consumed-timestamp']];
    }

    #[DataProvider('closedLifecycle')]
    public function test_existing_lifecycle_filters_do_not_resurrect_closed_or_partially_linked_quote_facts(string $kind): void
    {
        $f = $this->slotFixture();
        config(['conversions.quotes.max_open_per_user' => 1]);
        $q = $this->service()->quote($this->slotRequest($f));
        $this->service()->convert($this->slotRequest($f, 'slot-real-execution-0001')->withQuoteReference($q->reference));
        // Raw noncanonical lifecycle facts are aggregate fixtures, not newly granted execution authority.
        if ($kind !== 'consumed') {
            $changes = match ($kind) {
                'expired' => ['status' => 'expired', 'converted_id' => null, 'consumed_at' => null],
                'consumed-pointer' => ['status' => 'open', 'consumed_at' => null],
                'consumed-timestamp' => ['status' => 'open', 'converted_id' => null],
            };
            DB::table('wallet_conversion_quotes')->where('reference', $q->reference)->update($changes);
        }
        $old = DB::table('wallet_conversion_quotes')->where('reference', $q->reference)->first();
        $money = $this->slotPaper(false);
        $this->service()->quote($this->slotRequest($f, 'slot-lifecycle-new-000001'));
        $this->assertEquals($old, DB::table('wallet_conversion_quotes')->where('reference', $q->reference)->first());
        $this->assertSame($money, $this->slotPaper(false));
        $this->assertSame(1, app(WalletConversionQuoteRepository::class)->countOpenForUser($f['user']->id));
    }

    public function test_owner_ceiling_is_shared_across_directions_and_currency_facts_but_not_other_members(): void
    {
        $f = $this->slotFixture();
        $other = $this->slotFixture();
        config(['conversions.quotes.max_open_per_user' => 1]);
        $this->service()->quote($this->slotRequest($other));
        $q = $this->service()->quote($this->slotRequest($f));
        // Eligibility-only currency fact: this is not an FX quote or proof of USD authority.
        DB::table('wallet_conversion_quotes')->where('reference', $q->reference)->update(['currency' => 'USD']);
        $before = $this->slotPaper();
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f, direction: WalletConversionDirection::WinToCash)), 'rate_limited');
        $this->assertSame($before, $this->slotPaper());
    }

    public function test_freeing_quote_slot_does_not_reset_rolling_allowance_or_block_proved_original_replay(): void
    {
        $f = $this->slotFixture();
        config(['conversions.quotes.max_open_per_user' => 1, 'conversions.limits.daily_limit' => '30.00']);
        $q = $this->service()->quote($this->slotRequest($f));
        $request = $this->slotRequest($f, 'slot-replay-execution-0001')->withQuoteReference($q->reference);
        $result = $this->service()->convert($request);
        $before = $this->slotPaper();
        $this->slotRefuses(fn () => $this->service()->quote($this->slotRequest($f)), 'daily_limit_exceeded');
        $this->assertSame($result->conversionId, $this->service()->convert($request)->conversionId);
        $this->assertSame($before, $this->slotPaper());
    }

    public function test_current_slot_query_selects_only_ids_and_at_most_the_effective_ceiling(): void
    {
        $f = $this->slotFixture();
        config(['conversions.quotes.max_open_per_user' => 10]);
        for ($i = 0; $i < 5; $i++) {
            $this->service()->quote($this->slotRequest($f, 'slot-bounded-query-000'.$i));
        }
        DB::enableQueryLog();
        try {
            DB::transaction(fn () => $this->slotRefuses(fn () => app(WalletConversionQuoteRepository::class)->assertOpenSlotAvailableForExecution($f['user']->id, 3, Carbon::now()), 'rate_limited'));
            $queries = array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'wallet_conversion_quotes')));
            $this->assertCount(1, $queries);
            $sql = strtolower($queries[0]['query']);
            $this->assertStringContainsString('limit 3', $sql);
            $this->assertStringNotContainsString('count(', $sql);
            $this->assertStringNotContainsString('select *', $sql);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_member_api_returns_429_at_capacity_without_creating_quote_or_economic_rows(): void
    {
        $f = $this->slotFixture();
        config(['conversions.quotes.max_open_per_user' => 1]);
        $this->service()->quote($this->slotRequest($f));
        $before = $this->slotPaper();
        Sanctum::actingAs($f['user']);
        $this->postJson('/api/v1/wallet/conversions/quote', ['direction' => 'cash_to_win', 'amount' => '30.00'])
            ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertSame($before, $this->slotPaper());
    }
}
