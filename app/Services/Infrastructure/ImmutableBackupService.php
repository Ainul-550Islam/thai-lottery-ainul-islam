<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use RuntimeException;

class ImmutableBackupService
{
    private const CIPHER = 'aes-256-cbc';

    public function __construct(
        private readonly ?string $backupKey = null
    ) {}

    /**
     * Encrypt raw database dump data using AES-256-CBC with SHA-256 fingerprint.
     *
     * @return array{encrypted_payload: string, sha256_fingerprint: string, iv_hex: string}
     */
    public function encryptDumpData(string $rawDumpSql): array
    {
        $key = $this->backupKey ?? (string) config('app.backup_encryption_key', env('BACKUP_ENCRYPTION_KEY', 'HardenedBackupKey2026MasterSecret!'));
        $derivedKey = hash('sha256', $key, true);

        $iv = random_bytes(16);

        $encrypted = openssl_encrypt(
            $rawDumpSql,
            self::CIPHER,
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new RuntimeException('Failed to encrypt database dump.');
        }

        $fullPayload = $iv.$encrypted;
        $sha256 = hash('sha256', $fullPayload);

        return [
            'encrypted_payload' => base64_encode($fullPayload),
            'sha256_fingerprint' => $sha256,
            'iv_hex' => bin2hex($iv),
        ];
    }

    /**
     * Decrypt encrypted backup archive using private secret key.
     */
    public function decryptDumpData(string $base64Payload): string
    {
        $raw = base64_decode($base64Payload, true);
        if ($raw === false || strlen($raw) <= 16) {
            throw new RuntimeException('Invalid encrypted backup structure.');
        }

        $iv = substr($raw, 0, 16);
        $cipherText = substr($raw, 16);

        $key = $this->backupKey ?? (string) config('app.backup_encryption_key', env('BACKUP_ENCRYPTION_KEY', 'HardenedBackupKey2026MasterSecret!'));
        $derivedKey = hash('sha256', $key, true);

        $decrypted = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($decrypted === false) {
            throw new RuntimeException('Backup decryption failed (corrupted or wrong key).');
        }

        return $decrypted;
    }
}
