<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Support\Backup\BackupCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The backup cipher is the only thing standing between a stolen archive and the
 * financial ledger, so its failure modes are tested rather than assumed.
 *
 * Each test below corresponds to a way the encryption could be decorative:
 * it round-trips but does not detect tampering, it accepts a committed default
 * key, or it writes a plaintext file when decryption fails half-way.
 */
final class BackupCipherTest extends TestCase
{
    private const KEY = 'correct horse battery staple 32 chars';

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/tl-cipher-'.bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->workspace);

        parent::tearDown();
    }

    public function test_string_payloads_round_trip(): void
    {
        $cipher = new BackupCipher(self::KEY);
        $plaintext = "-- ledger snapshot\nINSERT INTO ledger_transactions VALUES ('tx-1', 'POSTED');";

        $envelope = $cipher->encryptString($plaintext);

        $this->assertStringStartsWith(BackupCipher::STRING_MAGIC, $envelope);
        $this->assertStringNotContainsString('ledger_transactions', $envelope, 'the payload must not appear in the clear');
        $this->assertSame($plaintext, $cipher->decryptString($envelope));
    }

    public function test_two_encryptions_of_the_same_plaintext_differ(): void
    {
        $cipher = new BackupCipher(self::KEY);

        // A deterministic ciphertext would let an observer confirm a guess.
        $this->assertNotSame(
            $cipher->encryptString('same input'),
            $cipher->encryptString('same input'),
        );
    }

    public function test_a_tampered_string_payload_is_rejected(): void
    {
        $cipher = new BackupCipher(self::KEY);
        $envelope = $cipher->encryptString('amount=1000');

        // Flip the last byte: this is what "change one digit in the archive"
        // looks like to a GCM tag.
        $envelope[strlen($envelope) - 1] = chr(ord($envelope[strlen($envelope) - 1]) ^ 0x01);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed authentication');

        $cipher->decryptString($envelope);
    }

    public function test_a_payload_encrypted_with_another_key_is_rejected(): void
    {
        $envelope = (new BackupCipher(self::KEY))->encryptString('ledger');

        $this->expectException(RuntimeException::class);

        (new BackupCipher('a completely different 32 char key!'))->decryptString($envelope);
    }

    public function test_a_missing_key_is_a_hard_failure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No backup encryption key');

        new BackupCipher('');
    }

    public function test_a_short_key_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too short');

        new BackupCipher('short');
    }

    public function test_the_committed_placeholder_key_that_shipped_in_this_repository_is_refused(): void
    {
        // This literal used to be the fallback default in ImmutableBackupService.
        // Every archive ever produced with it is readable by anyone who has the
        // repository, so it must never be accepted again.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('placeholder');

        new BackupCipher('HardenedBackupKey2026MasterSecret!');
    }

    public function test_file_archives_round_trip_beyond_one_chunk(): void
    {
        $cipher = new BackupCipher(self::KEY);

        // 2.5 MiB spans three 1 MiB chunks, which is where a broken per-chunk
        // nonce or a missed final-chunk flush would show up.
        $payload = random_bytes(2621440);
        $source = $this->workspace.'/dump.sql';
        $archive = $this->workspace.'/dump.sql.enc';
        $restored = $this->workspace.'/restored.sql';

        file_put_contents($source, $payload);

        $result = $cipher->encryptFile($source, $archive);

        $this->assertFileExists($archive);
        $this->assertGreaterThan(strlen($payload), $result['ciphertext_bytes'], 'the envelope carries a header and a MAC');
        $this->assertSame(hash_file('sha256', $archive), $result['sha256']);

        $cipher->decryptFile($archive, $restored);

        $this->assertSame(hash('sha256', $payload), hash_file('sha256', $restored));
    }

    public function test_a_tampered_file_archive_emits_no_plaintext(): void
    {
        $cipher = new BackupCipher(self::KEY);

        $source = $this->workspace.'/dump.sql';
        $archive = $this->workspace.'/dump.sql.enc';
        $restored = $this->workspace.'/restored.sql';

        file_put_contents($source, str_repeat('INSERT INTO ledger_transactions VALUES (1);'.PHP_EOL, 500));
        $cipher->encryptFile($source, $archive);

        // Corrupt a byte in the ciphertext body (past the 8+1+16+32 header).
        $handle = fopen($archive, 'r+b');
        fseek($handle, 200);
        fwrite($handle, "\xFF");
        fclose($handle);

        try {
            $cipher->decryptFile($archive, $restored);
            $this->fail('A tampered archive decrypted successfully.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('failed authentication', $error->getMessage());
        }

        // The critical property: no partial plaintext is left behind for a
        // restore script to import.
        $this->assertFileDoesNotExist($restored);
    }

    public function test_a_truncated_file_archive_is_rejected(): void
    {
        $cipher = new BackupCipher(self::KEY);

        $source = $this->workspace.'/dump.sql';
        $archive = $this->workspace.'/dump.sql.enc';
        $restored = $this->workspace.'/restored.sql';

        file_put_contents($source, str_repeat('x', 65536));
        $cipher->encryptFile($source, $archive);

        // A copy interrupted by a full disk looks exactly like this.
        $size = (int) filesize($archive);
        $handle = fopen($archive, 'r+b');
        ftruncate($handle, (int) ($size / 2));
        fclose($handle);

        $this->expectException(RuntimeException::class);

        $cipher->decryptFile($archive, $restored);
    }

    public function test_a_swapped_but_valid_archive_is_caught_by_the_recorded_checksum(): void
    {
        $cipher = new BackupCipher(self::KEY);

        $first = $this->workspace.'/first.sql';
        $second = $this->workspace.'/second.sql';
        $firstArchive = $this->workspace.'/first.enc';
        $secondArchive = $this->workspace.'/second.enc';
        $restored = $this->workspace.'/restored.sql';

        file_put_contents($first, 'first dump');
        file_put_contents($second, 'second dump');

        $firstResult = $cipher->encryptFile($first, $firstArchive);
        $cipher->encryptFile($second, $secondArchive);

        // Both archives are authentic; only the manifest ties them to a point in
        // time. Restoring yesterday's ledger because it happens to be the file
        // on disk is a real recovery failure mode.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum mismatch');

        $cipher->decryptFile($secondArchive, $restored, $firstResult['sha256']);
    }

    public function test_inspect_reports_the_envelope_without_decrypting(): void
    {
        $cipher = new BackupCipher(self::KEY);
        $source = $this->workspace.'/dump.sql';
        $archive = $this->workspace.'/dump.sql.enc';

        file_put_contents($source, 'dump');
        $cipher->encryptFile($source, $archive);

        $header = $cipher->inspectFile($archive);

        $this->assertSame(BackupCipher::FILE_MAGIC, $header['magic']);
        $this->assertSame(BackupCipher::FORMAT_VERSION, $header['version']);
        $this->assertTrue($header['plaintext_ready']);
    }
}
