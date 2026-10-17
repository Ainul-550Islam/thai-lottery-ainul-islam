<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Services\Auth\TotpMfaService;
use PHPUnit\Framework\TestCase;

class TotpMfaServiceTest extends TestCase
{
    private TotpMfaService $mfaService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mfaService = new TotpMfaService();
    }

    public function test_generates_valid_base32_secret(): void
    {
        $secret = $this->mfaService->generateSecret(32);

        $this->assertSame(32, strlen($secret));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function test_generates_valid_otpauth_uri(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $uri = $this->mfaService->getOtpAuthUri($secret, 'operator@thai-lottery.internal', 'ThaiLottery');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=ThaiLottery', $uri);
    }

    public function test_verifies_code_at_current_and_drift_time_steps(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $fixedTimestamp = 1609459200; // 2021-01-01 00:00:00 UTC

        // Calculate expected code at fixed timestamp
        $currentCounter = (int) floor($fixedTimestamp / 30);
        $validCode = $this->mfaService->calculateTotpAtCounter($secret, $currentCounter);

        // 1. Exact current time verification
        $this->assertTrue($this->mfaService->verifyCode($secret, $validCode, $fixedTimestamp));

        // 2. Drift -20 seconds (still within ±1 window)
        $this->assertTrue($this->mfaService->verifyCode($secret, $validCode, $fixedTimestamp - 20));

        // 3. Drift +20 seconds (still within ±1 window)
        $this->assertTrue($this->mfaService->verifyCode($secret, $validCode, $fixedTimestamp + 20));

        // 4. Drift +120 seconds (outside ±1 window -> false)
        $this->assertFalse($this->mfaService->verifyCode($secret, $validCode, $fixedTimestamp + 120));

        // 5. Invalid Code
        $this->assertFalse($this->mfaService->verifyCode($secret, '000000', $fixedTimestamp));
    }

    public function test_generates_8_distinct_recovery_codes(): void
    {
        $codes = $this->mfaService->generateRecoveryCodes(8);

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code);
        }
    }
}
