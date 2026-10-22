<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Models\WalletConversionQuote;
use App\Services\Finance\LedgerBalanceValidator;
use Tests\Feature\Wallet\Conversion\ConversionTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class WalletConversionServerRaceTest extends ConversionTestCase
{
    use RunsServerFinancialRaces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
    }

    public function test_concurrent_last_balance_allows_only_one_debit(): void
    {
        $f = $this->makeFundedMember('100.00');
        $q1 = $this->quoteFor($f['user']);
        $q2 = $this->quoteFor($f['user']);
        $out = $this->race($f, [$this->command($f, $q1->reference, 'balance-first-key-000001'),
            $this->command($f, $q2->reference, 'balance-second-key-000001')]);
        $this->assertOutcomes($out, ['completed', 'refused']);
        $this->assertSame('insufficient_balance', collect($out)->firstWhere('outcome', 'refused')['code']);
        $this->assertSame('0.00', $this->balanceOf($f['cash']));
        $this->assertSame('99.00', $this->balanceOf($f['win']));
        $this->assertSingleMovement();
        $this->assertSame(1, WalletConversionQuote::query()->where('status', 'consumed')->count());
    }

    public function test_concurrent_identical_thb_requests_replay_once(): void
    {
        $this->assertIdenticalRace(Currency::THB);
    }

    public function test_concurrent_identical_usd_requests_replay_once(): void
    {
        $this->assertIdenticalRace(Currency::USD);
    }

    public function test_concurrent_identical_bdt_requests_replay_once(): void
    {
        $this->assertIdenticalRace(Currency::BDT);
    }

    public function test_concurrent_quote_reuse_with_different_keys_is_refused(): void
    {
        $f = $this->makeFundedMember();
        $q = $this->quoteFor($f['user']);
        $out = $this->race($f, [$this->command($f, $q->reference, 'quote-first-key-000001'),
            $this->command($f, $q->reference, 'quote-second-key-000001')]);
        $this->assertOutcomes($out, ['completed', 'refused']);
        $this->assertSame('quote_expired', collect($out)->firstWhere('outcome', 'refused')['code']);
        $this->assertSame('900.00', $this->balanceOf($f['cash']));
        $this->assertSame('99.00', $this->balanceOf($f['win']));
        $this->assertSingleMovement();
    }

    public function test_concurrent_rolling_limit_is_rechecked_after_the_owner_lock(): void
    {
        $f = $this->makeFundedMember();
        config(['conversions.limits.daily_limit' => '100.00']);
        $q1 = $this->quoteFor($f['user']);
        $q2 = $this->quoteFor($f['user']);
        $out = $this->race($f, [$this->command($f, $q1->reference, 'daily-first-key-000001'),
            $this->command($f, $q2->reference, 'daily-second-key-000001')], ['conversions.limits.daily_limit' => '100.00']);
        $this->assertOutcomes($out, ['completed', 'refused']);
        $this->assertSame('daily_limit_exceeded', collect($out)->firstWhere('outcome', 'refused')['code']);
        $this->assertSame('900.00', $this->balanceOf($f['cash']));
        $this->assertSame('99.00', $this->balanceOf($f['win']));
        $this->assertSingleMovement();
    }

    public function test_concurrent_opposite_directions_preserve_both_balances_and_fees(): void
    {
        $f = $this->makeFundedMember('100.00');
        $f['win']->forceFill(['balance' => '100.00'])->save();
        $q1 = $this->quoteFor($f['user']);
        $q2 = $this->quoteFor($f['user'], direction: WalletConversionDirection::WinToCash);
        $second = $this->command($f, $q2->reference, 'opposite-second-key-000001');
        $second['request']['direction'] = 'win_to_cash';
        $out = $this->race($f, [$this->command($f, $q1->reference, 'opposite-first-key-000001'), $second]);
        $this->assertOutcomes($out, ['completed', 'completed']);
        $this->assertSame('99.00', $this->balanceOf($f['cash']));
        $this->assertSame('99.00', $this->balanceOf($f['win']));
        $this->assertSame(2, WalletConversion::query()->count());
        $this->assertSame(2, FinancialTransaction::query()->count());
        $this->assertBalanced();
    }

    public function test_concurrent_reversal_retries_restore_money_only_once(): void
    {
        $f = $this->makeFundedMember();
        $result = $this->convert($f['user']);
        $row = WalletConversion::query()->where('reference', $result->reference)->firstOrFail();
        $operator = $this->makeOperator();
        $command = ['action' => 'reverse', 'conversion_id' => (int) $row->id,
            'actor_id' => (int) $operator->id, 'key' => 'concurrent-reversal-key-000001'];
        $out = $this->race($f, [$command, $command]);
        $this->assertOutcomes($out, ['completed', 'replayed']);
        $this->assertSame($out[0]['reference'], $out[1]['reference']);
        $this->assertSame('1000.00', $this->balanceOf($f['cash']));
        $this->assertSame('0.00', $this->balanceOf($f['win']));
        $this->assertSame(1, WalletConversion::query()->whereNotNull('reversal_of_id')->count());
        $this->assertSame(2, FinancialTransaction::query()->count());
        $this->assertBalanced();
    }

    public function test_concurrent_same_key_for_different_intents_does_not_move_money_twice(): void
    {
        $f = $this->makeFundedMember();
        $q1 = $this->quoteFor($f['user']);
        $q2 = $this->quoteFor($f['user'], '200.00');
        $second = $this->command($f, $q2->reference, 'same-intent-key-000001');
        $second['request']['amount'] = '200.00';
        $out = $this->race($f, [$this->command($f, $q1->reference, 'same-intent-key-000001'), $second]);
        $this->assertOutcomes($out, ['completed', 'refused']);
        $this->assertSame('idempotency_conflict', collect($out)->firstWhere('outcome', 'refused')['code']);
        $this->assertSingleMovement();
        $row = WalletConversion::query()->firstOrFail();
        $this->assertSame(bcsub('1000.00', $row->amount_in, 2), $this->balanceOf($f['cash']));
        $this->assertSame($row->amount_out, $this->balanceOf($f['win']));
    }

    private function assertIdenticalRace(Currency $currency): void
    {
        $f = $this->makeFundedMember('100.00');
        $config = ['conversions.currency' => $currency->value,
            'fees.categories.cash_to_win.currency' => $currency->value,
            'fees.categories.win_to_cash.currency' => $currency->value];
        config($config);
        if ($currency !== Currency::THB) {
            foreach (['cash' => WalletType::Primary, 'win' => WalletType::Win] as $key => $type) {
                $wallet = Wallet::query()->create(['user_id' => $f['user']->id, 'type' => $type, 'currency' => $currency]);
                $wallet->forceFill(['status' => WalletStatus::Active, 'balance' => $key === 'cash' ? '100.00' : '0.00'])->save();
                $f[$key] = $wallet;
            }
        }
        $request = $this->command($f, null, 'identical-concurrent-key-000001', $currency)['request'];
        $quote = $this->service()->quote(ConversionRequestData::fromArray($request));
        $command = $this->command($f, $quote->reference, 'identical-concurrent-key-000001', $currency);
        $out = $this->race($f, [$command, $command], $config);
        $this->assertOutcomes($out, ['completed', 'replayed']);
        $this->assertSame($out[0]['reference'], $out[1]['reference']);
        $this->assertSame('0.00', $this->balanceOf($f['cash']));
        $this->assertSame('99.00', $this->balanceOf($f['win']));
        $this->assertSingleMovement();
        $this->assertSame($currency->value, WalletConversion::query()->firstOrFail()->currency);
    }

    private function command(array $f, ?string $quote, string $key, Currency $currency = Currency::THB): array
    {
        return ['action' => 'convert', 'request' => ['user_id' => (int) $f['user']->id, 'direction' => 'cash_to_win',
            'amount' => '100.00', 'currency' => $currency->value, 'channel' => 'api', 'quote_reference' => $quote, 'idempotency_key' => $key]];
    }

    private function race(array $f, array $commands, array $config = []): array
    {
        return $this->serverRace($commands, 'users', (int) $f['user']->id, $config);
    }

    private function assertOutcomes(array $results, array $expected): void
    {
        $actual = array_column($results, 'outcome');
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual, json_encode($results));
    }

    private function assertSingleMovement(): void
    {
        $this->assertSame(1, WalletConversion::query()->count());
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(3, LedgerEntry::query()->count());
        $this->assertBalanced();
    }

    private function assertBalanced(): void
    {
        foreach (FinancialTransaction::query()->get() as $transaction) {
            app(LedgerBalanceValidator::class)->assertTransactionBalanced($transaction);
            $this->assertTrue(true);
        }
    }
}
