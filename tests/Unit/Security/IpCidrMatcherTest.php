<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Network\IpCidrMatcher;
use PHPUnit\Framework\TestCase;

class IpCidrMatcherTest extends TestCase
{
    private IpCidrMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new IpCidrMatcher();
    }

    public function test_exact_ipv4_matching(): void
    {
        $this->assertTrue($this->matcher->ipMatchesPattern('192.168.1.50', '192.168.1.50'));
        $this->assertFalse($this->matcher->ipMatchesPattern('192.168.1.51', '192.168.1.50'));
    }

    public function test_ipv4_cidr_ranges(): void
    {
        $this->assertTrue($this->matcher->ipMatchesPattern('192.168.1.100', '192.168.1.0/24'));
        $this->assertTrue($this->matcher->ipMatchesPattern('10.250.1.1', '10.0.0.0/8'));
        $this->assertFalse($this->matcher->ipMatchesPattern('192.169.1.100', '192.168.1.0/24'));
        $this->assertTrue($this->matcher->ipMatchesPattern('172.16.5.4', '0.0.0.0/0'));
    }

    public function test_exact_ipv6_matching(): void
    {
        $this->assertTrue($this->matcher->ipMatchesPattern('2001:0db8:85a3:0000:0000:8a2e:0370:7334', '2001:db8:85a3::8a2e:370:7334'));
        $this->assertFalse($this->matcher->ipMatchesPattern('2001:0db8:85a3:0000:0000:8a2e:0370:7335', '2001:db8:85a3::8a2e:370:7334'));
    }

    public function test_ipv6_cidr_ranges(): void
    {
        $this->assertTrue($this->matcher->ipMatchesPattern('2001:db8:abcd:0012::1', '2001:db8:abcd::/48'));
        $this->assertFalse($this->matcher->ipMatchesPattern('2001:db8:abce:0012::1', '2001:db8:abcd::/48'));
        $this->assertTrue($this->matcher->ipMatchesPattern('2001:db8::1', '::/0'));
    }

    public function test_malformed_inputs_handled_safely(): void
    {
        $this->assertFalse($this->matcher->ipMatchesPattern('999.999.999.999', '192.168.1.0/24'));
        $this->assertFalse($this->matcher->ipMatchesPattern('invalid-ip', 'invalid-pattern'));
        $this->assertFalse($this->matcher->ipMatchesPattern('192.168.1.1', '192.168.1.1/33'));
        $this->assertFalse($this->matcher->ipMatchesPattern('2001:db8::1', '2001:db8::1/129'));
    }
}
