<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Privacy\LookupTicketStatusRequest;
use App\Services\Support\ContactDeliveryService;
use App\Services\Support\ContactMessageService;
use App\Services\Support\PublicSupportService;
use App\Services\Support\TicketStatusLookupService;
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
 *
 * THE STATUS LOOKUP IS A FLAG-GATED LANE, OFF BY DEFAULT. While
 * SUPPORT_TICKET_STATUS_ENABLED is false this method answers the exact 501 body
 * it has always answered. When it is on, a correct reference-and-address pair
 * returns only the public fields (reference, status label key, last update,
 * whether a provider accepted the confirmation mail); a wrong address and an
 * unknown reference return THE SAME neutral body, produced by the same code path
 * with the same work done, so neither the response nor its timing distinguishes
 * them.
 */
final class PublicContactController
{
    public function __construct(
        private readonly PublicSupportService $support,
        private readonly ContactDeliveryService $delivery,
        private readonly ContactMessageService $messages,
        private readonly TicketStatusLookupService $ticketStatus,
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

    /**
     * Public ticket-status lookup.
     *
     * The neutral body is assembled in ONE place (TicketStatusLookupService::
     * neutral()) and the matched body has exactly the same keys, so a client
     * cannot branch on the shape of the answer either.
     */
    public function checkTicketStatusApi(Request $request): JsonResponse
    {
        if (! (bool) config('support_tickets.status_lookup_enabled', false)) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'Public ticket-status lookup is not configured. Use the reference shown by the canonical contact submission workflow.',
            ], 501);
        }

        // JSON in, JSON out - including a validation refusal.
        $request->headers->set('Accept', 'application/json');

        $form = LookupTicketStatusRequest::createFromBase($request);
        $form->setContainer(app());
        $form->setRedirector(app('redirect'));
        $form->validateResolved();

        $reference = $form->reference();

        if ($this->ticketStatus->tooManyAttempts($request->ip(), $reference)) {
            return response()->json([
                'success' => false,
                'state' => 'RATE_LIMITED',
                'message' => (string) trans('support_tickets.lookup.rate_limited'),
            ], 429);
        }

        $result = $this->ticketStatus->lookup($reference, $form->email(), $request->ip());

        $label = $result['status_key'] === null
            ? null
            : (string) trans($result['status_key']);

        return response()->json([
            'success' => true,
            'state' => $result['state'],
            'data' => [
                'reference' => $result['reference'],
                'status' => $result['status_key'],
                'status_label' => $label,
                'last_updated_at' => $result['last_updated_at'],
                'delivery_acknowledged' => $result['delivery_acknowledged'],
            ],
            'message' => (string) trans('support_tickets.lookup.neutral'),
        ]);
    }
}
