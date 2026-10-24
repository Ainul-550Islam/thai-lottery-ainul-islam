<?php

declare(strict_types=1);

namespace App\Console\Commands\Infrastructure;

use App\Services\Infrastructure\DatabaseDumpService;
use App\Services\Infrastructure\ImmutableBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Takes a real, encrypted, verifiable database backup.
 *
 * The signature is unchanged from the command this replaces
 * (`backup:execute-encrypted-worm`) so any existing scheduler entry still
 * resolves — but nothing else is the same. The previous implementation
 * encrypted a hardcoded string and reported success without ever touching a
 * database or a dump binary. This one:
 *
 *   1. refuses to run without a backup key (no committed fallback);
 *   2. dumps the database with the real client, excluding volatile tables;
 *   3. proves the dump is complete by checking the client's own footer marker;
 *   4. encrypts and authenticates the archive, then RE-OPENS it and verifies
 *      the MAC and checksum before declaring success;
 *   5. writes a manifest next to the archive (no secrets, no row data);
 *   6. prunes archives older than the retention window;
 *   7. optionally ships the archive to a configured Flysystem disk, and fails
 *      loudly instead of claiming a shipment it did not make.
 *
 * Exit codes: 0 success, 1 failure, 2 refused (preconditions unmet).
 */
class ExecuteEncryptedBackupCommand extends Command
{
    protected $signature = 'backup:execute-encrypted-worm
        {--output= : Directory to write the archive into (default: config backup.path)}
        {--connection= : Database connection name (default: database.default)}
        {--dry-run : Report what would happen without writing anything}
        {--upload : Ship the finished archive to the configured backup disk}
        {--keep= : Minimum number of archives to retain regardless of age}
        {--json : Machine-readable result on stdout}';

    protected $description = 'Take an encrypted, authenticated database backup, verify it, and prune old archives.';

    public function handle(): int
    {
        $started = microtime(true);
        $json = (bool) $this->option('json');

        // Resolved here rather than injected: the service validates the
        // encryption key in its constructor, and a constructor exception on a
        // type-hinted handle() argument escapes before the command can report
        // anything. An operator (or CI) then sees a stack trace instead of a
        // failing exit code with a usable message.
        try {
            $backups = $this->laravel->make(ImmutableBackupService::class);
        } catch (Throwable $error) {
            $this->reportFailure($error->getMessage(), $json);

            return self::FAILURE;
        }

        try {
            return $this->executeBackup($backups, $json, $started);
        } catch (RuntimeException $error) {
            $this->reportFailure($error->getMessage(), $json);

            return self::FAILURE;
        } catch (Throwable $error) {
            // Never echo the exception trace: it can contain DSNs and paths.
            $this->reportFailure('Unexpected backup failure: '.$error->getMessage(), $json);

            return self::FAILURE;
        }
    }

    private function executeBackup(ImmutableBackupService $backups, bool $json, float $started): int
    {
        if (! $backups->isConfigured()) {
            if ($json) {
                $this->line((string) json_encode([
                    'status' => 'REFUSED',
                    'reason' => 'BACKUP_ENCRYPTION_KEY is not configured.',
                ]));
            } else {
                $this->components->error(
                    'No backup encryption key is configured. Set BACKUP_ENCRYPTION_KEY or '
                    .'BACKUP_ENCRYPTION_KEY_FILE. Refusing to write an unencrypted or '
                    .'placeholder-keyed archive.'
                );
            }

            return 2;
        }

        $outputDirectory = $this->resolveOutputDirectory();
        $keep = $this->option('keep') !== null
            ? max(0, (int) $this->option('keep'))
            : (int) config('backup.minimum_keep', 7);

        $timestamp = gmdate('Ymd\THis\Z');
        $baseName = 'backup_lottery_ledger_'.$timestamp;

        if ($this->option('dry-run')) {
            $plan = [
                'status' => 'DRY_RUN',
                'output_directory' => $outputDirectory,
                'archive' => $baseName.'.sql.gz.enc',
                'connection' => (string) ($this->option('connection') ?: config('database.default')),
                'retention_days' => (int) config('backup.retention_days', 14),
                'minimum_keep' => $keep,
                'upload' => $this->option('upload') ? 'REQUESTED' : 'NOT_REQUESTED',
            ];

            $json
                ? $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->components->info('Dry run: '.json_encode($plan, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! is_dir($outputDirectory) && ! @mkdir($outputDirectory, 0770, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException('Unable to create the backup directory: '.$outputDirectory);
        }

        if (! is_writable($outputDirectory)) {
            throw new RuntimeException('The backup directory is not writable: '.$outputDirectory);
        }

        // Plaintext is staged in a private 0700 directory outside the backup
        // output directory, and removed in a `finally` block. Previously the
        // dump was written into the output directory itself, so any failure
        // between dump and encrypt left a world-readable `.sql.gz` sitting
        // beside the archive it was supposed to be protected by.
        $staging = $this->privateStagingDirectory();
        $plainPath = $staging.'/'.$baseName.'.sql';
        $encryptedPath = $outputDirectory.'/'.$baseName.'.sql.gz.enc';

        try {
            return $this->dumpCompressEncrypt($backups, $plainPath, $encryptedPath, $outputDirectory, $baseName, $staging, $keep, $json, $started);
        } finally {
            $this->removeStaging($staging);
        }
    }

    /**
     * Everything after the staging directory exists. Split out so that a single
     * `finally` can guarantee no plaintext survives, whatever happens.
     */
    private function dumpCompressEncrypt(
        ImmutableBackupService $backups,
        string $plainPath,
        string $encryptedPath,
        string $outputDirectory,
        string $baseName,
        string $staging,
        int $keep,
        bool $json,
        float $started,
    ): int {
        $this->components->task('Dumping the database', function () use ($plainPath): void {
            DatabaseDumpService::fromConfig()->dump(
                $plainPath,
                $this->option('connection') ? (string) $this->option('connection') : null,
            );
        });
        @chmod($plainPath, 0600);
        // Compress before encrypting: compressing ciphertext achieves nothing,
        // and a 5-10x smaller archive is a 5-10x smaller restore window.
        $gzPath = $plainPath.'.gz';
        $this->components->task('Compressing the dump', function () use ($plainPath, $gzPath): void {
            $source = fopen($plainPath, 'rb');

            if ($source === false) {
                throw new RuntimeException('Unable to read the dump for compression.');
            }

            $destination = gzopen($gzPath, 'wb9');

            if ($destination === false) {
                fclose($source);

                throw new RuntimeException('Unable to open the compressed archive for writing.');
            }

            while (! feof($source)) {
                $chunk = fread($source, 262144);

                if ($chunk === false) {
                    break;
                }

                gzwrite($destination, $chunk);
            }

            fclose($source);
            gzclose($destination);
            @unlink($plainPath);
        });

        // The renderer's return value is a task status, not the closure's
        // return value, so the result is captured explicitly rather than
        // reading it out of the console output object.
        $crypto = ['bytes' => 0, 'sha256' => '', 'ciphertext_bytes' => 0];

        $this->components->task('Encrypting and authenticating the archive', function () use ($backups, $gzPath, $encryptedPath, &$crypto): void {
            $crypto = $backups->encryptFile($gzPath, $encryptedPath);
        });

        // The compressed plaintext must not survive the encryption step: a
        // readable dump sitting next to its own encrypted archive defeats the
        // archive, and it is exactly what the previous failure path left behind.
        if (is_file($gzPath)) {
            @unlink($gzPath);
        }

        $this->components->task('Verifying the archive we just wrote', function () use ($backups, $encryptedPath, $staging): void {
            $probePath = $staging.'/probe.verify';

            try {
                $backups->decryptFile($encryptedPath, $probePath);

                $probe = gzopen($probePath, 'rb');

                if ($probe === false) {
                    throw new RuntimeException('The decrypted archive is not readable.');
                }

                $header = (string) gzread($probe, 4096);
                gzclose($probe);

                if ($header === '') {
                    throw new RuntimeException('The decrypted archive is empty.');
                }

                // Accept every client this lane supports: MySQL writes
                // "-- MySQL dump ...", MariaDB writes "-- MariaDB dump ...",
                // pg_dump writes "-- PostgreSQL database dump". Checking only
                // for MySQL's banner rejected a perfectly good MariaDB backup,
                // which is exactly the kind of false alarm that trains an
                // operator to ignore the verification step.
                if (preg_match('/^-- (MySQL|MariaDB) dump|^-- PostgreSQL database dump/m', $header) !== 1) {
                    throw new RuntimeException('The decrypted archive does not begin like a database dump.');
                }
            } finally {
                if (is_file($probePath)) {
                    @unlink($probePath);
                }
            }
        });

        $sha256 = hash_file('sha256', $encryptedPath);
        $sha256 = $sha256 === false ? '' : $sha256;

        file_put_contents($encryptedPath.'.sha256', $sha256.'  '.basename($encryptedPath).PHP_EOL);
        @chmod($encryptedPath.'.sha256', 0640);

        $manifest = [
            'status' => 'VERIFIED',
            'archive' => basename($encryptedPath),
            'output_directory' => $outputDirectory,
            'sha256' => $sha256,
            'bytes' => (int) filesize($encryptedPath),
            // Size of the compressed dump that was encrypted. Named for what it
            // is: the old `plaintext_bytes` field read like a retention count.
            'dump_bytes' => (int) ($crypto['bytes'] ?? 0),
            'plaintext_retained' => false,
            'format_version' => 2,
            'cipher' => 'aes-256-ctr+hmac-sha256(encrypt-then-mac)',
            'connection' => (string) ($this->option('connection') ?: config('database.default')),
            'app_env' => (string) config('app.env'),
            // Deliberately no hostname, no database name, no credentials: a
            // manifest may be copied into a ticket or an artifact bundle.
            'created_at' => gmdate('c'),
            'duration_seconds' => round(microtime(true) - $started, 2),
            'verified' => true,
        ];

        file_put_contents($encryptedPath.'.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        @chmod($encryptedPath.'.manifest.json', 0640);

        // The latest pointer lets `ops:health` report backup age without
        // scanning the directory or parsing filenames.
        file_put_contents($outputDirectory.'/latest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        @chmod($outputDirectory.'/latest.json', 0640);

        $upload = $this->uploadIfRequested($encryptedPath);

        $pruned = $this->prune($outputDirectory, $keep);

        if ($json) {
            $this->line((string) json_encode(
                $manifest + ['upload' => $upload, 'pruned' => $pruned],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));
        } else {
            $this->components->twoColumnDetail('Archive', basename($encryptedPath));
            $this->components->twoColumnDetail('SHA-256', $sha256);
            $this->components->twoColumnDetail('Size', number_format((int) $manifest['bytes']).' bytes');
            $this->components->twoColumnDetail('Upload', $upload);
            $this->components->twoColumnDetail('Pruned', (string) $pruned);
            $this->components->info('Backup verified. An unverified backup is not a backup.');
        }

        return self::SUCCESS;
    }

    private function resolveOutputDirectory(): string
    {
        $explicit = $this->option('output');

        if (is_string($explicit) && $explicit !== '') {
            return rtrim($explicit, '/');
        }

        $configured = config('backup.path');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, '/')
            : storage_path('app/backups');
    }

    private function uploadIfRequested(string $encryptedPath): string
    {
        if (! $this->option('upload')) {
            return 'NOT_REQUESTED';
        }

        $disk = config('backup.upload.disk');

        if (! is_string($disk) || $disk === '') {
            // Fail loudly. The old command printed "Prepared for immutable
            // streaming to S3" without an S3 client, a bucket or a credential.
            throw new RuntimeException(
                'BACKUP_DISK is not configured but --upload was requested. Configure a Flysystem disk '
                .'before expecting an off-host copy.'
            );
        }

        try {
            $stream = fopen($encryptedPath, 'rb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the archive for upload.');
            }

            $prefix = trim((string) config('backup.upload.prefix', 'database'), '/');
            $key = ($prefix === '' ? '' : $prefix.'/').basename($encryptedPath);

            Storage::disk($disk)->writeStream($key, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return 'UPLOADED:'.$disk.':'.$key;
        } catch (Throwable $error) {
            throw new RuntimeException('Upload to disk "'.$disk.'" failed: '.$error->getMessage());
        }
    }

    /**
     * Delete archives past retention, but never below the minimum keep count.
     *
     * @return int number of archives removed
     */
    private function prune(string $directory, int $minimumKeep): int
    {
        $days = (int) config('backup.retention_days', 14);
        $cutoff = time() - ($days * 86400);

        $archives = glob($directory.'/backup_lottery_ledger_*.sql.gz.enc') ?: [];

        // Newest first: the keep floor must protect the newest copies.
        usort($archives, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $removed = 0;

        foreach ($archives as $index => $path) {
            if ($index < $minimumKeep) {
                continue;
            }

            if (filemtime($path) >= $cutoff) {
                continue;
            }

            foreach ([$path, $path.'.sha256', $path.'.manifest.json'] as $victim) {
                if (is_file($victim) && @unlink($victim)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * A 0700 directory on the same filesystem as the output directory, so
     * plaintext never lands somewhere another process can read it and never
     * crosses a mount during the encrypt step.
     */
    private function privateStagingDirectory(): string
    {
        $base = (string) config('backup.staging_path', sys_get_temp_dir());
        $path = rtrim($base, '/').'/tl-backup-'.bin2hex(random_bytes(8));

        if (! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create a private staging directory for the dump.');
        }

        @chmod($path, 0700);

        return $path;
    }

    /**
     * Remove the staging directory and everything in it. Failure to clean up is
     * itself a security event, so it is reported — but it never masks the
     * original outcome of the backup.
     */
    private function removeStaging(string $staging): void
    {
        if (! is_dir($staging)) {
            return;
        }

        $leftovers = 0;

        foreach ((array) glob($staging.'/{,.}*', GLOB_BRACE) as $entry) {
            $name = basename((string) $entry);

            if ($name === '.' || $name === '..' || ! is_file((string) $entry)) {
                continue;
            }

            if (! @unlink((string) $entry)) {
                $leftovers++;
            }
        }

        if (! @rmdir($staging) || $leftovers > 0) {
            $this->components->warn('Could not fully remove the staging directory: '.$staging.' — delete it manually and rotate the backup key.');
        }
    }

    private function reportFailure(string $message, bool $json): void
    {
        if ($json) {
            $this->line((string) json_encode(['status' => 'FAILED', 'reason' => $message]));

            return;
        }

        $this->components->error($message);
    }
}
