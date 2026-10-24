<?php

declare(strict_types=1);

namespace App\Support\Backup;

use RuntimeException;

/**
 * Authenticated encryption for database backups.
 *
 * WHAT WAS WRONG BEFORE
 * ---------------------
 * The lane this class replaces used `openssl_encrypt('aes-256-cbc', ...)` with
 * a random IV and no authentication tag. Two independent problems:
 *
 *   1. CBC without a MAC is malleable. An attacker with write access to the
 *      backup bucket — precisely the attacker a WORM bucket is meant to
 *      survive — can flip bits in the ciphertext of a `ledger_transactions`
 *      INSERT and change an amount without any key. Nothing downstream would
 *      notice: the archive decrypts cleanly, the restore imports, and the
 *      reconciliation report then disagrees with the ledger it was restored
 *      from.
 *
 *   2. The key defaulted to a literal string committed to git, so
 *      "encryption" was a base64 step.
 *
 * WHAT THIS DOES INSTEAD
 * ----------------------
 *   * Strings use AES-256-GCM with a 96-bit nonce and a 128-bit tag. Decrypt
 *     fails closed on a tampered payload.
 *   * Files use AES-256-CTR with per-chunk derived nonces and an
 *     encrypt-then-MAC HMAC-SHA256 over the header and the whole ciphertext,
 *     so a gigabyte-scale dump is authenticated without being held in memory.
 *   * There is no default key. A missing key is a hard error, not a fallback.
 *
 * The file format is documented in the class constants below and in
 * `docs/ops/OPS-RUNBOOK.md` (backup section), because an operator recovering
 * an archive at 3am needs the format written down somewhere that is not PHP.
 */
final class BackupCipher
{
    /** String envelope magic. */
    public const STRING_MAGIC = 'TLBK';

    /** File envelope magic. */
    public const FILE_MAGIC = 'TLBKFILE';

    /**
     * Bump when the format changes. Decrypt refuses formats it does not know
     * rather than guessing, because guessing is how a corrupted archive gets
     * imported as a valid one.
     */
    public const FORMAT_VERSION = 2;

    /** AES-GCM nonce length, bytes. */
    private const GCM_NONCE_BYTES = 12;

    /** AES-GCM tag length, bytes. */
    private const GCM_TAG_BYTES = 16;

    /** Per-file salt length, bytes. */
    private const SALT_BYTES = 16;

    /** Chunk size for file operations, bytes. */
    private const CHUNK_BYTES = 1048576;

    /** Minimum accepted key length. Production preflight enforces 32. */
    public const MINIMUM_KEY_LENGTH = 16;

    private string $masterKey;

    /**
     * @param  string|null  $key  Explicit key (tests, drills). When null the
     *                            configured key or key file is used.
     */
    public function __construct(?string $key = null, private readonly bool $allowLegacyCbcDecrypt = false)
    {
        $this->masterKey = self::assertUsableKey($key ?? self::configuredKey() ?? '');
    }

    /**
     * Resolve the key from configuration, preferring the file form.
     *
     * A `*_FILE` setting wins over the inline variable for the same reason
     * Docker secrets win: an operator who dumps the process environment should
     * not get the backup key with it.
     */
    public static function configuredKey(): ?string
    {
        $file = self::configString('backup.key_file');

        if ($file !== null && $file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException(
                    'BACKUP_ENCRYPTION_KEY_FILE is set but the file is not readable. Refusing to continue.'
                );
            }

            $contents = trim((string) file_get_contents($file));

            if ($contents === '') {
                throw new RuntimeException('BACKUP_ENCRYPTION_KEY_FILE is empty. Refusing to continue.');
            }

            return $contents;
        }

        $inline = self::configString('backup.key');

        return ($inline === null || $inline === '') ? null : $inline;
    }

    /**
     * True when a key is configured at all. Used by preflight, which must be
     * able to report "missing" without throwing.
     */
    public static function hasConfiguredKey(): bool
    {
        try {
            $key = self::configuredKey();
        } catch (RuntimeException) {
            return false;
        }

        return $key !== null && strlen($key) >= self::MINIMUM_KEY_LENGTH;
    }

    /**
     * Values that must never be accepted as a backup key.
     *
     * The first entry is the literal that shipped in this repository. It is
     * listed so an operator migrating an old deployment gets a clear message
     * instead of a silent "everything still works", which would be true and
     * useless.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'HardenedBackupKey2026MasterSecret!',
        'changeme',
        'change-me',
        'secret',
        'password',
        'backup',
        'test',
        'example',
    ];

    private static function assertUsableKey(string $key): string
    {
        $trimmed = trim($key);

        if ($trimmed === '') {
            throw new RuntimeException(
                'No backup encryption key is configured. Set BACKUP_ENCRYPTION_KEY or '
                .'BACKUP_ENCRYPTION_KEY_FILE. Backups are never written unencrypted.'
            );
        }

        if (strlen($trimmed) < self::MINIMUM_KEY_LENGTH) {
            throw new RuntimeException(sprintf(
                'The backup encryption key is too short (%d characters, minimum %d).',
                strlen($trimmed),
                self::MINIMUM_KEY_LENGTH,
            ));
        }

        $lowercaseKey = strtolower($trimmed);
        $forbidden = array_map('strtolower', self::FORBIDDEN_KEYS);

        if (in_array($lowercaseKey, $forbidden, true)) {
            throw new RuntimeException(
                'The backup encryption key is a known placeholder value. Generate a real secret '
                .'(for example: openssl rand -base64 48) before taking a backup.'
            );
        }

        return $trimmed;
    }

    private static function configString(string $key): ?string
    {
        if (! function_exists('config')) {
            return null;
        }

        $value = config($key);

        return is_string($value) ? $value : null;
    }

    /**
     * Derive a purpose-bound subkey so the encryption key and the MAC key are
     * never the same bytes (a classic encrypt-then-MAC mistake).
     */
    private function subkey(string $purpose, string $salt): string
    {
        return hash_hkdf('sha256', $this->masterKey, 32, 'tl-backup:'.$purpose, $salt);
    }

    /**
     * Encrypt a string payload with AES-256-GCM.
     *
     * Envelope: MAGIC(4) | VERSION(1) | NONCE(12) | TAG(16) | CIPHERTEXT(n)
     */
    public function encryptString(string $plaintext): string
    {
        $nonce = random_bytes(self::GCM_NONCE_BYTES);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->subkey('gcm', ''),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::STRING_MAGIC,
            self::GCM_TAG_BYTES,
        );

        if ($ciphertext === false || strlen($tag) !== self::GCM_TAG_BYTES) {
            throw new RuntimeException('Backup encryption failed: the OpenSSL cipher refused the payload.');
        }

        return self::STRING_MAGIC.chr(self::FORMAT_VERSION).$nonce.$tag.$ciphertext;
    }

    public function decryptString(string $envelope): string
    {
        if ($this->allowLegacyCbcDecrypt && ! str_starts_with($envelope, self::STRING_MAGIC)) {
            return $this->decryptLegacyCbc($envelope);
        }

        $headerLength = strlen(self::STRING_MAGIC) + 1 + self::GCM_NONCE_BYTES + self::GCM_TAG_BYTES;

        if (strlen($envelope) <= $headerLength) {
            throw new RuntimeException('Backup payload is truncated: shorter than its own header.');
        }

        if (! str_starts_with($envelope, self::STRING_MAGIC)) {
            throw new RuntimeException('Backup payload is not in the expected TL backup format.');
        }

        $offset = strlen(self::STRING_MAGIC);
        $version = ord($envelope[$offset]);

        if ($version !== self::FORMAT_VERSION) {
            throw new RuntimeException(sprintf(
                'Backup payload format version %d is not supported (this build reads version %d).',
                $version,
                self::FORMAT_VERSION,
            ));
        }

        $offset++;

        $nonce = substr($envelope, $offset, self::GCM_NONCE_BYTES);
        $offset += self::GCM_NONCE_BYTES;

        $tag = substr($envelope, $offset, self::GCM_TAG_BYTES);
        $offset += self::GCM_TAG_BYTES;

        $ciphertext = substr($envelope, $offset);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->subkey('gcm', ''),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::STRING_MAGIC,
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'Backup payload failed authentication: it was tampered with, truncated, or encrypted '
                .'with a different key.'
            );
        }

        return $plaintext;
    }

    /**
     * Encrypt a file in place-safe fashion: plaintext file in, envelope out.
     *
     * File envelope: MAGIC(8) | VERSION(1) | SALT(16) | MAC(32) | CHUNKS...
     *
     * The MAC is written into the header region *after* the body is complete,
     * so the file is only valid once fully written. A partially written
     * archive therefore fails verification instead of decrypting to a truncated
     * SQL file, which would import "successfully" and leave a database that
     * looks complete but is missing its last tables.
     *
     * @return array{sha256: string, bytes: int, ciphertext_bytes: int}
     */
    public function encryptFile(string $sourcePath, string $destinationPath): array
    {
        $source = $this->openForRead($sourcePath);
        $destination = $this->openForWrite($destinationPath);

        try {
            $salt = random_bytes(self::SALT_BYTES);
            $encKey = $this->subkey('file-enc', $salt);
            $macKey = $this->subkey('file-mac', $salt);
            $ivKey = $this->subkey('file-iv', $salt);

            $header = self::FILE_MAGIC.chr(self::FORMAT_VERSION).$salt.str_repeat("\0", 32);

            if (fwrite($destination, $header) !== strlen($header)) {
                throw new RuntimeException('Unable to write the backup envelope header.');
            }

            $mac = hash_init('sha256', HASH_HMAC, $macKey);
            hash_update($mac, self::FILE_MAGIC.chr(self::FORMAT_VERSION).$salt);

            $counter = 0;
            $plaintextBytes = 0;
            $ciphertextBytes = 0;

            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException('Failed reading the database dump.');
                }

                if ($chunk === '') {
                    break;
                }

                $chunkCiphertext = $this->cryptChunk($chunk, $encKey, $ivKey, $counter, encrypting: true);
                $counter++;

                $written = fwrite($destination, $chunkCiphertext);

                if ($written !== strlen($chunkCiphertext)) {
                    throw new RuntimeException('Short write while encrypting the backup archive.');
                }

                hash_update($mac, $chunkCiphertext);
                $plaintextBytes += strlen($chunk);
                $ciphertextBytes += strlen($chunkCiphertext);
            }

            $macBytes = hash_final($mac, true);

            // Overwrite the reserved MAC slot; the body stays where it is.
            if (fseek($destination, strlen(self::FILE_MAGIC) + 1 + self::SALT_BYTES) !== 0) {
                throw new RuntimeException('Unable to seek to the backup envelope MAC slot.');
            }

            if (fwrite($destination, $macBytes) !== 32) {
                throw new RuntimeException('Unable to write the backup envelope MAC.');
            }

            fflush($destination);
            fclose($destination);

            $sha256 = hash_file('sha256', $destinationPath);

            return [
                'sha256' => $sha256 === false ? '' : $sha256,
                'bytes' => $plaintextBytes,
                'ciphertext_bytes' => $ciphertextBytes + strlen(self::FILE_MAGIC) + 1 + self::SALT_BYTES + 32,
            ];
        } catch (\Throwable $error) {
            // Never leave a half-written archive behind: a truncated archive in
            // a backup directory is worse than a missing one, because the
            // retention pruner counts it as a good copy.
            if (is_resource($destination)) {
                fclose($destination);
            }

            if (is_file($destinationPath)) {
                @unlink($destinationPath);
            }

            throw $error;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * Verify and decrypt a file envelope.
     *
     * The MAC is checked over the whole ciphertext BEFORE any plaintext is
     * exposed to the caller, so `verifyFile()` and `decryptFile()` share one
     * implementation and a restore can never import unauthenticated bytes.
     *
     * @param  string|null  $expectedSha256  when provided, the envelope's own
     *                                       SHA-256 must match (detects a
     *                                       swapped-but-valid archive)
     */
    public function decryptFile(string $sourcePath, string $destinationPath, ?string $expectedSha256 = null): array
    {
        if ($expectedSha256 !== null) {
            $actual = hash_file('sha256', $sourcePath);

            if ($actual === false || ! hash_equals(strtolower($expectedSha256), strtolower($actual))) {
                throw new RuntimeException(
                    'Backup archive checksum mismatch: the file on disk is not the file that was recorded '
                    .'when the archive was produced.'
                );
            }
        }

        $source = $this->openForRead($sourcePath);
        $destination = $this->openForWrite($destinationPath);

        try {
            $prefixLength = strlen(self::FILE_MAGIC) + 1 + self::SALT_BYTES;
            $prefix = fread($source, $prefixLength);

            if ($prefix === false || strlen($prefix) !== $prefixLength) {
                throw new RuntimeException('Backup archive is truncated: it has no complete header.');
            }

            if (! str_starts_with($prefix, self::FILE_MAGIC)) {
                throw new RuntimeException(
                    'Backup archive is not in the TL backup file format. Legacy CBC archives must be '
                    .'converted before they can be restored by this build.'
                );
            }

            $version = ord($prefix[strlen(self::FILE_MAGIC)]);

            if ($version !== self::FORMAT_VERSION) {
                throw new RuntimeException(sprintf(
                    'Backup archive format version %d is not supported by this build (reads version %d).',
                    $version,
                    self::FORMAT_VERSION,
                ));
            }

            $salt = substr($prefix, strlen(self::FILE_MAGIC) + 1, self::SALT_BYTES);
            $encKey = $this->subkey('file-enc', $salt);
            $macKey = $this->subkey('file-mac', $salt);
            $ivKey = $this->subkey('file-iv', $salt);

            $macBytes = fread($source, 32);

            if ($macBytes === false || strlen($macBytes) !== 32) {
                throw new RuntimeException('Backup archive header is incomplete: no authentication tag.');
            }

            $mac = hash_init('sha256', HASH_HMAC, $macKey);
            hash_update($mac, self::FILE_MAGIC.chr(self::FORMAT_VERSION).$salt);

            $counter = 0;
            $plaintextBytes = 0;

            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new RuntimeException('Failed reading the backup archive.');
                }

                if ($chunk === '') {
                    break;
                }

                hash_update($mac, $chunk);

                $plaintext = $this->cryptChunk($chunk, $encKey, $ivKey, $counter, encrypting: false);
                $counter++;

                if (fwrite($destination, $plaintext) !== strlen($plaintext)) {
                    throw new RuntimeException('Short write while decrypting the backup archive.');
                }

                $plaintextBytes += strlen($plaintext);
            }

            if (! hash_equals($macBytes, hash_final($mac, true))) {
                throw new RuntimeException(
                    'Backup archive failed authentication: wrong key, or the archive was modified after '
                    .'it was written. Refusing to emit plaintext.'
                );
            }

            fflush($destination);
            fclose($destination);

            return ['bytes' => $plaintextBytes, 'chunks' => $counter];
        } catch (\Throwable $error) {
            if (is_resource($destination)) {
                fclose($destination);
            }

            if (is_file($destinationPath)) {
                @unlink($destinationPath);
            }

            throw $error;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /**
     * Read an envelope's header without decrypting anything.
     *
     * @return array{magic: string, version: int, plaintext_ready: bool}
     */
    public function inspectFile(string $path): array
    {
        $handle = $this->openForRead($path);

        try {
            $prefix = fread($handle, strlen(self::FILE_MAGIC) + 1);

            if ($prefix === false || strlen($prefix) < strlen(self::FILE_MAGIC)) {
                throw new RuntimeException('Backup archive is too small to be a TL backup envelope.');
            }

            return [
                'magic' => substr($prefix, 0, strlen(self::FILE_MAGIC)),
                'version' => strlen($prefix) > strlen(self::FILE_MAGIC) ? ord($prefix[strlen(self::FILE_MAGIC)]) : 0,
                'plaintext_ready' => str_starts_with($prefix, self::FILE_MAGIC),
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * AES-256-CTR over one chunk with a chunk-unique derived IV.
     *
     * CTR is a stream cipher, so encrypting the same key with a repeated IV is
     * a catastrophic keystream reuse. Deriving the IV from the chunk counter
     * with the MAC key (which is itself salt-bound) guarantees uniqueness
     * across chunks, across archives and across keys.
     */
    private function cryptChunk(string $chunk, string $encKey, string $ivKey, int $counter, bool $encrypting): string
    {
        $iv = substr(hash_hmac('sha256', 'chunk:'.$counter, $ivKey, true), 0, 16);

        $output = $encrypting
            ? openssl_encrypt($chunk, 'aes-256-ctr', $encKey, OPENSSL_RAW_DATA, $iv)
            : openssl_decrypt($chunk, 'aes-256-ctr', $encKey, OPENSSL_RAW_DATA, $iv);

        if ($output === false) {
            throw new RuntimeException('Chunk cipher operation failed.');
        }

        return $output;
    }

    /**
     * Decrypt the legacy AES-256-CBC payloads produced before this build.
     *
     * Off by default. It exists so a restore drill can migrate an archive
     * taken by the old code without re-running the old code, NOT so the old
     * format can stay in use — it is unauthenticated and therefore cannot be
     * verified, only decrypted.
     */
    private function decryptLegacyCbc(string $base64Payload): string
    {
        $raw = base64_decode($base64Payload, true);

        if ($raw === false || strlen($raw) <= 16) {
            throw new RuntimeException('Legacy backup payload is not valid base64 or is truncated.');
        }

        $iv = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-cbc',
            hash('sha256', $this->masterKey, true),
            OPENSSL_RAW_DATA,
            $iv,
        );

        if ($plaintext === false) {
            throw new RuntimeException('Legacy backup decryption failed (wrong key or corrupted payload).');
        }

        return $plaintext;
    }

    /**
     * @return resource
     */
    private function openForRead(string $path)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Backup source file is not readable: '.basename($path));
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open backup source file: '.basename($path));
        }

        return $handle;
    }

    /**
     * @return resource
     */
    private function openForWrite(string $path)
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the backup output directory.');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the backup output file for writing.');
        }

        // Ciphertext at rest is owner/group readable only. A backup directory is
        // routinely mounted into sidecars and debug containers.
        @chmod($path, 0640);

        return $handle;
    }
}
