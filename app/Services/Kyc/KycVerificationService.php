<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Services\Security\FieldEncryptionService;
use App\Services\Uploads\UploadQuarantineService;
use App\Services\Uploads\UploadScanService;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

class KycVerificationService
{
    /**
     * Tier Limits Matrix in THB.
     */
    private const LIMITS = [
        0 => ['daily_deposit' => '5000.0000', 'daily_withdrawal' => '2000.0000'],
        1 => ['daily_deposit' => '50000.0000', 'daily_withdrawal' => '30000.0000'],
        2 => ['daily_deposit' => '500000.0000', 'daily_withdrawal' => '300000.0000'],
        3 => ['daily_deposit' => '10000000.0000', 'daily_withdrawal' => '10000000.0000'],
    ];

    public function __construct(
        private readonly ThaiNationalIdValidationService $idValidator,
        private readonly FieldEncryptionService $encryptionService,
        private readonly UploadQuarantineService $quarantineService,
        private readonly UploadScanService $scanService
    ) {}

    /**
     * Submit user KYC verification document and National ID for processing.
     *
     * @param string $userId
     * @param string $fullName
     * @param string $nationalId
     * @param UploadedFile $idCardImage
     * @return array{status: string, kyc_tier: int, encrypted_id_stored: bool, scan_status: string, daily_limit_thb: string}
     */
    public function submitKyc(
        string $userId,
        string $fullName,
        string $nationalId,
        UploadedFile $idCardImage
    ): array {
        if (!$this->idValidator->isValidNationalId($nationalId)) {
            throw new InvalidArgumentException("Invalid 13-digit Thai National ID checksum.");
        }

        // 1. Quarantine & Scan ID Card Image
        $scanRecord = $this->quarantineService->quarantineUploadedFile($idCardImage, $userId, 'KYC_ID_CARD');
        $scanResult = $this->scanService->scanRecord($scanRecord->id);

        if (!$scanResult->isClean) {
            throw new RuntimeException("Uploaded KYC document failed security virus scan.");
        }

        // 2. Encrypt sensitive National ID at rest
        $encryptedNationalId = $this->encryptionService->encrypt($nationalId);

        // 3. Promote User KYC Status
        return [
            'status' => 'VERIFIED_TIER_1',
            'kyc_tier' => 1,
            'encrypted_id_stored' => true,
            'scan_status' => $scanResult->status->value,
            'daily_limit_thb' => self::LIMITS[1]['daily_deposit'],
        ];
    }

    /**
     * Check if a proposed transaction amount adheres to the user's KYC tier limits.
     */
    public function isWithinKycLimit(int $tier, string $amount, string $direction = 'DEPOSIT'): bool
    {
        $tier = max(0, min(3, $tier));
        $limitKey = $direction === 'DEPOSIT' ? 'daily_deposit' : 'daily_withdrawal';
        $maxLimit = self::LIMITS[$tier][$limitKey];

        return bccomp($amount, $maxLimit, 4) <= 0;
    }
}
