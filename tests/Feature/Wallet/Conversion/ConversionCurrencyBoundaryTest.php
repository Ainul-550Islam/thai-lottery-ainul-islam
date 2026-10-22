<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionFeeBreakdownData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Exceptions\Wallet\ConversionException;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Models\WalletConversionQuote;
use App\Services\Finance\Money;
use App\Services\Wallet\Conversion\ConversionLimitService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

class ConversionCurrencyBoundaryTest extends ConversionTestCase
{
    public static function nonPrimaryCurrencies(): array
    {
        return ['USD' => ['USD'], 'BDT' => ['BDT']];
    }

    public static function malformedCurrencies(): array
    {
        return ['unknown' => ['ZZZ'], 'null' => [null], 'empty' => [''],
            'array' => [['USD']], 'integer' => [42], 'lowercase' => ['usd']];
    }

    #[DataProvider('malformedCurrencies')]
    public function test_invalid_configuration_refuses_every_currency_dependent_surface_without_writes(mixed $currency): void
    {
        $member = $this->makeFundedMember();
        $this->actingAs($member['user']);
        Sanctum::actingAs($member['user']);
        config(['conversions.currency' => $currency]);
        $payload = ['direction' => 'cash_to_win', 'amount' => '100.00', 'currency' => 'THB',
            'idempotency_key' => 'invalid-configuration-key-000001', 'quote_reference' => 'unused-quote'];
        $this->get('/wallet/convert')->assertStatus(503);
        $this->getJson('/api/v1/wallet/conversions/summary')->assertStatus(503)
            ->assertJsonPath('error.code', 'currency_not_configured');
        $this->postJson('/api/v1/wallet/conversions/quote', $payload)->assertStatus(503)
            ->assertJsonPath('error.code', 'currency_not_configured');
        $this->postJson('/api/v1/wallet/conversions', $payload)->assertStatus(503)
            ->assertJsonPath('error.code', 'currency_not_configured');
        $this->json('GET', '/wallet/convert/preview', $payload)->assertStatus(503)
            ->assertJsonPath('error.code', 'currency_not_configured');
        foreach ([
            fn () => $this->service()->quote($this->request($member['user'])),
            fn () => app(ConversionLimitService::class)->dailyTotal((int) $member['user']->id),
            fn () => app(ConversionLimitService::class)->monthlyTotal((int) $member['user']->id),
            fn () => ConversionRequestData::fromArray(['direction' => 'cash_to_win', 'amount' => '100.00', 'user_id' => 1]),
        ] as $operation) {
            $this->assertCurrencyRefused($operation, 'currency_not_configured');
        }
        $this->assertUntouched($member);
    }

    #[DataProvider('malformedCurrencies')]
    public function test_explicit_bad_currency_is_not_replaced_by_the_configured_currency(mixed $currency): void
    {
        $member = $this->makeFundedMember();
        $this->actingAs($member['user']);
        Sanctum::actingAs($member['user']);
        $payload = ['direction' => 'cash_to_win', 'amount' => '100.00', 'currency' => $currency,
            'idempotency_key' => 'invalid-request-currency-000001', 'quote_reference' => 'unused-quote'];
        $this->postJson('/api/v1/wallet/conversions/quote', $payload)->assertStatus(422);
        $this->postJson('/api/v1/wallet/conversions', $payload)->assertStatus(422);
        $this->json('GET', '/wallet/convert/preview', $payload)->assertStatus(422);
        $this->json('GET', '/api/v1/wallet/conversions/summary', ['currency' => $currency])->assertStatus(422);
        $this->assertUntouched($member);
    }

    public function test_supported_but_different_currency_is_refused_even_for_a_read_only_preview(): void
    {
        $member = $this->makeFundedMember();
        $this->actingAs($member['user']);
        Sanctum::actingAs($member['user']);
        $payload = ['direction' => 'cash_to_win', 'amount' => '100.00', 'currency' => 'USD'];
        $this->postJson('/api/v1/wallet/conversions/quote', $payload)->assertStatus(422)->assertJsonPath('error.code', 'currency_mismatch');
        $this->json('GET', '/wallet/convert/preview', $payload)->assertStatus(422)->assertJsonPath('error.code', 'currency_mismatch');
        $this->assertUntouched($member);
    }

    #[DataProvider('nonPrimaryCurrencies')]
    public function test_omitted_currency_uses_the_explicit_configuration_not_primary_thb(string $code): void
    {
        $member = $this->makeFundedMember();
        $this->addCurrencyWallets((int) $member['user']->id, Currency::from($code));
        $this->configureCurrency($code);
        Sanctum::actingAs($member['user']);
        $quote = $this->postJson('/api/v1/wallet/conversions/quote', [
            'direction' => 'cash_to_win', 'amount' => '100.00',
        ])->assertCreated()->assertJsonPath('data.quote.currency', $code)->json('data.quote.reference');
        $this->postJson('/api/v1/wallet/conversions', ['direction' => 'cash_to_win', 'amount' => '100.00',
            'quote_reference' => $quote, 'idempotency_key' => 'configured-usd-conversion-000001'])
            ->assertCreated()->assertJsonPath('data.conversion.currency', $code)
            ->assertJsonPath('data.conversion.amount_out', '99.00');
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $this->assertSame('0.00', $this->balanceOf($member['win']));
        $this->assertSame('900.00', Wallet::query()->where('user_id', $member['user']->id)->where('currency', $code)->where('type', 'primary')->firstOrFail()->balance);
    }

    public function test_summary_has_separate_exact_currency_buckets_and_scoped_legacy_money_fields(): void
    {
        $member = $this->makeFundedMember();
        $this->convert($member['user']);
        $this->addCurrencyWallets((int) $member['user']->id);
        $this->configureCurrency('USD');
        Sanctum::actingAs($member['user']);
        $quote = $this->postJson('/api/v1/wallet/conversions/quote', ['direction' => 'cash_to_win', 'amount' => '200.00'])
            ->assertCreated()->json('data.quote.reference');
        $this->postJson('/api/v1/wallet/conversions', ['direction' => 'cash_to_win', 'amount' => '200.00',
            'quote_reference' => $quote, 'idempotency_key' => 'summary-usd-conversion-000001'])->assertCreated();
        // An unrelated member's actual conversion must not contaminate either bucket.
        $this->configureCurrency('THB');
        $other = $this->makeFundedMember();
        $this->convert($other['user'], '50.00', idempotencyKey: 'other-owner-conversion-000001');
        Sanctum::actingAs($member['user']);
        $ledgerCount = LedgerEntry::query()->count();
        $summary = $this->getJson('/api/v1/wallet/conversions/summary')->assertOk();
        $summary->assertJsonPath('data.summary.completed', 2)
            ->assertJsonPath('data.summary.currency', 'THB')
            ->assertJsonPath('data.summary.completed_volume_in', '100.00')
            ->assertJsonPath('data.summary.completed_volume_out', '99.00')
            ->assertJsonPath('data.summary.fee_total', '1.00')
            ->assertJsonPath('data.summary.by_currency.THB.completed_volume_in', '100.00')
            ->assertJsonPath('data.summary.by_currency.USD.completed_volume_in', '200.00')
            ->assertJsonPath('data.summary.by_currency.USD.completed_volume_out', '198.00')
            ->assertJsonPath('data.summary.by_currency.USD.fee_total', '2.00');
        $this->getJson('/api/v1/wallet/conversions/summary?currency=USD')->assertOk()
            ->assertJsonPath('data.summary.currency', 'USD')
            ->assertJsonPath('data.summary.completed_volume_in', '200.00');
        $this->getJson('/api/v1/wallet/conversions/summary?currency=BDT')->assertOk()
            ->assertJsonPath('data.summary.currency', 'BDT')
            ->assertJsonPath('data.summary.completed_volume_in', '0.00');
        $this->assertSame($ledgerCount, LedgerEntry::query()->count());
    }

    public function test_open_quotes_keep_their_recorded_denomination_after_configuration_changes(): void
    {
        $member = $this->makeFundedMember();
        $quote = $this->quoteFor($member['user']);
        $this->addCurrencyWallets((int) $member['user']->id);
        $this->configureCurrency('USD');
        $response = $this->actingAs($member['user'])->withSession(['conversion_quote_reference' => $quote->reference])->get('/wallet/convert');
        $response->assertOk()->assertSee(Money::of('99.00', Currency::THB)->format())
            ->assertSee('data-wc-currency="USD"', false)
            ->assertViewHas('activeQuoteReference', null)
            ->assertDontSee('value="'.$quote->reference.'"', false);
        $this->assertTrue(WalletConversionQuote::query()->where('reference', $quote->reference)->firstOrFail()->isOpen());
        $this->assertUntouched($member, expectedQuotes: 1);
    }

    #[DataProvider('malformedCurrencies')]
    public function test_dto_cannot_turn_a_malformed_currency_into_primary_money(mixed $currency): void
    {
        $this->expectException(ConversionException::class);
        ConversionRequestData::fromArray(['direction' => 'cash_to_win', 'amount' => '100.00', 'currency' => $currency, 'user_id' => 1]);
    }

    public function test_dto_omission_uses_configured_currency_and_bad_fee_currency_is_refused(): void
    {
        $this->configureCurrency('USD');
        $dto = ConversionRequestData::fromArray(['direction' => 'cash_to_win', 'amount' => '100.00', 'user_id' => 1]);
        $this->assertSame(Currency::USD, $dto->currency);
        $price = $this->calculator()->breakdownFor($dto->direction, '100.00', 'USD');
        $bad = new ConversionFeeBreakdownData(...array_merge(get_object_vars($price), ['currency' => 'ZZZ']));
        $this->expectException(ConversionException::class);
        ConversionFeeBreakdownData::currencyOf($bad);
    }

    public function test_missing_configuration_does_not_default_to_thb(): void
    {
        $member = $this->makeFundedMember();
        $values = config('conversions');
        unset($values['currency']);
        config(['conversions' => $values]);
        $this->actingAs($member['user'])->get('/wallet/convert')->assertStatus(503);
        $this->assertCurrencyRefused(fn () => $this->service()->quote($this->request($member['user'])), 'currency_not_configured');
        $this->assertUntouched($member);
    }

    public function test_reversal_rows_are_not_counted_as_fresh_completed_volume(): void
    {
        $member = $this->makeFundedMember();
        $result = $this->convert($member['user']);
        $row = WalletConversion::query()->where('reference', $result->reference)->firstOrFail();
        $operator = $this->makeOperator();
        $this->service()->reverse((int) $row->id,
            new ConversionAuditContextData(WalletConversionChannel::Admin, (int) $operator->id),
            'Approved synthetic correction for currency summary regression.', 'summary-reversal-key-000001');
        Sanctum::actingAs($member['user']);
        $this->getJson('/api/v1/wallet/conversions/summary')->assertOk()
            ->assertJsonPath('data.summary.counts_scope', 'all_currencies')
            ->assertJsonPath('data.summary.completed_volume_in', '0.00')
            ->assertJsonPath('data.summary.fee_total', '0.00')
            ->assertJsonPath('data.summary.by_currency.THB.completed_volume_in', '0.00');
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $this->assertSame('0.00', $this->balanceOf($member['win']));
    }

    public function test_fractional_completed_amounts_are_added_as_exact_decimal_strings(): void
    {
        $member = $this->makeFundedMember();
        $this->convert($member['user'], '10.03', idempotencyKey: 'fractional-summary-key-000001');
        $this->convert($member['user'], '10.07', idempotencyKey: 'fractional-summary-key-000002');
        Sanctum::actingAs($member['user']);
        $this->getJson('/api/v1/wallet/conversions/summary')->assertOk()
            ->assertJsonPath('data.summary.completed_volume_in', '20.10')
            ->assertJsonPath('data.summary.completed_volume_out', '19.90')
            ->assertJsonPath('data.summary.fee_total', '0.20');
    }

    private function assertCurrencyRefused(callable $operation, string $code): void
    {
        try {
            $operation();
            $this->fail('An invalid denomination must not be accepted.');
        } catch (ConversionException $exception) {
            $this->assertSame($code, $exception->errorCode());
        }
    }

    private function configureCurrency(string $currency): void
    {
        config(['conversions.currency' => $currency, 'fees.categories.cash_to_win.currency' => $currency,
            'fees.categories.win_to_cash.currency' => $currency]);
    }

    private function addCurrencyWallets(int $userId, Currency $currency = Currency::USD): void
    {
        foreach ([WalletType::Primary, WalletType::Win] as $type) {
            $wallet = Wallet::query()->create(['user_id' => $userId, 'type' => $type, 'currency' => $currency]);
            $wallet->forceFill(['status' => WalletStatus::Active, 'balance' => $type === WalletType::Primary ? '1000.00' : '0.00'])->save();
        }
    }

    private function assertUntouched(array $member, int $expectedQuotes = 0): void
    {
        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
        $this->assertSame('0.00', $this->balanceOf($member['win']));
        $this->assertSame(0, WalletConversion::query()->count());
        $this->assertSame($expectedQuotes, WalletConversionQuote::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }
}
