<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Contracts\Security\MalwareScanner;
use App\DTOs\Security\MalwareScanResult;
use App\Enums\MalwareScanStatus;
use App\Services\Uploads\Scanners\ClamAvScanner;
use App\Services\Uploads\UploadQuarantineService;
use App\Services\Uploads\UploadScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** A clean verdict belongs to the exact bytes staged by the quarantine record. */
final class QuarantineScanIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL = "%PDF-1.4\noriginal synthetic A\n%%EOF\n";
    private const CHANGED = "%PDF-1.4\nreplaced synthetic B\n%%EOF\n";

    public static function cases(): array
    {
        return [
            'quarantine disk, original' => ['quarantine', 'none'],
            'quarantine disk, substituted before scan' => ['quarantine', 'before'],
            'quarantine disk, replaced after scan' => ['quarantine', 'after'],
            'private disk, original' => ['private', 'none'],
            'private disk, substituted before scan' => ['private', 'before'],
            'private disk, replaced after scan' => ['private', 'after'],
        ];
    }

    public static function invalidProofs(): array
    {
        return [
            'missing scan digest' => ['missing_scan_digest'],
            'wrong scan size' => ['wrong_scan_size'],
            'missing record digest' => ['missing_record_digest'],
        ];
    }

    #[DataProvider('invalidProofs')]
    public function test_clean_reply_without_complete_matching_proof_is_an_error(string $fault): void
    {
        Storage::fake('quarantine');
        $upload = UploadedFile::fake()->createWithContent('slip.pdf', self::ORIGINAL);
        $quarantine = new UploadQuarantineService;
        $record = $quarantine->quarantineUploadedFile($upload, 'test-user', 'TEST');
        $digest = $fault === 'missing_scan_digest' ? '' : hash('sha256', self::ORIGINAL);
        $size = strlen(self::ORIGINAL) + ($fault === 'wrong_scan_size' ? 1 : 0);
        if ($fault === 'missing_record_digest') {
            $record->update(['file_sha256' => '']);
        }
        $scanner = Mockery::mock(MalwareScanner::class);
        $scanner->shouldReceive('scanStream')->once()->andReturn(MalwareScanResult::clean(
            fileSha256: $digest, fileSizeBytes: $size,
        ));

        $result = (new UploadScanService($quarantine, $scanner))->scanRecord($record->id);
        $this->assertSame(MalwareScanStatus::ERROR, $result->status);
        $this->assertFalse($result->isClean());
        $this->assertSame('SCAN_CONTENT_MISMATCH', $result->threatName);
        $this->assertSame(MalwareScanStatus::ERROR, $record->fresh()->scan_status);
    }

    #[DataProvider('cases')]
    public function test_clean_verdict_is_bound_to_original_staged_content(string $disk, string $mutation): void
    {
        Storage::fake('quarantine');
        Storage::fake('private');
        $upload = UploadedFile::fake()->createWithContent('slip.pdf', self::ORIGINAL);
        $quarantine = new UploadQuarantineService;
        $record = $disk === 'quarantine'
            ? $quarantine->quarantineUploadedFile($upload, 'test-user', 'TEST')
            : $quarantine->stageInQuarantine($upload, 'TEST', 'test-user');
        $this->assertSame(hash('sha256', self::ORIGINAL), $record->file_sha256);
        $this->assertSame(strlen(self::ORIGINAL), $record->file_size_bytes);
        if ($mutation !== 'none') {
            $this->assertSame(strlen(self::ORIGINAL), strlen(self::CHANGED));
        }
        if ($mutation === 'before') {
            Storage::disk($disk)->put($record->private_storage_key, self::CHANGED);
        }

        $base = sys_get_temp_dir().'/quarantine-scan-'.bin2hex(random_bytes(8));
        $socket = $base.'.sock';
        $transcript = $base.'.json';
        $command = [PHP_BINARY, base_path('tests/Support/ClamAvProtocolStub.php'),
            $socket, $transcript, base64_encode("stream: OK\0"), '1'];
        if ($mutation === 'after') {
            $command[] = Storage::disk($disk)->path($record->private_storage_key);
            $command[] = base64_encode(self::CHANGED);
        }
        $process = new Process($command, base_path());
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
                    $this->fail('Protocol stub failed: '.$process->getErrorOutput());
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertFileExists($socket);
            config([
                'antimalware.clamav.socket' => 'unix://'.$socket,
                'antimalware.timeout_seconds' => 3,
                'antimalware.max_file_size_bytes' => 4096,
            ]);
            $result = (new UploadScanService($quarantine, app(ClamAvScanner::class)))->scanRecord($record->id);
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertFileExists($transcript);
            $rows = json_decode((string) file_get_contents($transcript), true, 32, JSON_THROW_ON_ERROR);
            $this->assertCount(1, $rows);
            $this->assertSame(hash('sha256', $mutation === 'before' ? self::CHANGED : self::ORIGINAL), $rows[0]['sha256']);
            $this->assertTrue($rows[0]['complete']);
            $this->assertSame($mutation !== 'none' ? MalwareScanStatus::ERROR : MalwareScanStatus::CLEAN, $result->status);
            $this->assertSame($result->status, $record->fresh()->scan_status);
            if ($mutation !== 'none') {
                $this->assertSame('SCAN_CONTENT_MISMATCH', $result->threatName);
                $this->assertFalse($result->isClean());
                $this->assertSame(hash('sha256', self::ORIGINAL), $record->fresh()->file_sha256);
            } else {
                $this->assertTrue($result->isClean());
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            @unlink($socket);
            @unlink($transcript);
        }
    }
}
