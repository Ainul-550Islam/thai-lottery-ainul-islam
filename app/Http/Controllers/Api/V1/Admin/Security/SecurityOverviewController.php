<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Security;

use App\Contracts\Security\MalwareScanner;
use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Models\FraudAlert;
use App\Models\UploadScanRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class SecurityOverviewController extends Controller
{
    public function __construct(
        private readonly MalwareScanner $malwareScanner
    ) {}

    public function overview(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('access-admin');

        $activeBlocksCount = BlockedIp::where('is_active', true)->count();
        $openFraudAlertsCount = FraudAlert::where('status', 'OPEN')->count();
        $pendingUploadScansCount = UploadScanRecord::where('scan_status', 'pending_scan')->count();
        $infectedUploadsCount = UploadScanRecord::where('scan_status', 'infected')->count();

        $scannerActive = $this->malwareScanner->ping();
        $scannerInfo = $this->malwareScanner->getVersionInfo();

        return response()->json([
            'success' => true,
            'data' => [
                'active_ip_blocks' => $activeBlocksCount,
                'open_fraud_alerts' => $openFraudAlertsCount,
                'pending_upload_scans' => $pendingUploadScansCount,
                'infected_uploads_detected' => $infectedUploadsCount,
                'antimalware_engine' => [
                    'active' => $scannerActive,
                    'info' => $scannerInfo,
                ],
                'bot_detection_mode' => config('bot.mode', 'log_only'),
                'fraud_detection_mode' => config('fraud.mode', 'enforce'),
                'evaluated_at' => now()->toIso8601String(),
            ],
        ], Response::HTTP_OK);
    }
}
