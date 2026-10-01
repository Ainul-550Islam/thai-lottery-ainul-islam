<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Support\ContactDeliveryService;
use App\Services\Support\ContactMessageService;
use App\Services\Support\PublicSupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Compatibility JSON controller for the public Contact API.
 *
 * The old compatibility surface advertised channels, departments and ticket
 * tracking that were not backed by a canonical provider. It now reports the
 * configured support projection and delegates submissions to the same stored
 * contact-message workflow as the browser form. There is no invented address,
 * ticket assignment, response time, staff identity, or delivery claim.
 */
final class PublicContactController
{
    public function __construct(
        private readonly PublicSupportService $support,
        private readonly ContactDeliveryService $delivery,
        private readonly ContactMessageService $messages,
    ) {
    }

    public function getChannelsApi(Request $request): JsonResponse
    {
        unset($request);

        $support = $this->support->contact();
        $delivery = $this->delivery->status();

        return response()->json([
            'success' => true,
            'channels' => [
                'support' => $support,
            ],
            'departments' => [],
            'delivery' => $delivery,
            'notice' => $support['status'] === 'CONFIGURED'
                ? 'Only configured support details are published.'
                : 'Support channels are NOT_CONFIGURED.',
        ]);
    }

    public function submitTicketApi(Request $request): JsonResponse
    {
        $honeypot = (string) config('contact.anti_spam.honeypot_field', 'website');
        $legacyEmail = $request->input('email_or_phone');
        $payload = [
            'name' => $request->input('name', $request->input('full_name')),
            'email' => $request->input('email', is_string($legacyEmail) && filter_var($legacyEmail, FILTER_VALIDATE_EMAIL) ? $legacyEmail : null),
            'subject' => $request->input('subject'),
            'message' => $request->input('message'),
            $honeypot => $request->input($honeypot),
        ];
        $limits = (array) config('contact.limits', []);
        $validator = Validator::make($payload, [
            'name' => ['required', 'string', 'min:2', 'max:'.(int) ($limits['name'] ?? 120)],
            'email' => ['required', 'string', 'email:rfc', 'max:'.(int) ($limits['email'] ?? 190)],
            'subject' => ['required', 'string', 'min:3', 'max:'.(int) ($limits['subject'] ?? 160)],
            'message' => ['required', 'string', 'min:10', 'max:'.(int) ($limits['message'] ?? 4000)],
            $honeypot => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'state' => 'INVALID_REQUEST',
                'message' => 'Use the canonical name, email, subject and message fields.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->messages->submit([
                'name' => (string) $payload['name'],
                'email' => (string) $payload['email'],
                'subject' => (string) $payload['subject'],
                'message' => (string) $payload['message'],
                $honeypot => $payload[$honeypot],
            ], $request);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'state' => 'UNAVAILABLE',
                'message' => 'The contact capability is temporarily unavailable.',
            ], 503);
        }

        $state = match ($result['outcome']) {
            ContactMessageService::OUTCOME_RECEIVED => match ($result['delivery_state']) {
                'SENT' => 'SENT',
                'NOT_CONFIGURED' => 'RECEIVED_NOT_CONFIGURED',
                'FAILED' => 'RECEIVED_DELIVERY_FAILED',
                default => 'RECEIVED',
            },
            ContactMessageService::OUTCOME_DUPLICATE => 'DUPLICATE',
            ContactMessageService::OUTCOME_SPAM => 'RECEIVED',
            ContactMessageService::OUTCOME_RATE_LIMITED => 'RATE_LIMITED',
            ContactMessageService::OUTCOME_DISABLED => 'NOT_CONFIGURED',
            default => 'UNAVAILABLE',
        };

        return response()->json([
            'success' => $state !== 'UNAVAILABLE',
            'state' => $state,
            'reference' => $result['reference'],
            'message' => $state === 'SENT'
                ? 'Your message was accepted by the configured support provider.'
                : 'Your message state is reported without claiming delivery.',
        ], in_array($state, ['RATE_LIMITED'], true) ? 429 : ($state === 'UNAVAILABLE' ? 503 : 200));
    }

    public function checkTicketStatusApi(Request $request): JsonResponse
    {
        unset($request);

        return response()->json([
            'success' => false,
            'state' => 'NOT_CONFIGURED',
            'message' => 'Public ticket-status lookup is not configured. Use the reference shown by the canonical contact submission workflow.',
        ], 501);
    }
}
