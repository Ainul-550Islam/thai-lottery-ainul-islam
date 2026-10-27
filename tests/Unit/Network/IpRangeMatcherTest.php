<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use App\Services\Network\IpRangeMatcher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpRangeMatcherTest extends TestCase
{
    public static function matchingCases(): array
    {
        return [
            ['192.0.2.0', '192.0.2.0/24', true], ['192.0.2.255', '192.0.2.0/24', true],
            ['192.0.3.0', '192.0.2.0/24', false], ['192.0.2.1', '192.0.2.1/32', true],
            ['192.0.2.2', '192.0.2.1', false], ['192.0.2.1', '0.0.0.0/0', true],
            ['2001:db8:1::ffff', '2001:db8:1::/48', true], ['2001:db8:2::1', '2001:db8:1::/48', false],
            ['2001:db8::1', '2001:0DB8:0:0:0:0:0:1/128', true], ['2001:db8::2', '2001:db8::1/128', false],
            ['2001:db8::1', '::/0', true], ['192.0.2.1', '::/0', false],
            ['::ffff:192.0.2.8', '192.0.2.0/24', true], ['192.0.2.8', '::ffff:192.0.2.0/120', true],
            ['::ffff:c000:0208', '192.0.2.8', true], ['2001:db8::1', '0.0.0.0/0', false],
        ];
    }

    #[DataProvider('matchingCases')]
    public function test_literal_and_cidr_matching(string $address, string $range, bool $expected): void
    {
        self::assertSame($expected, (new IpRangeMatcher)->matches($address, $range));
    }

    public static function badRanges(): array
    {
        return [['*'], ['example.test'], ['192.0.2.1:443'], ['https://192.0.2.1'], ['192.0.2.1/33'], ['::/129'], ['::ffff:192.0.2.0/95'], ['192.0.2.1/024'], ['192.0.2.1/-1'], ['192.0.2.1/24/1'], ['fe80::1%eth0'], [' 192.0.2.1'], ['192.0.2.1 '], ['192.168.001.1'], ['']];
    }

    #[DataProvider('badRanges')]
    public function test_malformed_ranges_are_not_silently_treated_as_nonmatches(string $range): void
    {
        $matcher = new IpRangeMatcher;
        self::assertNull($matcher->normalizeRange($range));
        $this->expectException(InvalidArgumentException::class);
        $matcher->matches('192.0.2.1', $range);
    }

    public function test_normalization_and_invalid_address_types(): void
    {
        $matcher = new IpRangeMatcher;
        self::assertSame('2001:db8::1', $matcher->normalizeAddress('2001:0DB8:0:0:0:0:0:1'));
        self::assertSame('192.0.2.8', $matcher->normalizeAddress('::ffff:c000:0208'));
        self::assertSame('192.0.2.0/24', $matcher->normalizeRange('::ffff:192.0.2.0/120'));
        foreach ([null, true, [], '', 'unknown', '192.0.2.1, 192.0.2.2'] as $value) {
            self::assertNull($matcher->normalizeAddress($value));
        }
        $this->expectException(InvalidArgumentException::class);
        $matcher->matches('invalid', '192.0.2.0/24');
    }
}
