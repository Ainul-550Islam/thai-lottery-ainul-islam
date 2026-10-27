<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Bot\DeviceFingerprintService;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class DeviceFingerprintServiceTest extends TestCase
{
    private DeviceFingerprintService $fingerprintService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprintService = new DeviceFingerprintService(new Repository([
            'bot' => [
                'fingerprint' => [
                    'key' => str_repeat('k', 32),
                ],
            ],
        ]));
    }

    public function test_generates_consistent_hmac_fingerprint_for_identical_clients(): void
    {
        $req1 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.12',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
        ]);

        $req2 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.45', // same /24 subnet normalizes identically
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
        ]);

        $fp1 = $this->fingerprintService->fingerprint($req1);
        $fp2 = $this->fingerprintService->fingerprint($req2);

        $this->assertSame(64, strlen($fp1));
        $this->assertSame($fp1, $fp2);
    }

    public function test_produces_distinct_fingerprints_for_differing_headers(): void
    {
        $req1 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.12',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
        ]);

        $req2 = Request::create('/api/v1/orders', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.12',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5 like Mac OS X)',
            'HTTP_ACCEPT_LANGUAGE' => 'th-TH,th;q=0.9',
        ]);

        $this->assertNotSame(
            $this->fingerprintService->fingerprint($req1),
            $this->fingerprintService->fingerprint($req2),
        );
    }
}
