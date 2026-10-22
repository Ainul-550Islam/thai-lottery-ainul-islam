<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

/** Missing/invalid provider facts must not silently acquire a country's defaults. */
final class WebhookMoneyFactsTest extends PaymentTestCase
{
    public static function currencyCases(): array
    {
        $cases = [];
        foreach (['stripe' => Currency::THB, 'bkash' => Currency::BDT, 'nagad' => Currency::BDT, 'crypto' => Currency::USD] as $driver => $valid) {
            foreach (['missing' => null, 'invalid' => 'NOT_A_CURRENCY', 'numeric' => 123, 'valid' => strtolower($valid->value)] as $case => $input) {
                $cases[$driver.' '.$case] = [$driver, $input, $case === 'valid' ? $valid : null];
            }
        }

        return $cases;
    }

    private function parse(string $driver, array $facts): WebhookPayload
    {
        $body = $driver === 'stripe' ? ['data' => ['object' => $facts]] : $facts;
        $request = Request::create('/webhook/'.$driver, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));

        return $this->gatewayManager()->driver($driver)->parseWebhook($request);
    }

    #[DataProvider('currencyCases')]
    public function test_currency_is_explicit_or_null_never_a_country_fallback(string $driver, mixed $input, ?Currency $expected): void
    {
        $facts = ['amount' => '40000'];
        if ($input !== null) {
            $facts['currency'] = $input;
        }
        $this->assertSame($expected, $this->parse($driver, $facts)->currency);
    }

    public static function drivers(): array
    {
        return array_combine(['stripe', 'bkash', 'nagad', 'crypto'], array_map(fn ($v) => [$v], ['stripe', 'bkash', 'nagad', 'crypto']));
    }

    #[DataProvider('drivers')]
    public function test_missing_amount_is_null_not_an_invented_zero(string $driver): void
    {
        $this->assertNull($this->parse($driver, ['currency' => 'USD'])->amount);
    }

    public static function invalidMinorUnits(): array
    {
        return [['40000.9'], ['4e4'], [-1], [[]], [40000.1], ['not-money']];
    }

    #[DataProvider('invalidMinorUnits')]
    public function test_stripe_minor_units_must_be_exact_nonnegative_integers(mixed $amount): void
    {
        $this->assertNull($this->parse('stripe', ['currency' => 'THB', 'amount_total' => $amount])->amount);
    }

    public function test_stripe_valid_minor_units_normalize_without_float_or_rounding(): void
    {
        $this->assertSame('400.09', $this->parse('stripe', ['currency' => 'THB', 'amount_total' => 40009])->amount);
    }
}
