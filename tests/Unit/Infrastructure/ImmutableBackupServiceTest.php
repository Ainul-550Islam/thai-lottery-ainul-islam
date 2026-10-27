<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Services\Infrastructure\ImmutableBackupService;
use RuntimeException;
use Tests\TestCase;

class ImmutableBackupServiceTest extends TestCase
{
    private ImmutableBackupService $backupService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupService = new ImmutableBackupService('TestSecretBackupMasterKey2026!');
    }

    public function test_encrypts_and_decrypts_database_dump(): void
    {
        $rawDump = "INSERT INTO ledger_transactions (id, status) VALUES ('tx-123', 'POSTED');";

        $res = $this->backupService->encryptDumpData($rawDump);

        $this->assertNotEmpty($res['encrypted_payload']);
        $this->assertNotEmpty($res['sha256_fingerprint']);
        $this->assertSame(64, strlen($res['sha256_fingerprint']));

        // Verify accurate decryption
        $decrypted = $this->backupService->decryptDumpData($res['encrypted_payload']);
        $this->assertSame($rawDump, $decrypted);
    }

    public function test_configured_backup_key_is_used_without_an_environment_fallback(): void
    {
        config(['app.backup_encryption_key' => 'config-only-backup-key-for-tests']);
        $service = new ImmutableBackupService;
        $rawDump = 'INSERT INTO wallet_audit VALUES (1);';

        $encrypted = $service->encryptDumpData($rawDump);

        $this->assertSame($rawDump, $service->decryptDumpData($encrypted['encrypted_payload']));
    }

    public function test_missing_backup_key_fails_closed_instead_of_deriving_a_key_from_empty_string(): void
    {
        config(['app.backup_encryption_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Backup encryption is not configured');
        (new ImmutableBackupService)->encryptDumpData('sensitive database dump');
    }
}
