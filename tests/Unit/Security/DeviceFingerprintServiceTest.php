<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Bot\DeviceFingerprintService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class DeviceFingerprintServiceTest extends TestCase
{
    private DeviceFingerprintService $fingerprintService;

    protected function setUp(): void
    {
        parent::setUp();
        config(['bot.fingerprint_hmac_key' => 'test-secret-hmac-key-2026']);
        $this->fingerprintService = new DeviceFingerprintService();
    }

    public function test_generates_consistent_hmac_fingerprint_for_identical_clients(): void
    {
        $req1 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br',
        ]);

        $req2 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
            'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br',
        ]);

        $fp1 = $this->fingerprintService->generateFingerprint($req1);
        $fp2 = $this->fingerprintService->generateFingerprint($req2);

        $this->assertNotEmpty($fp1);
        $this->assertSame($fp1, $fp2);
        $this->assertSame(64, strlen($fp1)); // SHA-256 HMAC length
    }

    public function test_produces_distinct_fingerprints_for_differing_headers(): void
    {
        $req1 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
        ]);

        $req2 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X)',
            'HTTP_ACCEPT_LANGUAGE' => 'th-TH,th;q=0.9',
        ]);

        $fp1 = $this->fingerprintService->generateFingerprint($req1);
        $fp2 = $this->fingerprintService->generateFingerprint($req2);

        $this->assertNotSame($fp1, $fp2);
    }
}
