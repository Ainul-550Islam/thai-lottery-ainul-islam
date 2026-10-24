<?php

declare(strict_types=1);

namespace App\Services\Security;

use RuntimeException;

class FieldEncryptionService
{
    private const CIPHER = 'aes-256-gcm';

    private const TAG_LENGTH = 16;

    private const IV_LENGTH = 12;

    private const VERSION_PREFIX = 'v1:';

    private string $encryptionKey;

    /** @var list<string> */
    private array $decryptionKeys = [];

    public function __construct(?string $key = null)
    {
        $configuredKey = $key;

        if ($configuredKey === null || trim($configuredKey) === '') {
            $fieldKey = config('app.field_encryption_key');
            $configuredKey = is_string($fieldKey) && trim($fieldKey) !== ''
                ? $fieldKey
                : (string) config('app.key', '');
        }

        $decodedPrimary = $this->decodeKeyMaterial($configuredKey);
        if ($decodedPrimary === '') {
            throw new RuntimeException('FieldEncryptionService requires non-empty key material; refusing insecure fallback.');
        }

        $this->encryptionKey = hash_hkdf('sha256', $decodedPrimary, 32, 'field-encryption-v1');
        $this->decryptionKeys[] = $this->encryptionKey;
        // Compatibility derived key for pre-HKDF SHA-256 ciphertexts under the same secret:
        $this->decryptionKeys[] = hash('sha256', $decodedPrimary, true);

        $previousKeys = config('app.previous_field_encryption_keys', []);
        if (is_array($previousKeys)) {
            foreach ($previousKeys as $prev) {
                if (is_string($prev) && trim($prev) !== '') {
                    $decodedPrev = $this->decodeKeyMaterial($prev);
                    if ($decodedPrev !== '') {
                        $this->decryptionKeys[] = hash_hkdf('sha256', $decodedPrev, 32, 'field-encryption-v1');
                        $this->decryptionKeys[] = hash('sha256', $decodedPrev, true);
                    }
                }
            }
        }
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

        return self::VERSION_PREFIX.base64_encode($iv.$tag.$cipherText);
    }

    /**
     * Decrypt sensitive PII using AES-256-GCM and verify authentication tag.
     */
    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        $encoded = str_starts_with($payload, self::VERSION_PREFIX)
            ? substr($payload, strlen(self::VERSION_PREFIX))
            : $payload;

        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new RuntimeException('Invalid encrypted payload structure.');
        }

        $iv = substr($data, 0, self::IV_LENGTH);
        $tag = substr($data, self::IV_LENGTH, self::TAG_LENGTH);
        $cipherText = substr($data, self::IV_LENGTH + self::TAG_LENGTH);

        foreach ($this->decryptionKeys as $candidateKey) {
            $plainText = openssl_decrypt(
                $cipherText,
                self::CIPHER,
                $candidateKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($plainText !== false) {
                return $plainText;
            }
        }

        throw new RuntimeException('Data integrity check failed during field decryption (Authentication Tag mismatch).');
    }

    /**
     * Deterministic domain-separated HMAC blind index for indexed equality lookups without storing plaintext PII.
     */
    public function blindIndex(string $value, string $context = 'default'): string
    {
        $indexKey = hash_hkdf('sha256', $this->encryptionKey, 32, 'blind-index:'.$context);

        return hash_hmac('sha256', mb_strtolower(trim($value)), $indexKey);
    }

    private function decodeKeyMaterial(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, 'base64:')) {
            $decoded = base64_decode(substr($trimmed, 7), true);
            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('Malformed base64 field encryption key.');
            }

            return $decoded;
        }

        return $trimmed;
    }
}
