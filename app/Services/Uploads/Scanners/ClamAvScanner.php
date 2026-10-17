<?php

declare(strict_types=1);

namespace App\Services\Uploads\Scanners;

use App\Contracts\Security\MalwareScanner;
use App\DTOs\Security\MalwareScanResult;
use App\Enums\MalwareScanStatus;
use RuntimeException;
use Throwable;

/**
 * Class ClamAvScanner
 *
 * Real TCP/Unix socket streaming adapter for ClamAV daemon.
 * Scanner unavailable or timeout is NEVER treated as clean (fails closed).
 */
class ClamAvScanner implements MalwareScanner
{
    private string $host;
    private int $port;
    private int $timeoutSeconds;

    public function __construct()
    {
        $this->host = (string) config('antimalware.clamav.host', '127.0.0.1');
        $this->port = (int) config('antimalware.clamav.port', 3310);
        $this->timeoutSeconds = (int) config('antimalware.timeout_seconds', 15);
    }

    public function scanFile(string $filePath, ?string $originalFilename = null): MalwareScanResult
    {
        $startTime = microtime(true);

        if (!file_exists($filePath)) {
            return new MalwareScanResult(
                status: MalwareScanStatus::ERROR,
                threatName: 'FILE_NOT_FOUND',
                scannerEngine: 'ClamAV',
                fileSha256: '',
                fileSizeBytes: 0,
                scanDurationMs: 0.00
            );
        }

        $fileSize = filesize($filePath) ?: 0;
        $fileSha256 = hash_file('sha256', $filePath) ?: '';

        try {
            $socket = @fsockopen($this->host, $this->port, $errno, $errstr, (float) $this->timeoutSeconds);

            if (!$socket) {
                throw new RuntimeException("Cannot connect to ClamAV socket [{$this->host}:{$this->port}]: {$errstr} ({$errno})");
            }

            stream_set_timeout($socket, $this->timeoutSeconds);

            // Send zINSTREAM command (zero-delimited chunked streaming)
            fwrite($socket, "zINSTREAM\0");

            $fileHandle = fopen($filePath, 'rb');
            if (!$fileHandle) {
                fclose($socket);
                throw new RuntimeException("Failed to open file for streaming: {$filePath}");
            }

            while (!feof($fileHandle)) {
                $chunk = fread($fileHandle, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $chunkLength = pack('N', strlen($chunk));
                fwrite($socket, $chunkLength . $chunk);
            }

            fclose($fileHandle);

            // Send zero-length chunk to terminate stream
            fwrite($socket, pack('N', 0));

            $response = trim((string) fgets($socket));
            fclose($socket);

            $durationMs = (microtime(true) - $startTime) * 1000;

            // ClamAV response parsing: "stream: OK" vs "stream: Eicar-Test-Signature FOUND"
            if (str_ends_with($response, 'OK')) {
                return new MalwareScanResult(
                    status: MalwareScanStatus::CLEAN,
                    threatName: null,
                    scannerEngine: 'ClamAV',
                    fileSha256: $fileSha256,
                    fileSizeBytes: $fileSize,
                    scanDurationMs: round($durationMs, 2)
                );
            }

            if (str_contains($response, 'FOUND')) {
                preg_match('/stream:\s+(.+)\s+FOUND/i', $response, $matches);
                $threatName = $matches[1] ?? 'MALICIOUS_PAYLOAD_DETECTED';

                return new MalwareScanResult(
                    status: MalwareScanStatus::INFECTED,
                    threatName: $threatName,
                    scannerEngine: 'ClamAV',
                    fileSha256: $fileSha256,
                    fileSizeBytes: $fileSize,
                    scanDurationMs: round($durationMs, 2)
                );
            }

            return new MalwareScanResult(
                status: MalwareScanStatus::ERROR,
                threatName: "UNKNOWN_SCANNER_RESPONSE: {$response}",
                scannerEngine: 'ClamAV',
                fileSha256: $fileSha256,
                fileSizeBytes: $fileSize,
                scanDurationMs: round($durationMs, 2)
            );
        } catch (Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;

            return new MalwareScanResult(
                status: MalwareScanStatus::ERROR,
                threatName: "SCANNER_OUTAGE: {$e->getMessage()}",
                scannerEngine: 'ClamAV',
                fileSha256: $fileSha256,
                fileSizeBytes: $fileSize,
                scanDurationMs: round($durationMs, 2)
            );
        }
    }

    public function ping(): bool
    {
        try {
            $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 2.0);
            if (!$socket) {
                return false;
            }
            fwrite($socket, "PING\n");
            $response = trim((string) fgets($socket));
            fclose($socket);

            return $response === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }

    public function getVersionInfo(): array
    {
        try {
            $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 2.0);
            if (!$socket) {
                return ['scanner' => 'ClamAV', 'version' => 'OFFLINE', 'engine_active' => false];
            }
            fwrite($socket, "VERSION\n");
            $response = trim((string) fgets($socket));
            fclose($socket);

            return [
                'scanner' => 'ClamAV',
                'version' => $response ?: 'ClamAV 1.3',
                'engine_active' => true,
            ];
        } catch (Throwable) {
            return ['scanner' => 'ClamAV', 'version' => 'UNREACHABLE', 'engine_active' => false];
        }
    }
}
