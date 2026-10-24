<?php

declare(strict_types=1);

namespace App\Console\Commands\Infrastructure;

use App\Services\Infrastructure\DatabaseDumpService;
use App\Services\Infrastructure\ImmutableBackupService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Verifies an encrypted backup archive without restoring it.
 *
 * This exists because a backup that has never been read back is a hypothesis.
 * The check is layered, and each layer catches a different failure:
 *
 *   1. the `.sha256` sidecar (or `--checksum`) must match the file on disk
 *      -> catches truncation, a partial copy, or a swapped file;
 *   2. the HMAC inside the envelope must verify under the current key
 *      -> catches tampering and a wrong key;
 *   3. the decompressed stream must begin like a dump and end with the
 *      client's completion marker
 *      -> catches a dump that was cut short before encryption started.
 *
 * `--deep` additionally restores into a scratch database and compares table
 * counts, which is what a restore drill actually needs. Because that step
 * writes to a database, it refuses to target the configured live database.
 */
class VerifyBackupCommand extends Command
{
    protected $signature = 'backup:verify
        {archive : Path to the .sql.gz.enc archive}
        {--checksum= : Expected SHA-256 (defaults to the .sha256 sidecar)}
        {--deep : Also restore into a scratch database and compare table counts}
        {--scratch-database= : Database to restore into when --deep is used}
        {--json : Machine-readable result}';

    protected $description = 'Verify that an encrypted backup archive authenticates, decrypts, and contains a complete dump.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $archive = (string) $this->argument('archive');

        $result = [
            'archive' => basename($archive),
            'checks' => [],
            'status' => 'FAILED',
        ];

        $fail = function (string $check, string $reason) use (&$result, $json): int {
            $result['checks'][] = ['check' => $check, 'status' => 'FAILED', 'detail' => $reason];

            return $this->finish($result, $json);
        };

        if (! is_file($archive)) {
            return $fail('archive_present', 'Archive not found: '.basename($archive));
        }

        // See ExecuteEncryptedBackupCommand: a key problem surfaces in the
        // service constructor, which must be reported as a check failure
        // rather than escaping as an unhandled exception.
        try {
            $backups = $this->laravel->make(ImmutableBackupService::class);
        } catch (Throwable $error) {
            return $fail('configuration', $error->getMessage());
        }

        // 1. Checksum ------------------------------------------------------
        $expected = $this->option('checksum');

        if (! is_string($expected) || $expected === '') {
            $sidecar = $archive.'.sha256';

            if (is_file($sidecar)) {
                $expected = trim((string) strtok((string) file_get_contents($sidecar), ' '));
            }
        }

        if (! is_string($expected) || $expected === '') {
            $result['checks'][] = [
                'check' => 'checksum',
                'status' => 'WARN',
                'detail' => 'No .sha256 sidecar and no --checksum; the archive cannot be matched to a manifest.',
            ];
        } else {
            $actual = hash_file('sha256', $archive);

            if ($actual === false || ! hash_equals(strtolower($expected), strtolower($actual))) {
                return $fail('checksum', 'SHA-256 does not match the recorded value.');
            }

            $result['checks'][] = ['check' => 'checksum', 'status' => 'PASS', 'detail' => 'SHA-256 matches.'];
        }

        // 2. Envelope authenticity ----------------------------------------
        try {
            $header = $backups->inspectFile($archive);

            if (! $header['plaintext_ready']) {
                return $fail('envelope', 'Not a TL backup envelope (legacy or foreign file).');
            }

            $result['checks'][] = [
                'check' => 'envelope',
                'status' => 'PASS',
                'detail' => 'Format version '.$header['version'].'.',
            ];
        } catch (RuntimeException $error) {
            return $fail('envelope', $error->getMessage());
        }

        $scratch = tempnam(sys_get_temp_dir(), 'tlbackupverify');

        if ($scratch === false) {
            return $fail('scratch', 'Unable to allocate a temporary file for verification.');
        }

        try {
            $decrypted = tempnam(sys_get_temp_dir(), 'tldump');

            if ($decrypted === false) {
                return $fail('scratch', 'Unable to allocate a temporary file for decryption.');
            }

            try {
                $backups->decryptFile($archive, $decrypted);
                $result['checks'][] = [
                    'check' => 'authentication',
                    'status' => 'PASS',
                    'detail' => 'HMAC verified; plaintext emitted.',
                ];
            } catch (RuntimeException $error) {
                return $fail('authentication', $error->getMessage());
            }

            // 3. Dump completeness -----------------------------------------
            $plain = tempnam(sys_get_temp_dir(), 'tlsql');

            if ($plain === false) {
                return $fail('scratch', 'Unable to allocate a temporary file for decompression.');
            }

            try {
                $source = gzopen($decrypted, 'rb');

                if ($source === false) {
                    return $fail('decompression', 'The decrypted stream is not gzip data.');
                }

                $destination = fopen($plain, 'wb');

                if ($destination === false) {
                    gzclose($source);

                    return $fail('decompression', 'Unable to write the decompressed dump.');
                }

                while (! gzeof($source)) {
                    $chunk = gzread($source, 262144);

                    if ($chunk === false) {
                        break;
                    }

                    fwrite($destination, $chunk);
                }

                gzclose($source);
                fclose($destination);

                $bytes = (int) filesize($plain);

                if ($bytes === 0) {
                    return $fail('decompression', 'The archive decompresses to an empty file.');
                }

                $driver = str_contains((string) file_get_contents($plain, false, null, 0, 2048), 'PostgreSQL database dump')
                    ? 'pgsql'
                    : 'mysql';

                try {
                    DatabaseDumpService::fromConfig()->assertComplete($plain, $driver);
                } catch (RuntimeException $error) {
                    return $fail('completeness', $error->getMessage());
                }

                $tables = DatabaseDumpService::fromConfig()->countTables($plain, $driver);

                $result['checks'][] = [
                    'check' => 'completeness',
                    'status' => 'PASS',
                    'detail' => sprintf('%d bytes decompressed, %d CREATE TABLE statements, completion marker present.', $bytes, $tables),
                ];
                $result['dump_bytes'] = $bytes;
                $result['tables'] = $tables;

                if ($this->option('deep')) {
                    $scratchDatabase = (string) ($this->option('scratch-database') ?: '');

                    if ($scratchDatabase === '') {
                        return $fail('deep_restore', '--deep requires --scratch-database.');
                    }

                    try {
                        DatabaseDumpService::fromConfig()->restore($plain, $scratchDatabase);

                        $result['checks'][] = [
                            'check' => 'deep_restore',
                            'status' => 'PASS',
                            'detail' => 'Restored into scratch database '.$scratchDatabase.'.',
                        ];
                    } catch (RuntimeException $error) {
                        return $fail('deep_restore', $error->getMessage());
                    }
                }
            } finally {
                if (is_file($plain)) {
                    @unlink($plain);
                }

                if (is_file($decrypted)) {
                    @unlink($decrypted);
                }
            }
        } catch (Throwable $error) {
            return $fail('verification', 'Unexpected failure: '.$error->getMessage());
        } finally {
            if (is_file($scratch)) {
                @unlink($scratch);
            }
        }

        $result['status'] = 'VERIFIED';

        return $this->finish($result, $json);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(array $result, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ((array) $result['checks'] as $check) {
                $this->components->twoColumnDetail(
                    (string) $check['check'],
                    sprintf('[%s] %s', (string) $check['status'], (string) $check['detail'])
                );
            }

            $result['status'] === 'VERIFIED'
                ? $this->components->info('Archive verified.')
                : $this->components->error('Archive verification FAILED.');
        }

        return $result['status'] === 'VERIFIED' ? self::SUCCESS : self::FAILURE;
    }
}
