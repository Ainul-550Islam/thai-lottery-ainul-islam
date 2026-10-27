<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Contracts\Security\MalwareScanner;
use App\DTOs\Security\MalwareScanResult;
use App\Enums\MalwareScanStatus;
use App\Services\Uploads\Scanners\ClamAvScanner;
use Tests\TestCase;

/**
 * The scanner behind the quarantine upload gate.
 *
 * ============================================================================
 * NO CLAMD IS REQUIRED, AND NONE IS CONTACTED
 * ============================================================================
 * Every case below is a decision the scanner makes BEFORE or INSTEAD of a
 * successful conversation with the daemon: no address configured, an address
 * nothing is listening on, a file that is missing, a file over the ceiling, a
 * stream that is not a stream. Those are exactly the cases where a permissive
 * implementation returns `clean` because it had nothing to compare against —
 * and a `clean` verdict is what releases an upload into storage.
 *
 * The INSTREAM wire protocol itself (chunk framing, `stream: OK`,
 * `stream: <sig> FOUND`) needs a stub daemon and is not exercised here.
 *
 * ============================================================================
 * THE BUG THESE TESTS LOCK DOWN
 * ============================================================================
 * `UploadScanService::scanRecord()` calls `scanStream()` on the quarantine
 * path. That method did not exist on this class or on the contract, so the
 * gate died with `Call to undefined method` before any bytes were sent.
 * `test_the_scanner_satisfies_the_contract_and_implements_scan_stream` fails if
 * that hole reopens, and it fails at the interface level rather than waiting
 * for a request to hit it.
 */
final class UploadClamAvScannerTest extends TestCase
{
    private const DEAD_SOCKET_TIMEOUT = 1;

    private function scanner(): ClamAvScanner
    {
        return $this->app->make(ClamAvScanner::class);
    }

    private function tempFile(string $contents = 'hello'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tl-upload-clam-');

        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }

    private function deadSocket(string $name = 'not-a-daemon.sock'): string
    {
        return 'unix://'.storage_path('app/private/'.$name);
    }

    /**
     * @return array{status: MalwareScanStatus, threatName: string|null}
     */
    private function verdict(MalwareScanResult $result): array
    {
        return ['status' => $result->status, 'threatName' => $result->threatName];
    }

    // ---------------------------------------------------------------------
    // The hole that made the gate unreachable
    // ---------------------------------------------------------------------

    public function test_the_scanner_satisfies_the_contract_and_implements_scan_stream(): void
    {
        $scanner = $this->scanner();

        $this->assertInstanceOf(MalwareScanner::class, $scanner);

        // The method the caller actually invokes on the quarantine path. This is
        // the whole point: the contract must declare it, or a second
        // implementation can ship without it and only fail in production.
        $this->assertTrue(
            method_exists($scanner, 'scanStream'),
            'UploadScanService::scanRecord() calls scanStream(); the scanner must implement it',
        );

        $contract = new \ReflectionClass(MalwareScanner::class);

        $this->assertTrue(
            $contract->hasMethod('scanStream'),
            'the contract must declare scanStream(), not only the implementation',
        );
    }

    // ---------------------------------------------------------------------
    // Fail-closed on every path that is not a clean daemon answer
    // ---------------------------------------------------------------------

    public function test_a_missing_file_is_an_error_verdict_and_never_clean(): void
    {
        config(['antimalware.clamav.socket' => $this->deadSocket()]);

        $result = $this->scanner()->scanFile(storage_path('app/private/does-not-exist.bin'));

        $this->assertSame(MalwareScanStatus::ERROR, $result->status);
        $this->assertFalse($result->isClean());
        $this->assertSame('FILE_NOT_FOUND', $result->threatName);
        $this->assertSame(ClamAvScanner::ENGINE, $result->scannerEngine);
    }

    public function test_a_directory_is_not_scanned_as_a_file(): void
    {
        config(['antimalware.clamav.socket' => $this->deadSocket()]);

        $result = $this->scanner()->scanFile(storage_path('app/private'));

        $this->assertSame(MalwareScanStatus::ERROR, $result->status);
        $this->assertFalse($result->isClean());
        $this->assertSame('FILE_NOT_FOUND', $result->threatName);
    }

    public function test_a_file_over_the_configured_ceiling_is_refused_before_the_daemon_is_contacted(): void
    {
        config([
            'antimalware.clamav.socket' => $this->deadSocket(),
            'antimalware.max_file_size_bytes' => 1024,
        ]);

        $path = $this->tempFile(str_repeat('a', 4096));

        try {
            $result = $this->scanner()->scanFile($path, 'kyc-front.jpg');

            $this->assertSame(MalwareScanStatus::ERROR, $result->status);
            $this->assertFalse($result->isClean());
            $this->assertSame(4096, $result->fileSizeBytes);

            // The refusal names both numbers, so an operator can tell a real
            // oversized upload from a misconfigured ceiling.
            $this->assertIsString($result->threatName);
            $this->assertStringStartsWith('FILE_TOO_LARGE', $result->threatName);
            $this->assertStringContainsString('4096', $result->threatName);
            $this->assertStringContainsString('1024', $result->threatName);
        } finally {
            @unlink($path);
        }
    }

    public function test_an_unreachable_daemon_is_an_error_verdict_and_never_clean(): void
    {
        config([
            'antimalware.clamav.socket' => $this->deadSocket(),
            'antimalware.max_file_size_bytes' => 4_194_304,
            'antimalware.timeout_seconds' => self::DEAD_SOCKET_TIMEOUT,
        ]);

        $path = $this->tempFile();

        try {
            $result = $this->scanner()->scanFile($path, 'slip.png');

            $this->assertSame(MalwareScanStatus::ERROR, $result->status);
            $this->assertFalse($result->isClean());
            $this->assertIsString($result->threatName);
            $this->assertStringStartsWith('SCANNER_OUTAGE', $result->threatName);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_outage_verdict_does_not_publish_the_internal_address(): void
    {
        // The verdict lands in upload_scan_records.threat_name and in a log
        // line. A connect failure must not carry the socket path or host:port
        // into either of them.
        config([
            'antimalware.clamav.socket' => $this->deadSocket('secret-internal-endpoint.sock'),
            'antimalware.max_file_size_bytes' => 4_194_304,
            'antimalware.timeout_seconds' => self::DEAD_SOCKET_TIMEOUT,
        ]);

        $path = $this->tempFile();

        try {
            $result = $this->scanner()->scanFile($path);

            $encoded = (string) json_encode($this->verdict($result));

            $this->assertStringNotContainsString('secret-internal-endpoint', $encoded);
            $this->assertStringNotContainsString('unix://', $encoded);
        } finally {
            @unlink($path);
        }
    }

    // ---------------------------------------------------------------------
    // Stream scanning: the caller owns the handle
    // ---------------------------------------------------------------------

    public function test_a_non_resource_is_refused_instead_of_reported_clean(): void
    {
        config(['antimalware.clamav.socket' => $this->deadSocket()]);

        $result = $this->scanner()->scanStream('not-a-stream', 'upload.pdf');

        $this->assertSame(MalwareScanStatus::ERROR, $result->status);
        $this->assertFalse($result->isClean());
        $this->assertSame('STREAM_NOT_READABLE', $result->threatName);
    }

    public function test_scan_stream_never_closes_a_caller_owned_handle(): void
    {
        config([
            'antimalware.clamav.socket' => $this->deadSocket(),
            'antimalware.max_file_size_bytes' => 4_194_304,
            'antimalware.timeout_seconds' => self::DEAD_SOCKET_TIMEOUT,
        ]);

        $path = $this->tempFile();
        $handle = fopen($path, 'rb');

        $this->assertIsResource($handle);

        try {
            $result = $this->scanner()->scanStream($handle, 'upload.pdf');

            $this->assertSame(MalwareScanStatus::ERROR, $result->status);
            $this->assertFalse($result->isClean());

            // UploadScanService closes it in its own finally block. A second
            // fclose() here would turn a clean refusal into a warning.
            $this->assertTrue(is_resource($handle), 'scanStream() must not close a caller-owned stream');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($path);
        }
    }

    public function test_scan_stream_and_scan_file_reach_the_same_conclusion(): void
    {
        config([
            'antimalware.clamav.socket' => $this->deadSocket(),
            'antimalware.max_file_size_bytes' => 4_194_304,
            'antimalware.timeout_seconds' => self::DEAD_SOCKET_TIMEOUT,
        ]);

        $path = $this->tempFile('identical bytes');
        $handle = fopen($path, 'rb');

        $this->assertIsResource($handle);

        try {
            $fromFile = $this->scanner()->scanFile($path, 'upload.pdf');
            $fromStream = $this->scanner()->scanStream($handle, 'upload.pdf');

            $this->assertSame($fromFile->status, $fromStream->status);
            $this->assertSame($fromFile->threatName, $fromStream->threatName);
            $this->assertFalse($fromFile->isClean());
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink($path);
        }
    }

    // ---------------------------------------------------------------------
    // Honest reporting
    // ---------------------------------------------------------------------

    public function test_no_configured_address_makes_the_scanner_unavailable_without_dialling_anything(): void
    {
        config([
            'antimalware.clamav.socket' => '',
            'antimalware.clamav.host' => '',
            'antimalware.clamav.port' => 0,
        ]);

        $scanner = $this->scanner();

        $this->assertFalse($scanner->isAvailable(), 'no address means there is nowhere to send a file');

        $path = $this->tempFile();

        try {
            $result = $scanner->scanFile($path);

            $this->assertSame(MalwareScanStatus::ERROR, $result->status);
            $this->assertFalse($result->isClean());
        } finally {
            @unlink($path);
        }
    }

    public function test_a_configured_unix_socket_is_available_without_opening_it(): void
    {
        config([
            'antimalware.clamav.socket' => 'unix:///var/run/clamav/clamd.ctl',
            'antimalware.clamav.host' => '',
            'antimalware.clamav.port' => 0,
        ]);

        // The docblock used to promise unix socket support while the code only
        // ever dialled host:port. Availability is a configuration statement.
        $this->assertTrue($this->scanner()->isAvailable());
    }

    public function test_the_host_port_pair_is_available_when_no_socket_is_set(): void
    {
        config([
            'antimalware.clamav.socket' => '',
            'antimalware.clamav.host' => '127.0.0.1',
            'antimalware.clamav.port' => 3310,
        ]);

        $this->assertTrue($this->scanner()->isAvailable());
    }

    public function test_an_out_of_range_port_is_a_typo_not_an_address(): void
    {
        config([
            'antimalware.clamav.socket' => '',
            'antimalware.clamav.host' => '127.0.0.1',
            'antimalware.clamav.port' => 99999,
        ]);

        $this->assertFalse($this->scanner()->isAvailable());
    }

    public function test_version_info_never_invents_a_database_version(): void
    {
        config([
            'antimalware.clamav.socket' => $this->deadSocket('never-answers.sock'),
            'antimalware.timeout_seconds' => self::DEAD_SOCKET_TIMEOUT,
        ]);

        $info = $this->scanner()->getVersionInfo();

        $this->assertSame('UNREACHABLE', $info['version']);
        $this->assertFalse($info['engine_active']);

        // The admin security page prints this value. A hard-coded fallback
        // version is an unobserved claim about signature freshness.
        $this->assertNotSame('ClamAV 1.3', $info['version']);
        $this->assertSame(ClamAvScanner::ENGINE, $info['scanner']);
    }

    public function test_ping_is_false_when_the_daemon_cannot_be_reached(): void
    {
        config([
            'antimalware.clamav.socket' => $this->deadSocket(),
            'antimalware.timeout_seconds' => self::DEAD_SOCKET_TIMEOUT,
        ]);

        $this->assertFalse($this->scanner()->ping());
    }

    public function test_no_verdict_is_clean_except_one_the_daemon_returned(): void
    {
        // A sweep over the configurations that can reach a refusal. If any of
        // them ever reports clean, an unscanned file is released into storage.
        config([
            'antimalware.clamav.socket' => $this->deadSocket(),
            'antimalware.max_file_size_bytes' => 4_194_304,
            'antimalware.timeout_seconds' => self::DEAD_SOCKET_TIMEOUT,
        ]);

        $path = $this->tempFile();

        try {
            $handle = fopen($path, 'rb');

            $this->assertIsResource($handle);

            $verdicts = [
                $this->scanner()->scanFile($path),
                $this->scanner()->scanFile(storage_path('app/private/missing.bin')),
                $this->scanner()->scanStream($handle),
            ];

            if (is_resource($handle)) {
                fclose($handle);
            }

            foreach ($verdicts as $result) {
                $this->assertFalse($result->isClean(), 'an unreachable daemon produced a clean verdict');
                $this->assertSame(MalwareScanStatus::ERROR, $result->status);
            }
        } finally {
            @unlink($path);
        }
    }
}
