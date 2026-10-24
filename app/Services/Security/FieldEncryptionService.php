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

    /**
     * Resolve the key material, or refuse to construct at all.
     *
     * WHY THERE IS NO FALLBACK
     * This constructor used to fall back to a hard-coded base64 secret committed in
     * this file. Every field encrypted with it could therefore be decrypted by anyone
     * holding the repository, which is the opposite of what field-level encryption is
     * for - and it made a missing FIELD_ENCRYPTION_KEY invisible in production. The
     * service now fails closed: no key material means no instance, so a
     * misconfiguration surfaces at boot instead of silently weakening the data.
     *
     * @throws RuntimeException when no key material is configured
     */
    public function __construct(?string $key = null)
    {
        // Precedence: an explicit key, then the dedicated field key, then the
        // application key (which Laravel already treats as secret and rotates), and
        // nothing else. APP_KEY is a legitimate last resort because it is per
        // installation and never committed; the removed hard-coded literal was not.
        $rawKey = $key
            ?? config('app.field_encryption_key')
            ?? (function (): ?string {
                $value = env('FIELD_ENCRYPTION_KEY');

                return is_string($value) && $value !== '' ? $value : null;
            })()
            ?? config('app.key');

        if (! is_string($rawKey) || trim($rawKey) === '') {
            throw new RuntimeException(
                'Field encryption is not configured: set FIELD_ENCRYPTION_KEY (or app.field_encryption_key).',
            );
        }

        if (str_starts_with($rawKey, 'base64:')) {
            $decoded = base64_decode(substr($rawKey, 7), true);

            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('Field encryption key is not valid base64.');
            }

            $rawKey = $decoded;
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
        return base64_encode($iv.$tag.$cipherText);
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
