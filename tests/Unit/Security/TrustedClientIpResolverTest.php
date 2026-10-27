<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Network\IpCidrMatcher;
use App\Services\Network\TrustedClientIpResolver;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class TrustedClientIpResolverTest extends TestCase
{
    private TrustedClientIpResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TrustedClientIpResolver(new IpCidrMatcher);
    }

    public function test_resolves_direct_client_ip_when_no_forward_headers(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.195',
        ]);

        $ip = $this->resolver->resolveClientIp($request);
        $this->assertSame('203.0.113.195', $ip);
    }

    public function test_ignores_forwarded_headers_from_untrusted_direct_socket(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.195',
            'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 8.8.8.8',
        ]);

        $trustedProxies = ['10.0.0.0/8', '172.16.0.0/12'];
        $ip = $this->resolver->resolveClientIp($request, $trustedProxies);

        // Direct REMOTE_ADDR is 203.0.113.195 (not in trusted proxy CIDRs), so headers must NOT be trusted
        $this->assertSame('203.0.113.195', $ip);
    }

    public function test_resolves_true_client_ip_when_behind_trusted_load_balancer(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.0.1.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42, 10.0.1.20',
        ]);

        $trustedProxies = ['10.0.0.0/8'];
        $ip = $this->resolver->resolveClientIp($request, $trustedProxies);

        // 10.0.1.5 is trusted, 10.0.1.20 is trusted, first untrusted IP is 198.51.100.42
        $this->assertSame('198.51.100.42', $ip);
    }
}
