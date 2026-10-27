<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\IpBlocklistMiddleware;
use App\Models\BlockedIp;
use App\Services\Network\IpBlocklistService;
use App\Services\Network\IpCidrMatcher;
use App\Services\Network\TrustedClientIpResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class IpBlocklistMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private IpBlocklistService $blocklistService;

    private IpBlocklistMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->blocklistService = new IpBlocklistService(new IpCidrMatcher);
        $this->middleware = new IpBlocklistMiddleware(
            $this->blocklistService,
            new TrustedClientIpResolver(new IpCidrMatcher)
        );
    }

    public function test_allows_unblocked_ip(): void
    {
        $request = Request::create('/api/v1/health', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        $response = $this->middleware->handle($request, fn () => new Response('OK', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_blocks_explicitly_blocked_ip(): void
    {
        BlockedIp::create([
            'id' => (string) Str::uuid(),
            'ip_or_cidr' => '203.0.113.55',
            'ip_version' => 4,
            'is_cidr' => false,
            'reason_code' => 'BRUTE_FORCE',
            'is_active' => true,
        ]);
        $this->blocklistService->invalidateCache();

        $request = Request::create('/api/v1/auth/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.55']);

        $response = $this->middleware->handle($request, fn () => new Response('OK', 200));

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('IP_ADDRESS_BLOCKED', $data['error_code']);
    }

    public function test_blocks_ip_inside_blocked_cidr_subnet(): void
    {
        BlockedIp::create([
            'id' => (string) Str::uuid(),
            'ip_or_cidr' => '198.51.100.0/24',
            'ip_version' => 4,
            'is_cidr' => true,
            'reason_code' => 'THREAT_FEED',
            'is_active' => true,
        ]);
        $this->blocklistService->invalidateCache();

        $request = Request::create('/api/v1/orders', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.42']);

        $response = $this->middleware->handle($request, fn () => new Response('OK', 200));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_allows_expired_blocked_ip(): void
    {
        BlockedIp::create([
            'id' => (string) Str::uuid(),
            'ip_or_cidr' => '203.0.113.99',
            'ip_version' => 4,
            'is_cidr' => false,
            'reason_code' => 'TEMPORARY_BLOCK',
            'is_active' => true,
            'expires_at' => now()->subMinute(),
        ]);
        $this->blocklistService->invalidateCache();

        $request = Request::create('/api/v1/health', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.99']);

        $response = $this->middleware->handle($request, fn () => new Response('OK', 200));

        $this->assertSame(200, $response->getStatusCode());
    }
}
