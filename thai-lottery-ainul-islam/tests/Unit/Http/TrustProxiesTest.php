<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Middleware\TrustProxies;
use Illuminate\Config\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class TrustProxiesTest extends TestCase
{
    private array $previousProxies;

    private int $previousHeaders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousProxies = Request::getTrustedProxies();
        $this->previousHeaders = Request::getTrustedHeaderSet();
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->previousProxies, $this->previousHeaders);
        parent::tearDown();
    }

    private function request(string $peer, string $forwarded = '198.51.100.20'): Request
    {
        return Request::create('http://origin.test:8080/probe', 'GET', [], [], [], [
            'REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_FOR' => $forwarded,
            'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443',
            'HTTP_X_FORWARDED_HOST' => 'attacker.test', 'HTTP_X_FORWARDED_PREFIX' => '/evil',
            'HTTP_FORWARDED' => 'for=203.0.113.66;proto=https;host=attacker.test',
        ]);
    }

    private function runMiddleware(mixed $proxies, Request $request): void
    {
        $middleware = new TrustProxies(new Repository(['network' => ['trusted_proxies' => $proxies]]));
        $response = $middleware->handle($request, fn () => new Response('downstream'));
        self::assertSame('downstream', $response->getContent());
    }

    public function test_default_trusts_no_proxy_even_for_platform_looking_host(): void
    {
        $request = $this->request('192.0.2.10');
        $request->headers->set('Host', 'pretend.on-forge.com');
        $middleware = new TrustProxies(new Repository(['trustedproxy' => ['proxies' => '*']]));
        $middleware->handle($request, fn () => new Response);
        self::assertSame('192.0.2.10', $request->ip());
        self::assertFalse($request->isSecure());
        self::assertSame([], Request::getTrustedProxies());
    }

    public function test_untrusted_peer_cannot_spoof_address_scheme_port_host_or_prefix(): void
    {
        $request = $this->request('192.0.2.10');
        $this->runMiddleware(['10.0.0.0/24'], $request);
        self::assertSame('192.0.2.10', $request->ip());
        self::assertFalse($request->isSecure());
        self::assertSame(8080, $request->getPort());
        self::assertSame('origin.test', $request->getHost());
        self::assertSame('', $request->getBaseUrl());
    }

    public static function trustedPeers(): array
    {
        return [
            [['192.0.2.10'], '192.0.2.10'],
            [['192.0.2.0/24'], '192.0.2.255'],
            [['192.0.2.10/32'], '192.0.2.10'],
            [['2001:db8:abcd::/48'], '2001:db8:abcd::10'],
            [['2001:db8::1/128'], '2001:db8::1'],
        ];
    }

    #[DataProvider('trustedPeers')]
    public function test_explicit_ipv4_ipv6_peers_can_forward_only_selected_headers(array $proxies, string $peer): void
    {
        $request = $this->request($peer);
        $this->runMiddleware($proxies, $request);
        self::assertSame('198.51.100.20', $request->ip());
        self::assertTrue($request->isSecure());
        self::assertSame(443, $request->getPort());
        self::assertSame('origin.test', $request->getHost());
        self::assertSame('', $request->getBaseUrl());
    }

    public function test_peer_outside_ipv6_allowlist_is_not_trusted(): void
    {
        $request = $this->request('2001:db8:abce::1');
        $this->runMiddleware(['2001:db8:abcd::/48'], $request);
        self::assertSame('2001:db8:abce::1', $request->ip());
        self::assertFalse($request->isSecure());
    }

    public function test_chain_ignores_spoofed_leftmost_address_before_untrusted_hop(): void
    {
        $request = $this->request('10.0.0.2', '203.0.113.99, 198.51.100.7, 10.0.0.1');
        $this->runMiddleware(['10.0.0.0/24'], $request);
        self::assertSame('198.51.100.7', $request->ip());
    }

    public function test_forwarded_header_alone_is_ignored_even_from_trusted_peer(): void
    {
        $request = $this->request('10.0.0.2');
        foreach (['X-Forwarded-For', 'X-Forwarded-Proto', 'X-Forwarded-Port'] as $name) {
            $request->headers->remove($name);
        }
        $this->runMiddleware(['10.0.0.2'], $request);
        self::assertSame('10.0.0.2', $request->ip());
        self::assertFalse($request->isSecure());
    }

    public function test_trust_is_reset_when_next_request_has_empty_config(): void
    {
        $first = $this->request('10.0.0.2');
        $this->runMiddleware(['10.0.0.2'], $first);
        self::assertTrue($first->isSecure());
        $next = $this->request('10.0.0.2');
        $this->runMiddleware([], $next);
        self::assertSame('10.0.0.2', $next->ip());
        self::assertFalse($next->isSecure());
    }

    public static function invalidConfiguration(): array
    {
        return [
            [null], ['*'], [['*']], [['**']], [['REMOTE_ADDR']], [['proxy.example.test']],
            [['0.0.0.0/0']], [['::/0']], [['192.0.2.1/33']], [['2001:db8::1/129']],
            [['192.0.2.1/-1']], [['192.0.2.1/024']], [['192.0.2.1/24/1']],
            [['192.0.2.1:443']], [['https://192.0.2.1']], [['fe80::1%eth0']],
            [[' 192.0.2.1']], [['']], [[false]], [['a' => '192.0.2.1']],
            [array_fill(0, 65, '192.0.2.1')], [['192.0.2.1', 'bad-entry']],
        ];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_config_returns_503_before_downstream_and_clears_old_trust(mixed $proxies): void
    {
        Request::setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_FOR);
        $called = false;
        $middleware = new TrustProxies(new Repository(['network' => ['trusted_proxies' => $proxies]]));
        try {
            $middleware->handle($this->request('192.0.2.10'), function () use (&$called) {
                $called = true;

                return new Response;
            });
            self::fail('Expected invalid configuration to fail closed.');
        } catch (HttpResponseException $exception) {
            self::assertSame(503, $exception->getResponse()->getStatusCode());
            self::assertSame('service_unavailable', $exception->getResponse()->getData(true)['error']['code']);
        }
        self::assertFalse($called);
        self::assertSame([], Request::getTrustedProxies());
    }
}
