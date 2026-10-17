<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Security\FraudDecisionData;
use App\Enums\FraudDecision;
use App\Enums\MalwareScanStatus;
use App\Models\DepositTransaction;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Uploads\UploadQuarantineService;
use App\Services\Uploads\UploadScanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class BankSlipVerificationService
{
    public function __construct(
        private readonly UploadQuarantineService $quarantineService,
        private readonly UploadScanService $scanService,
        private readonly FraudDetectionService $fraudDetectionService
    ) {}

    /**
     * Process an uploaded payment slip with quarantine, virus scan, and fraud deduplication.
     *
     * @param UploadedFile $slipImage
     * @param string $userId
     * @param string $transRef Bank Transaction Reference / PromptPay TransRef
     * @param string $amount Claimed transfer amount in THB
     * @param string $clientIp
     * @return array{scan_status: string, fraud_decision: string, record_id: string}
     */
    public function verifyAndRecordSlip(
        UploadedFile $slipImage,
        string $userId,
        string $transRef,
        string $amount,
        string $clientIp
    ): array {
        // 1. Quarantine and schedule antimalware scan
        $scanRecord = $this->quarantineService->quarantineUploadedFile($slipImage, $userId, 'PAYMENT_SLIP');
        $scanResult = $this->scanService->scanRecord($scanRecord->id);

        if (!$scanResult->isClean) {
            throw new RuntimeException("Uploaded payment slip failed security scan: {$scanResult->threatName}");
        }

        // 2. Pre-flight Fraud Evaluation
        $fraudData = [
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'event_type' => 'DEPOSIT',
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => 'THB',
            'client_ip' => $clientIp,
            'reference_id' => $transRef,
        ];

        $fraudDecision = $this->fraudDetectionService->evaluateTransactionRisk($fraudData);

        if ($fraudDecision->decision === FraudDecision::DENY) {
            throw new RuntimeException("Deposit slip rejected due to high fraud risk: " . implode(', ', $fraudDecision->reasonCodes));
        }

        return [
            'scan_status' => $scanResult->status->value,
            'fraud_decision' => $fraudDecision->decision->value,
            'record_id' => $scanRecord->id,
            'trans_ref' => $transRef,
        ];
    }
}
