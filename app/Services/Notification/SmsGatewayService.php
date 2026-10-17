<?php

declare(strict_types=1);

namespace App\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsGatewayService
{
    private string $apiKey;
    private string $apiSecret;
    private string $senderName;

    public function __construct(
        ?string $apiKey = null,
        ?string $apiSecret = null,
        ?string $senderName = null
    ) {
        $this->apiKey = $apiKey ?? (string) config('services.sms.api_key', env('THAI_SMS_API_KEY', 'test-sms-key'));
        $this->apiSecret = $apiSecret ?? (string) config('services.sms.api_secret', env('THAI_SMS_API_SECRET', 'test-sms-secret'));
        $this->senderName = $senderName ?? (string) config('services.sms.sender_name', env('THAI_SMS_SENDER', 'THAILOTTO'));
    }

    /**
     * Dispatch an urgent SMS message to a Thai mobile number (08XXXXXXXX / 09XXXXXXXX).
     */
    public function sendSms(string $mobileNumber, string $message): bool
    {
        $sanitizedNumber = preg_replace('/[^0-9]/', '', $mobileNumber) ?? '';

        if (strlen($sanitizedNumber) === 10 && str_starts_with($sanitizedNumber, '0')) {
            $formattedMsisdn = '66' . substr($sanitizedNumber, 1);
        } else {
            $formattedMsisdn = $sanitizedNumber;
        }

        Log::info("Sending SMS alert to [{$formattedMsisdn}] via Thai SMS Gateway.");

        // Simulated high-reliability gateway API dispatch
        return true;
    }
}
