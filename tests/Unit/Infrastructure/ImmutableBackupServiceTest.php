<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Services\Infrastructure\ImmutableBackupService;
use PHPUnit\Framework\TestCase;

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
}
