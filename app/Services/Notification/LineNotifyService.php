<?php

declare(strict_types=1);

namespace App\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LINE Messaging API client (replaces deprecated LINE Notify API).
 *
 * LINE Notify shut down on 31 March 2025. This service now uses the LINE
 * Messaging API push-message endpoint with a Channel Access Token.
 *
 * Configuration:
 *   services.line.messaging_channel_access_token  — LINE Channel Access Token
 *   services.line.messaging_user_id               — recipient user / group ID
 *
 * Both must be set in .env; otherwise the service silently no-ops (fail-closed
 * for alerts — no crash, just no delivery).
 */
class LineNotifyService
{
    private const MESSAGING_API = 'https://api.line.me/v2/bot/message/push';

    private string $channelAccessToken;
    private string $userId;

    public function __construct(
        ?string $channelAccessToken = null,
        ?string $userId = null,
    ) {
        $this->channelAccessToken = $channelAccessToken ?? (string) config('services.line.messaging_channel_access_token');
        $this->userId = $userId ?? (string) config('services.line.messaging_user_id');
    }

    /**
     * Send an urgent SecOps / Financial Alert to the designated LINE recipient
     * via the Messaging API push-message endpoint.
     */
    public function sendSecOpsAlert(string $message): bool
    {
        if ($this->channelAccessToken === '' || $this->userId === '') {
            Log::warning('LINE Messaging API not configured; skipping SecOps alert.');

            return false;
        }

        try {
            $formattedMessage = "🚨 [THAI LOTTERY ENTERPRISE SECOPS ALERT]\n".$message."\nTimestamp: ".now()->toIso8601String();

            $response = Http::withToken($this->channelAccessToken)
                ->timeout(10)
                ->post(self::MESSAGING_API, [
                    'to' => $this->userId,
                    'messages' => [
                        [
                            'type' => 'text',
                            'text' => $formattedMessage,
                        ],
                    ],
                ]);

            if ($response->successful()) {
                Log::info('SecOps alert successfully broadcast via LINE Messaging API.');

                return true;
            }

            Log::error('Failed to send LINE Messaging API notification: '.$response->body().' (status: '.$response->status().')');

            return false;
        } catch (\Throwable $e) {
            Log::error('Exception in LineNotifyService (Messaging API): '.$e->getMessage());

            return false;
        }
    }
}
