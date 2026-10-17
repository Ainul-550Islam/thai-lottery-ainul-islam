<?php

declare(strict_types=1);

namespace App\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LineNotifyService
{
    private const LINE_NOTIFY_API = 'https://notify-api.line.me/api/notify';

    private string $accessToken;

    public function __construct(?string $token = null)
    {
        $this->accessToken = $token ?? (string) config('services.line.notify_token', env('LINE_NOTIFY_TOKEN', 'test-line-token'));
    }

    /**
     * Send an urgent SecOps / Financial Alert to the designated Thailand LINE notification channel.
     */
    public function sendSecOpsAlert(string $message): bool
    {
        if (empty($this->accessToken)) {
            Log::warning("LINE Notify token not configured; skipping dispatch.");
            return false;
        }

        try {
            $formattedMessage = "\n🚨 [THAI LOTTERY ENTERPRISE SECOPS ALERT]\n" . $message . "\nTimestamp: " . now()->toIso8601String();

            $response = Http::withToken($this->accessToken)
                ->asForm()
                ->post(self::LINE_NOTIFY_API, [
                    'message' => $formattedMessage,
                ]);

            if ($response->successful()) {
                Log::info("SecOps alert successfully broadcast to LINE channel.");
                return true;
            }

            Log::error("Failed to send LINE notification: " . $response->body());
            return false;
        } catch (\Throwable $e) {
            Log::error("Exception in LineNotifyService: " . $e->getMessage());
            return false;
        }
    }
}
