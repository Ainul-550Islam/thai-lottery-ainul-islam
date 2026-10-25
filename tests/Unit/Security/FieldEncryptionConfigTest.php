<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Security\FieldEncryptionService;
use RuntimeException;
use Tests\TestCase;

final class FieldEncryptionConfigTest extends TestCase
{
    public function test_dedicated_config_key_is_used_and_fingerprint_identifies_derived_key(): void
    {
        $dedicated = 'dedicated-field-key-for-deterministic-tests';
        config([
            'app.field_encryption_key' => $dedicated,
            'app.key' => 'a-completely-different-application-key',
        ]);
        $service = new FieldEncryptionService;

        $payload = $service->encrypt('13-digit-PII-ข้อมูล');
        $this->assertSame('13-digit-PII-ข้อมูล', $service->decrypt($payload));
        $this->assertSame('13-digit-PII-ข้อมูล', $service->decryptWithKey($payload, $dedicated));
        $derived = hash('sha256', $dedicated, true);
        $this->assertSame(substr(hash('sha256', $derived), 0, 8), $service->keyFingerprint());

        $this->expectException(RuntimeException::class);
        $service->decryptWithKey($payload, 'a-completely-different-application-key');
    }

    public function test_blank_dedicated_key_falls_back_to_base64_app_key_without_reading_environment(): void
    {
        $appKey = 'base64:'.base64_encode(str_repeat('a', 32));
        $oldEnv = $_ENV['FIELD_ENCRYPTION_KEY'] ?? null;
        $oldServer = $_SERVER['FIELD_ENCRYPTION_KEY'] ?? null;
        $oldGetenv = getenv('FIELD_ENCRYPTION_KEY');
        $_ENV['FIELD_ENCRYPTION_KEY'] = 'environment-key-that-must-not-be-read';
        $_SERVER['FIELD_ENCRYPTION_KEY'] = 'environment-key-that-must-not-be-read';
        putenv('FIELD_ENCRYPTION_KEY=environment-key-that-must-not-be-read');
        config(['app.field_encryption_key' => '', 'app.key' => $appKey]);

        try {
            $service = new FieldEncryptionService;
            $payload = $service->encrypt('app-key-fallback');
            $this->assertSame('app-key-fallback', $service->decrypt($payload));
            $this->assertSame('app-key-fallback', $service->decryptWithKey($payload, $appKey));
        } finally {
            $this->restoreEnvironment('FIELD_ENCRYPTION_KEY', $oldEnv, $oldServer, $oldGetenv);
        }
    }

    public function test_gcm_authentication_tag_detects_ciphertext_tampering(): void
    {
        config(['app.field_encryption_key' => 'tamper-test-field-key']);
        $service = new FieldEncryptionService;
        $payload = $service->encrypt('sensitive-value');
        $decoded = base64_decode($payload, true);
        $this->assertIsString($decoded);
        $decoded[strlen($decoded) - 1] = $decoded[strlen($decoded) - 1] ^ "\x01";

        $this->expectException(RuntimeException::class);
        $service->decrypt(base64_encode($decoded));
    }

    private function restoreEnvironment(string $name, mixed $env, mixed $server, string|false $process): void
    {
        if ($env === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $env;
        }
        if ($server === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $server;
        }
        if ($process === false) {
            putenv($name);
        } else {
            putenv($name.'='.$process);
        }
    }
}
