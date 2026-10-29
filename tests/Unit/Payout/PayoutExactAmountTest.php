<?php

declare(strict_types=1);

namespace Tests\Unit\Payout;

use App\Exceptions\FinancialException;
use App\Services\Payout\ThaiBank\KbankPayoutAdapter;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PayoutExactAmountTest extends TestCase
{
    public static function amountCases(): array
    {
        $cases = [
            'nested amount must beat unrelated decoy' => ['{"reference":"REF-1","status":"SUCCESS","other":{"amount":100.00},"data":{"amount":1.23,"currency":"THB"}}', '1.23'],
            'missing amount must not use unrelated decoy' => ['{"reference":"REF-1","status":"SUCCESS","other":{"amount":100.00},"data":{"currency":"THB"}}', null],
            'exponent must not be truncated' => ['{"reference":"REF-1","status":"SUCCESS","data":{"amount":100.00e-2,"currency":"THB"}}', null],
            'string amount' => ['{"reference":"REF-1","status":"SUCCESS","data":{"amount":"100.00","currency":"THB"}}', '100.00'],
            'root fallback exact amount' => ['{"reference":"REF-1","status":"SUCCESS","amount":1.23,"data":{"currency":"THB"}}', '1.23'],
        ];
        $result = [];
        foreach (['SCB' => ScbPayoutAdapter::class, 'KBank' => KbankPayoutAdapter::class] as $name => $adapter) {
            foreach ($cases as $case => [$json, $expected]) {
                $result[$name.' '.$case] = [$adapter, $json, $expected];
            }
        }

        return $result;
    }

    #[DataProvider('amountCases')]
    public function test_callback_amount_uses_only_the_declared_json_path(string $adapterClass, string $json, ?string $expected): void
    {
        $facts = (new $adapterClass)->parseCallback($json);
        self::assertSame($expected, $facts['amount']);
        self::assertSame('REF-1', $facts['reference']);
        self::assertSame('SUCCESS', $facts['state']);
    }

    public static function adapters(): array
    {
        return ['SCB' => [ScbPayoutAdapter::class], 'KBank' => [KbankPayoutAdapter::class]];
    }

    #[DataProvider('adapters')]
    public function test_duplicate_callback_keys_are_rejected_even_if_escaped(string $adapterClass): void
    {
        $json = '{"reference":"REF-1","status":"SUCCESS","st\\u0061tus":"FAILED","amount":"100.00"}';
        try {
            (new $adapterClass)->parseCallback($json);
            self::fail('An ambiguous callback must not have a definitive outcome.');
        } catch (FinancialException $exception) {
            self::assertSame('payout_webhook_payload_invalid', $exception->errorCode());
        }
    }
}
