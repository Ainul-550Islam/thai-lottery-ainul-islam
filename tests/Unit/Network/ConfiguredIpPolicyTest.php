<?php

declare(strict_types=1);

namespace Tests\Unit\Network;

use App\Services\Network\ConfiguredIpPolicy;
use App\Services\Network\IpRangeMatcher;
use Illuminate\Config\Repository;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfiguredIpPolicyTest extends TestCase
{
    private function assess(array $config, mixed $address = '192.0.2.10'): array
    {
        return (new ConfiguredIpPolicy(new Repository(['ip_access' => $config]), new IpRangeMatcher))->assess($address);
    }

    public function test_defaults_are_empty_log_only_and_not_a_risk_score(): void
    {
        self::assertSame(['mode' => 'log', 'code' => 'NOT_LISTED'], $this->assess([]));
        self::assertSame(['mode' => 'log', 'code' => 'LISTED'], $this->assess(['blocked_ranges' => ['192.0.2.0/24']]));
        self::assertSame(['mode' => 'enforce', 'code' => 'LISTED'], $this->assess(['mode' => 'enforce', 'blocked_ranges' => ['192.0.2.0/24']]));
    }

    public function test_disabled_policy_does_not_apply_dormant_list(): void
    {
        self::assertSame(['mode' => 'enforce', 'code' => 'DISABLED'], $this->assess(['enabled' => false, 'mode' => 'enforce', 'blocked_ranges' => ['bad']]));
    }

    public function test_mapped_ipv6_and_unknown_address_are_explicit(): void
    {
        $result = $this->assess(['blocked_ranges' => ['192.0.2.0/24']], '::ffff:192.0.2.10');
        self::assertSame('LISTED', $result['code']);
        self::assertStringNotContainsString('192.0.2', json_encode($result));
        self::assertSame('ADDRESS_UNAVAILABLE', $this->assess([], null)['code']);
        self::assertSame('ADDRESS_UNAVAILABLE', $this->assess([], 'invalid')['code']);
    }

    public function test_nonmatch_and_duplicate_ranges_are_supported(): void
    {
        self::assertSame('NOT_LISTED', $this->assess(['blocked_ranges' => ['2001:db8::/32']])['code']);
        self::assertSame('LISTED', $this->assess(['blocked_ranges' => ['192.0.2.10', '192.0.2.10']])['code']);
    }

    public static function invalidPolicies(): array
    {
        return [
            [['mode' => 'off']], [['enabled' => 'true']], [['blocked_ranges' => '*']],
            [['blocked_ranges' => ['*']]], [['blocked_ranges' => ['a' => '192.0.2.10']]],
            [['blocked_ranges' => ['192.0.2.0/24', 'bad']]],
            [['blocked_ranges' => array_fill(0, 257, '192.0.2.1')]],
            [['blocked_ranges' => [null]]],
        ];
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_configuration_never_silently_allows_or_partially_applies(array $config): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid IP access policy.');
        $this->assess($config);
    }
}
