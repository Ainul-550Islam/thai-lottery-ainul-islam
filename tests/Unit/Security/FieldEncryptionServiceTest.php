<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Security\FieldEncryptionService;
use PHPUnit\Framework\TestCase;

class FieldEncryptionServiceTest extends TestCase
{
    private FieldEncryptionService $encryptionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->encryptionService = new FieldEncryptionService('TestSecretKeyForEncryption2026!');
    }

    public function test_encrypts_and_decrypts_sensitive_pii(): void
    {
        $sensitiveId = '1234567890125';

        $encrypted = $this->encryptionService->encrypt($sensitiveId);
        $this->assertNotEmpty($encrypted);
        $this->assertNotSame($sensitiveId, $encrypted);

        $decrypted = $this->encryptionService->decrypt($encrypted);
        $this->assertSame($sensitiveId, $decrypted);
    }

    public function test_produces_unique_ciphertexts_due_to_random_iv(): void
    {
        $text = '0812345678';

        $enc1 = $this->encryptionService->encrypt($text);
        $enc2 = $this->encryptionService->encrypt($text);

        // Random IV ensures ciphertexts are distinct
        $this->assertNotSame($enc1, $enc2);

        // Both decrypt to the same plain text
        $this->assertSame($text, $this->encryptionService->decrypt($enc1));
        $this->assertSame($text, $this->encryptionService->decrypt($enc2));
    }

    public function test_throws_exception_on_tampered_ciphertext(): void
    {
        $encrypted = $this->encryptionService->encrypt('SecretData');
        $rawBytes = base64_decode($encrypted);

        // Tamper with the ciphertext byte
        $tamperedBytes = $rawBytes;
        $tamperedBytes[strlen($tamperedBytes) - 1] = chr(ord($tamperedBytes[strlen($tamperedBytes) - 1]) ^ 0xFF);
        $tamperedPayload = base64_encode($tamperedBytes);

        $this->expectException(\RuntimeException::class);
        $this->encryptionService->decrypt($tamperedPayload);
    }
}
