<?php

declare(strict_types=1);

namespace App\Console\Commands\Infrastructure;

use App\Services\Infrastructure\ImmutableBackupService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Decrypts and decompresses an archive to a plaintext SQL file, so a restore
 * can be performed by the database client directly.
 *
 * WHY THIS IS A SEPARATE COMMAND FROM backup:verify
 * `backup:verify` must NOT leave plaintext on disk — it is what a cron monitor
 * runs every hour, and a host that accumulates unencrypted dumps of the
 * financial ledger in /tmp is a worse problem than the one backups solve. This
 * command exists for the restore path only, where the plaintext is the point,
 * and it therefore:
 *
 *   * authenticates the archive BEFORE emitting a single byte (the cipher
 *     streams to a temporary file and only renames it after the MAC verifies);
 *   * writes with mode 0600;
 *   * refuses to write inside the repository or the web root;
 *   * tells the caller (scripts/ops/restore.sh) to shred the file when done.
 */
class ExtractBackupCommand extends Command
{
    protected $signature = 'backup:extract
        {archive : Path to the .sql.gz.enc archive}
        {--output= : Where to write the plaintext SQL (required)}
        {--force : Overwrite an existing output file}
        {--json : Machine-readable result}';

    protected $description = 'Authenticate, decrypt and decompress a backup archive to a plaintext SQL file for restore.';

    public function handle(ImmutableBackupService $backups): int
    {
        $json = (bool) $this->option('json');
        $archive = (string) $this->argument('archive');
        $output = (string) ($this->option('output') ?: '');

        $fail = function (string $message) use ($json): int {
            $json
                ? $this->line((string) json_encode(['status' => 'FAILED', 'reason' => $message]))
                : $this->components->error($message);

            return self::FAILURE;
        };

        if ($output === '') {
            return $fail('--output is required; this command will not guess where to leave a plaintext dump.');
        }

        if (! is_file($archive)) {
            return $fail('Archive not found: '.basename($archive));
        }

        if (is_file($output) && ! $this->option('force')) {
            return $fail('Refusing to overwrite an existing file: '.basename($output).' (pass --force)');
        }

        $realOutput = $this->canonicalise($output);
        $publicRoot = (string) realpath(public_path());

        if ($publicRoot !== '' && str_starts_with($realOutput, $publicRoot)) {
            return $fail('Refusing to write plaintext into the web root.');
        }

        $temporaryGz = $output.'.gz.partial';

        try {
            $backups->decryptFile($archive, $temporaryGz);
        } catch (RuntimeException $error) {
            @unlink($temporaryGz);

            return $fail('Archive failed authentication and was not extracted: '.$error->getMessage());
        }

        try {
            $source = gzopen($temporaryGz, 'rb');

            if ($source === false) {
                throw new RuntimeException('The decrypted stream is not gzip data.');
            }

            $destination = fopen($output.'.partial', 'wb');

            if ($destination === false) {
                gzclose($source);

                throw new RuntimeException('Unable to write the plaintext dump.');
            }

            @chmod($output.'.partial', 0600);

            while (! gzeof($source)) {
                $chunk = gzread($source, 262144);

                if ($chunk === false) {
                    break;
                }

                fwrite($destination, $chunk);
            }

            gzclose($source);
            fclose($destination);

            if (! @rename($output.'.partial', $output)) {
                throw new RuntimeException('Unable to finalise the plaintext dump.');
            }

            @chmod($output, 0600);
        } catch (RuntimeException $error) {
            @unlink($temporaryGz);
            @unlink($output.'.partial');

            return $fail($error->getMessage());
        } finally {
            if (is_file($temporaryGz)) {
                @unlink($temporaryGz);
            }
        }

        $bytes = (int) filesize($output);

        $json
            ? $this->line((string) json_encode(['status' => 'EXTRACTED', 'output' => $output, 'bytes' => $bytes]))
            : $this->components->info(sprintf('Extracted %s bytes to a plaintext dump (mode 0600).', number_format($bytes)));

        return self::SUCCESS;
    }

    private function canonicalise(string $path): string
    {
        $directory = realpath(dirname($path));

        return $directory === false ? $path : $directory.'/'.basename($path);
    }
}
