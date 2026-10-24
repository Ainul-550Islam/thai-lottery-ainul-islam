<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Support\Backup\BackupCipher;
use RuntimeException;

/**
 * Encrypts and authenticates database backups.
 *
 * The public surface is unchanged from the version this replaces, because the
 * existing unit test and any operator muscle memory depend on it:
 *
 *   encryptDumpData(string $sql): array{encrypted_payload, sha256_fingerprint, iv_hex}
 *   decryptDumpData(string $base64Payload): string
 *
 * What changed is underneath: AES-256-GCM instead of unauthenticated
 * AES-256-CBC, and no default key anywhere. A caller that does not supply a
 * key and has none configured gets an exception rather than a backup that
 * only looks encrypted.
 *
 * File-scale archives (a real database dump) go through `encryptFile()` /
 * `decryptFile()`, which stream and authenticate. See App\Support\Backup\BackupCipher
 * for the envelope formats.
 */
class ImmutableBackupService
{
    private readonly BackupCipher $cipher;

    public function __construct(?string $backupKey = null, bool $allowLegacyCbcDecrypt = false)
    {
        $this->cipher = new BackupCipher($backupKey, $allowLegacyCbcDecrypt);
    }

    /**
     * Encrypt an in-memory dump payload.
     *
     * @return array{encrypted_payload: string, sha256_fingerprint: string, iv_hex: string, format_version: int}
     */
    public function encryptDumpData(string $rawDumpSql): array
    {
        $envelope = $this->cipher->encryptString($rawDumpSql);

        return [
            'encrypted_payload' => base64_encode($envelope),
            'sha256_fingerprint' => hash('sha256', $envelope),
            'iv_hex' => bin2hex(substr($envelope, strlen(BackupCipher::STRING_MAGIC) + 1, 12)),
            'format_version' => BackupCipher::FORMAT_VERSION,
        ];
    }

    /**
     * Decrypt an in-memory dump payload and verify its fingerprint.
     *
     * @param  string|null  $expectedFingerprint  when supplied, must match the
     *                                            SHA-256 recorded at encryption
     *                                            time
     */
    public function decryptDumpData(string $base64Payload, ?string $expectedFingerprint = null): string
    {
        $envelope = base64_decode($base64Payload, true);

        if ($envelope === false) {
            throw new RuntimeException('Backup payload is not valid base64.');
        }

        if ($expectedFingerprint !== null && ! hash_equals(strtolower($expectedFingerprint), hash('sha256', $envelope))) {
            throw new RuntimeException('Backup payload fingerprint does not match the recorded value.');
        }

        return $this->cipher->decryptString($envelope);
    }

    /**
     * Stream a plaintext dump file into an encrypted archive.
     *
     * @return array{sha256: string, bytes: int, ciphertext_bytes: int}
     */
    public function encryptFile(string $sourcePath, string $destinationPath): array
    {
        return $this->cipher->encryptFile($sourcePath, $destinationPath);
    }

    /**
     * Stream an encrypted archive back to plaintext after authenticating it.
     *
     * @return array{bytes: int, chunks: int}
     */
    public function decryptFile(string $sourcePath, string $destinationPath, ?string $expectedSha256 = null): array
    {
        return $this->cipher->decryptFile($sourcePath, $destinationPath, $expectedSha256);
    }

    /**
     * Header-only inspection; used by `backup:verify` and the restore scripts to
     * reject the wrong file type before touching a database.
     *
     * @return array{magic: string, version: int, plaintext_ready: bool}
     */
    public function inspectFile(string $path): array
    {
        return $this->cipher->inspectFile($path);
    }

    public function isConfigured(): bool
    {
        return BackupCipher::hasConfiguredKey();
    }
}
