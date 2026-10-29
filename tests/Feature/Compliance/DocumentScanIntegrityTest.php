<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Enums\KycDocumentType;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Verification\DocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** An observed clean scan must bind to the bytes actually stored for review. */
final class DocumentScanIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL = "%PDF-1.4\nsynthetic original A\n%%EOF\n";
    private const REPLACED = "%PDF-1.4\nsynthetic replaced B\n%%EOF\n";

    public function test_clean_scan_persists_the_same_bytes_that_were_streamed(): void
    {
        [$document, $transcript] = $this->uploadWithProtocolStub(null);
        $this->assertInstanceOf(KycDocument::class, $document);
        $this->assertSame(hash('sha256', self::ORIGINAL), $transcript['sha256']);
        $this->assertTrue($transcript['complete']);
        $this->assertSame(self::ORIGINAL, Storage::disk('kyc-test')->get($document->file_path));
        $this->assertSame('clean', $document->metadata['scan']['verdict']);
        $this->assertSame('clamav', $document->metadata['scan']['driver']);
    }

    public function test_replacing_a_scanned_document_with_same_mime_and_size_is_refused(): void
    {
        $this->assertSame(strlen(self::ORIGINAL), strlen(self::REPLACED));
        [$outcome, $transcript] = $this->uploadWithProtocolStub(self::REPLACED);
        $this->assertSame(hash('sha256', self::ORIGINAL), $transcript['sha256']);
        $this->assertTrue($transcript['complete']);
        $this->assertInstanceOf(InvalidArgumentException::class, $outcome,
            'Different bytes with the same PDF MIME and size must not be stored as a clean scan.');
        $this->assertSame(0, KycDocument::query()->count());
        $this->assertSame([], Storage::disk('kyc-test')->allFiles());
    }

    /** @return array{KycDocument|\Throwable, array<string, mixed>} */
    private function uploadWithProtocolStub(?string $replacement): array
    {
        Storage::fake('kyc-test');
        $user = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent('id.pdf', self::ORIGINAL);
        $path = $upload->getRealPath();
        $this->assertIsString($path);
        $base = sys_get_temp_dir().'/kyc-scan-'.bin2hex(random_bytes(8));
        $socket = $base.'.sock';
        $transcript = $base.'.json';
        $command = [PHP_BINARY, base_path('tests/Support/ClamAvProtocolStub.php'),
            $socket, $transcript, base64_encode("stream: OK\0"), '1'];
        if ($replacement !== null) {
            $command[] = $path;
            $command[] = base64_encode($replacement);
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
                'antimalware.driver' => 'clamav',
                'antimalware.clamav.socket' => 'unix://'.$socket,
                'antimalware.timeout_seconds' => 3,
                'antimalware.max_file_size_bytes' => 4096,
                'account_verification.storage.disk' => 'kyc-test',
                'account_verification.storage.path_prefix' => 'kyc_documents',
            ]);
            try {
                $outcome = app(DocumentStorageService::class)->store($user, KycDocumentType::NationalId, $upload);
            } catch (\Throwable $error) {
                $outcome = $error;
            }
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertFileExists($transcript);
            $records = json_decode((string) file_get_contents($transcript), true, 32, JSON_THROW_ON_ERROR);
            $this->assertCount(1, $records);

            return [$outcome, $records[0]];
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
            @unlink($socket);
            @unlink($transcript);
        }
    }
}
