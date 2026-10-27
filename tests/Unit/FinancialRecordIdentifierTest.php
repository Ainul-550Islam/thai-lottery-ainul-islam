<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\FinancialException;
use App\Models\Wallet;
use App\Services\Finance\FinancialRecordIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FinancialRecordIdentifierTest extends TestCase
{
    public static function canonical(): array
    {
        return [[1, 1], [PHP_INT_MAX, PHP_INT_MAX], ['1', 1], [(string) PHP_INT_MAX, PHP_INT_MAX], ['9007199254740993', 9007199254740993]];
    }

    #[DataProvider('canonical')]
    public function test_canonical_keys_preserve_exact_identity(int|string $value, int $expected): void
    {
        $this->assertSame($expected, FinancialRecordIdentifier::parse($value));
    }

    public static function invalid(): array
    {
        return [[null], [true], [false], [0], [-1], [1.0], [1.9], [INF], [NAN], [''], ['0'], ['01'], ['+1'], ['-1'], ['1.0'], ['1e0'], ['1suffix'], [' 1'], ["1\n"], ['１'], ['١'], [(string) PHP_INT_MAX.'0'], [str_repeat('1', 10000)], [[]]];
    }

    #[DataProvider('invalid')]
    public function test_lossy_and_noncanonical_values_are_refused(mixed $value): void
    {
        try {
            FinancialRecordIdentifier::parse($value);
            $this->fail('Lossy key conversion was admitted.');
        } catch (FinancialException $exception) {
            $this->assertSame('financial_identifier_invalid', $exception->errorCode());
        }
    }

    public function test_objects_are_not_stringified(): void
    {
        $value = new class
        {
            public function __toString(): string
            {
                throw new \LogicException('Untrusted object executed.');
            }
        };
        $this->expectException(FinancialException::class);
        FinancialRecordIdentifier::parse($value);
    }

    public function test_raw_current_key_is_checked_before_eloquent_can_hide_the_damage(): void
    {
        $wallet = new Wallet;
        $wallet->setRawAttributes(['id' => 1], true);
        $wallet->id = '1suffix';
        $this->assertSame(1, $wallet->getKey(), 'The implicit Eloquent key cast is precisely why raw validation is necessary.');
        $this->assertSame(1, $wallet->getRawOriginal('id'), 'The original snapshot is not the current routing input.');
        $this->expectException(FinancialException::class);
        FinancialRecordIdentifier::fromModel($wallet);
    }
}
