<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Contracts\Security\MalwareScanner;
use App\Enums\MalwareScanStatus;
use App\Exceptions\AntimalwareException;
use App\Services\Antimalware\AntimalwareManager;
use App\Services\Antimalware\ScannerInterface;
use App\Services\Uploads\Scanners\ClamAvScanner;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real protocol exchanges against a disposable local stub, not a live clamd. */
final class UnifiedClamAvScannerTest extends TestCase
{
    private function exchange(string $reply, int $connections, callable $check): array
    {
        $base = sys_get_temp_dir().'/clam-test-'.bin2hex(random_bytes(8));
        $socket = $base.'.sock';
        $transcript = $base.'.json';
        $process = new Process([
            PHP_BINARY, base_path('tests/Support/ClamAvProtocolStub.php'),
            $socket, $transcript, base64_encode($reply), (string) $connections,
        ], base_path());
        $process->setTimeout(20);
        $process->start();

        try {
            $deadline = microtime(true) + 5;
            do {
                clearstatcache(true, $socket);
                if (file_exists($socket)) {
                    break;
                }
                if (! $process->isRunning()) {
                    $this->fail('Scanner stub failed: '.$process->getErrorOutput());
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertFileExists($socket, 'The local scanner stub did not start.');

            config([
                'antimalware.driver' => 'clamav',
                'antimalware.clamav.socket' => 'unix://'.$socket,
                'antimalware.max_file_size_bytes' => 4096,
                'antimalware.timeout_seconds' => 3,
            ]);
            $check();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertFileExists($transcript);

            return json_decode((string) file_get_contents($transcript), true, 32, JSON_THROW_ON_ERROR);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            @unlink($socket);
            @unlink($transcript);
        }
    }

    private function file(string $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'clam-bytes-');
        $this->assertIsString($path);
        file_put_contents($path, $data);

        return $path;
    }

    public function test_both_contracts_use_the_existing_scanner_and_stream_all_bytes(): void
    {
        $bytes = "hello\0clamav\n";
        $path = $this->file($bytes);
        try {
            $transcript = $this->exchange("stream: OK\0", 2, function () use ($path, $bytes): void {
                $driver = app(AntimalwareManager::class)->driver();
                $scanner = app(MalwareScanner::class);
                $this->assertInstanceOf(ClamAvScanner::class, $driver);
                $this->assertInstanceOf(ScannerInterface::class, $scanner);
                $this->assertInstanceOf(ClamAvScanner::class, $scanner);
                $this->assertFileDoesNotExist(app_path('Services/Antimalware/ClamAvScanner.php'));

                $verdict = $driver->scan($path);
                $this->assertSame('clean', $verdict['verdict']);
                $this->assertSame(strlen($bytes), $verdict['bytes']);
                $this->assertSame(hash('sha256', $bytes), $verdict['sha256']);
                $quarantine = $scanner->scanFile($path);
                $this->assertSame(MalwareScanStatus::CLEAN, $quarantine->status);
                $this->assertSame(hash('sha256', $bytes), $quarantine->fileSha256);
            });
            $this->assertCount(2, $transcript);
            foreach ($transcript as $request) {
                $this->assertSame(base64_encode("zINSTREAM\0"), $request['command']);
                $this->assertTrue($request['complete']);
                $this->assertSame(strlen($bytes), $request['bytes']);
                $this->assertSame(hash('sha256', $bytes), $request['sha256']);
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_infected_verdict_is_never_a_clean_manager_result(): void
    {
        $path = $this->file('known sample');
        try {
            $this->exchange("stream: Eicar-Test-Signature FOUND\0", 2, function () use ($path): void {
                try {
                    app(AntimalwareManager::class)->driver()->scan($path);
                    $this->fail('An infected file was reported clean.');
                } catch (AntimalwareException $error) {
                    $this->assertSame('antimalware_infected', $error->errorCode());
                    $this->assertSame('Eicar-Test-Signature', $error->context()['signature']);
                }
                $result = app(MalwareScanner::class)->scanFile($path);
                $this->assertSame(MalwareScanStatus::INFECTED, $result->status);
                $this->assertSame('Eicar-Test-Signature', $result->threatName);
            });
        } finally {
            @unlink($path);
        }
    }

    public function test_unknown_daemon_reply_fails_closed_for_both_contracts(): void
    {
        $path = $this->file('untrusted sample');
        try {
            $this->exchange("stream: UNKNOWN\0", 2, function () use ($path): void {
                try {
                    app(AntimalwareManager::class)->driver()->scan($path);
                    $this->fail('An unknown reply was reported clean.');
                } catch (AntimalwareException $error) {
                    $this->assertSame('antimalware_unavailable', $error->errorCode());
                }
                $result = app(MalwareScanner::class)->scanFile($path);
                $this->assertSame(MalwareScanStatus::ERROR, $result->status);
                $this->assertFalse($result->isClean());
            });
        } finally {
            @unlink($path);
        }
    }

    public function test_empty_non_eof_read_refuses_the_stream_instead_of_scanning_a_prefix(): void
    {
        $this->assertTrue(stream_wrapper_register('non-eof-test', NonEofEmptyTestStream::class));
        $handle = fopen('non-eof-test://sample', 'rb');
        $this->assertIsResource($handle);
        try {
            $transcript = $this->exchange("stream: OK\0", 1, function () use ($handle): void {
                $result = app(MalwareScanner::class)->scanStream($handle);
                $this->assertSame(MalwareScanStatus::ERROR, $result->status);
                $this->assertSame('FILE_READ_INCOMPLETE', $result->threatName);
                $this->assertFalse($result->isClean());
            });
            $this->assertFalse($transcript[0]['complete']);
        } finally {
            fclose($handle);
            stream_wrapper_unregister('non-eof-test');
        }
    }
}

/** Test stream that temporarily returns no data without claiming EOF. */
final class NonEofEmptyTestStream
{
    public mixed $context = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }
}
