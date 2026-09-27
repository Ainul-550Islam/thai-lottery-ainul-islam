<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ContactMessageRequest;
use App\Services\Support\ContactDeliveryService;
use App\Services\Support\ContactMessageService;
use App\Services\Support\PublicSupportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Public Contact / Support page (PROMPT 10).
 *
 * THIN. Validation is a FormRequest, abuse control and persistence are
 * services, delivery is a service. There is no mail call, no query and no
 * sanitising in this file, so none of those exists in two places.
 *
 * ANONYMOUS. Both routes are public. Requiring a login to contact support
 * would mean the people most likely to need help - those who cannot sign in -
 * are the ones who cannot ask.
 *
 * IT RENDERS STATES, NOT PROMISES. The service returns what actually happened
 * and this controller maps it to a translation key. There is no branch here
 * that can turn "stored but not delivered" into "email sent", because the only
 * thing it has to work with is the outcome the service returned.
 */
final class ContactController
{
    public function __construct(
        private readonly ContactMessageService $messages,
        private readonly ContactDeliveryService $delivery,
        private readonly PublicSupportService $support,
    ) {}

    /**
     * GET /contact
     */
    public function show(): View
    {
        return view('contact.index', $this->pageData());
    }

    /**
     * POST /contact
     *
     * Always redirects back. A redirect after a successful POST is what stops
     * the browser's reload button resubmitting the message, and it is the
     * reason the duplicate window is a backstop rather than the only defence.
     */
    public function submit(ContactMessageRequest $request): RedirectResponse
    {
        $result = $this->messages->submit($request->payload(), $request);

        $redirect = redirect()->route('contact')->withFragment('contact-form');

        // The honeypot case reports success on purpose: an automated submitter
        // must not be able to tell from the response that it was detected.
        // Nothing was delivered and the row is marked SPAM.
        return match ($result['outcome']) {
            ContactMessageService::OUTCOME_RECEIVED,
            ContactMessageService::OUTCOME_SPAM,
            ContactMessageService::OUTCOME_DUPLICATE => $redirect->with('contact_status', [
                'state' => $this->publicState($result),
                'reference' => $result['reference'],
            ]),

            ContactMessageService::OUTCOME_RATE_LIMITED => $redirect->with('contact_status', [
                'state' => 'rate_limited',
                'reference' => null,
            ]),

            ContactMessageService::OUTCOME_DISABLED => $redirect->with('contact_status', [
                'state' => 'disabled',
                'reference' => null,
            ]),

            default => $redirect->with('contact_status', [
                'state' => 'failed',
                'reference' => null,
            ]),
        };
    }

    /**
     * Translate a delivery state into the message the visitor sees.
     *
     * 'sent' is reachable from exactly one delivery state. Every other path
     * ends at a message that says the platform has the enquiry and a reply may
     * take longer - which is the truth in all of them.
     *
     * @param  array{outcome: string, reference: string|null, status: string|null, delivery_state: string|null}  $result
     */
    private function publicState(array $result): string
    {
        return match ($result['delivery_state']) {
            'SENT' => 'sent',
            'NOT_CONFIGURED' => 'received_not_configured',
            'FAILED' => 'received_delivery_failed',
            'PENDING' => 'received_pending',
            default => 'received',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(): array
    {
        $support = $this->support->contact();

        return [
            'support' => $support,
            // Whether a provider EXISTS, never its address, host or
            // credentials. The page uses it to set expectations about reply
            // time and nothing else.
            'delivery' => $this->delivery->status(),
            'limits' => (array) config('contact.limits', []),
            'honeypotField' => (string) config('contact.anti_spam.honeypot_field', 'website'),
            'enabled' => (bool) config('contact.enabled', true),
            'meta' => [
                'title' => (string) trans('contact.meta_title'),
                'description' => (string) trans('contact.meta_description'),
                'robots' => (string) config('contact.seo.robots', 'index,follow'),
                'canonical' => url('/'.ltrim((string) config('contact.seo.path', 'contact'), '/')),
            ],
        ];
    }
}
