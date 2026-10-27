<?php

declare(strict_types=1);

namespace Tests\Unit\Bot;

use App\Services\Bot\DeviceFingerprintService;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceFingerprintServiceTest extends TestCase
{
    private function service(?string $key = null): DeviceFingerprintService
    {
        return new DeviceFingerprintService(new Repository(['bot' => ['fingerprint' => ['key' => $key ?? str_repeat('k', 32)]]]));
    }

    private function request(string $ip, string $ua = 'Browser/1.0', string $language = 'en-US'): Request
    {
        return Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $ua,
            'HTTP_ACCEPT_LANGUAGE' => $language,
        ]);
    }

    public function test_deterministic_64_hex_and_ipv4_network_privacy(): void
    {
        $service = $this->service();
        $value = $service->fingerprint($this->request('192.0.2.1'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $value);
        self::assertSame($value, $service->fingerprint($this->request('192.0.2.254')));
        self::assertNotSame($value, $service->fingerprint($this->request('192.0.3.1')));
    }

    public function test_ipv6_canonicalization_and_64_bit_network(): void
    {
        $service = $this->service();
        $value = $service->fingerprint($this->request('2001:db8:1:2::1'));
        self::assertSame($value, $service->fingerprint($this->request('2001:0db8:0001:0002:ffff:ffff:ffff:ffff')));
        self::assertNotSame($value, $service->fingerprint($this->request('2001:db8:1:3::1')));
        self::assertSame(
            $service->fingerprint($this->request('192.0.2.1')),
            $service->fingerprint($this->request('::ffff:192.0.2.1')),
        );
    }

    public function test_headers_and_key_are_bound_to_the_fingerprint(): void
    {
        $request = $this->request('192.0.2.1');
        $service = $this->service();
        $original = $service->fingerprint($request);
        self::assertNotSame($original, $service->fingerprint($this->request('192.0.2.1', 'Other/1')));
        self::assertNotSame($original, $service->fingerprint($this->request('192.0.2.1', language: 'th-TH')));
        self::assertNotSame($original, $this->service(str_repeat('z', 32))->fingerprint($request));
        self::assertSame($original, $this->service('base64:'.base64_encode(str_repeat('k', 32)))->fingerprint($request));
    }

    public function test_normalized_whitespace_and_bounded_headers(): void
    {
        $service = $this->service();
        self::assertSame(
            $service->fingerprint($this->request('192.0.2.1', 'Browser  One')),
            $service->fingerprint($this->request('192.0.2.1', ' Browser One ')),
        );
        self::assertSame(
            $service->fingerprint($this->request('192.0.2.1', str_repeat('a', 2048).'x')),
            $service->fingerprint($this->request('192.0.2.1', str_repeat('a', 2048).'y')),
        );
    }

    public function test_untrusted_forwarded_headers_do_not_choose_the_fingerprint(): void
    {
        $proxies = Request::getTrustedProxies();
        $headers = Request::getTrustedHeaderSet();
        try {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
            $request = $this->request('192.0.2.1');
            $original = $this->service()->fingerprint($request);
            $request->headers->set('X-Forwarded-For', '203.0.113.10');
            $request->headers->set('X-Device-Fingerprint', str_repeat('a', 64));
            self::assertSame($original, $this->service()->fingerprint($request));
        } finally {
            Request::setTrustedProxies($proxies, $headers);
        }
    }

    public static function invalidKeys(): array
    {
        return [[''], ['short'], ['base64:!!!'], ['base64:'.base64_encode('short')]];
    }

    #[DataProvider('invalidKeys')]
    public function test_invalid_keys_are_refused(string $key): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service($key)->fingerprint($this->request('192.0.2.1'));
    }

    public function test_missing_key_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        (new DeviceFingerprintService(new Repository))->fingerprint($this->request('192.0.2.1'));
    }

    public function test_invalid_client_ip_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->fingerprint($this->request('not-an-ip'));
    }
}
