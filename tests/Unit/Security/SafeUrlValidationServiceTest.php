<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\ThreatIntel\SafeUrlValidationService;
use PHPUnit\Framework\TestCase;

class SafeUrlValidationServiceTest extends TestCase
{
    private SafeUrlValidationService $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new SafeUrlValidationService();
    }

    public function test_rejects_loopback_and_rfc1918_private_urls(): void
    {
        $this->assertFalse($this->validator->isSafeExternalUrl('http://127.0.0.1/admin'));
        $this->assertFalse($this->validator->isSafeExternalUrl('http://localhost:8080/metrics'));
        $this->assertFalse($this->validator->isSafeExternalUrl('http://10.0.1.5/webhook'));
        $this->assertFalse($this->validator->isSafeExternalUrl('http://192.168.1.1/router'));
        $this->assertFalse($this->validator->isSafeExternalUrl('http://172.16.0.10/api'));
    }

    public function test_rejects_cloud_metadata_endpoints(): void
    {
        $this->assertFalse($this->validator->isSafeExternalUrl('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_rejects_non_http_schemes(): void
    {
        $this->assertFalse($this->validator->isSafeExternalUrl('file:///etc/passwd'));
        $this->assertFalse($this->validator->isSafeExternalUrl('gopher://127.0.0.1:6379'));
        $this->assertFalse($this->validator->isSafeExternalUrl('ftp://example.com/file'));
    }

    public function test_accepts_valid_public_fqdn_and_ips(): void
    {
        $this->assertTrue($this->validator->isSafeExternalUrl('https://api.glo.or.th/v1/results'));
        $this->assertTrue($this->validator->isSafeExternalUrl('https://1.1.1.1/dns-query'));
    }
}
