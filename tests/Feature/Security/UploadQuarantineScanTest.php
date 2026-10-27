<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Contracts\Security\MalwareScanner;
use App\DTOs\Security\MalwareScanResult;
use App\Enums\MalwareScanStatus;
use App\Services\Uploads\UploadQuarantineService;
use App\Services\Uploads\UploadScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class UploadQuarantineScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_quarantines_and_scans_clean_file(): void
    {
        Storage::fake('quarantine');
        Storage::fake('kyc_secure');

        $uploadedFile = UploadedFile::fake()->create('id_card.pdf', 500, 'application/pdf');

        $quarantineService = new UploadQuarantineService;
        $record = $quarantineService->quarantineUploadedFile($uploadedFile, 'user-789', 'KYC');

        $this->assertSame(MalwareScanStatus::PENDING, $record->scan_status);

        // Mock clean ClamAV scan
        $mockScanner = Mockery::mock(MalwareScanner::class);
        $mockScanner->shouldReceive('scanStream')
            ->once()
            ->andReturn(MalwareScanResult::clean(10, 'ClamAV 1.0.0'));

        $scanService = new UploadScanService($quarantineService, $mockScanner);
        $result = $scanService->scanRecord($record->id);

        $this->assertTrue($result->isClean);
        $this->assertDatabaseHas('upload_scan_records', [
            'id' => $record->id,
            'scan_status' => MalwareScanStatus::CLEAN->value,
        ]);
    }

    public function test_quarantines_and_detects_infected_file(): void
    {
        Storage::fake('quarantine');
        Storage::fake('kyc_secure');

        $uploadedFile = UploadedFile::fake()->create('malware.exe', 100, 'application/x-dosexec');

        $quarantineService = new UploadQuarantineService;
        $record = $quarantineService->quarantineUploadedFile($uploadedFile, 'user-attacker', 'KYC');

        // Mock infected scan result
        $mockScanner = Mockery::mock(MalwareScanner::class);
        $mockScanner->shouldReceive('scanStream')
            ->once()
            ->andReturn(MalwareScanResult::infected('Win.Trojan.Generic', 15, 'ClamAV 1.0.0'));

        $scanService = new UploadScanService($quarantineService, $mockScanner);
        $result = $scanService->scanRecord($record->id);

        $this->assertFalse($result->isClean);
        $this->assertSame(MalwareScanStatus::INFECTED, $result->status);
        $this->assertDatabaseHas('upload_scan_records', [
            'id' => $record->id,
            'scan_status' => MalwareScanStatus::INFECTED->value,
            'threat_name' => 'Win.Trojan.Generic',
        ]);
    }
}
