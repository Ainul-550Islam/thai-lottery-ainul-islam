<?php

declare(strict_types=1);

namespace App\Services\Security;

use RuntimeException;

class FieldEncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12;

    private string $encryptionKey;

    public function __construct(?string $key = null)
    {
        $rawKey = $key ?? config('app.field_encryption_key', env('FIELD_ENCRYPTION_KEY', 'base64:32ByteSecretKeyForEnterpriseFintech2026='));

        if (str_starts_with($rawKey, 'base64:')) {
            $rawKey = base64_decode(substr($rawKey, 7));
        }

        $this->encryptionKey = hash('sha256', $rawKey, true); // Ensure exact 32 bytes
    }

    /**
     * Encrypt sensitive PII using AES-256-GCM with authenticated tag.
     */
    public function encrypt(string $plainText): string
    {
        if ($plainText === '') {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($cipherText === false) {
            throw new RuntimeException('Failed to encrypt sensitive field data.');
        }

        // Pack: IV (12B) + TAG (16B) + CIPHERTEXT
        return base64_encode($iv . $tag . $cipherText);
    }

    /**
     * Decrypt sensitive PII using AES-256-GCM and verify authentication tag.
     */
    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $data = base64_decode($payload, true);
        if ($data === false || strlen($data) < (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new RuntimeException('Invalid encrypted payload structure.');
        }

        $iv = substr($data, 0, self::IV_LENGTH);
        $tag = substr($data, self::IV_LENGTH, self::TAG_LENGTH);
        $cipherText = substr($data, self::IV_LENGTH + self::TAG_LENGTH);

        $plainText = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Failed to decrypt or tag authentication failed (data tampered).');
        }

        return $plainText;
    }
}
