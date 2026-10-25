<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\QueueName;
use App\Models\DataSubjectRequest;
use App\Support\Privacy\PrivacyMailCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * "Somebody used this address to submit a privacy request — is it you?"
 *
 * THE LINK IS THE WHOLE MESSAGE. It is a signed, expiring URL carrying a
 * single-use token; only the token's SHA-256 is stored, so a copy of the mail
 * or of the database alone cannot verify a request. The mail never states
 * whether an account exists for the address, because it is sent to an address
 * that may belong to somebody with no account at all.
 *
 * NO ACCOUNT DETAILS, NO NAME, NO IDENTIFIER BEYOND THE REFERENCE. If this mail
 * is forwarded, or lands in the wrong inbox, it reveals that a privacy request
 * exists for that address and nothing else.
 *
 * QUEUED, LOCALIZED AND ATTRIBUTED. It is queued on the notifications lane,
 * rendered in the requester's own language, and sent from the configured mail
 * identity: a privacy notice arriving in a language the recipient did not use
 * is a notice they cannot act on.
 */
final class PrivacyRequestVerificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * The plain token exists in this object and in the mail body, and nowhere
     * else. It is never logged, never audited and never written to the
     * database.
     */
    public function __construct(
        public readonly DataSubjectRequest $request,
        public readonly string $plainToken,
    ) {
        $this->afterCommit();
        $this->onQueue(QueueName::Notifications->value);
        $this->locale($request->locale);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine(),
            from: PrivacyMailCopy::fromAddress(),
        );
    }

    public function content(): Content
    {
        $expiresAt = $this->expiresAt();

        return new Content(
            view: 'emails.privacy.request-verify',
            with: [
                'reference' => $this->request->reference,
                'typeLabel' => (string) trans($this->request->type->labelKey(), [], $this->request->locale),
                'verifyUrl' => URL::temporarySignedRoute(
                    'privacy.request.verify',
                    $expiresAt,
                    ['token' => $this->plainToken],
                ),
                'expiresAt' => $expiresAt->format('Y-m-d H:i'),
                'appName' => (string) config('app.name', ''),
                'locale' => $this->request->locale,
            ],
        );
    }

    /**
     * The exact expiry the URL is signed for.
     *
     * Computed from the row's own timestamp rather than "now" so a queued mail
     * that waited produces the same window the service told the requester
     * about, and a URL that is not already stale by the time it is read.
     */
    private function expiresAt(): Carbon
    {
        $issuedAt = $this->request->created_at instanceof Carbon ? $this->request->created_at : Carbon::now();

        return $issuedAt->copy()->addMinutes(
            max(5, (int) config('privacy_requests.verification_ttl_minutes', 1440)),
        );
    }

    private function subjectLine(): string
    {
        return PrivacyMailCopy::for('mail.verify.subject')
            ->locate($this->request->locale)
            ->reference($this->request->reference)
            ->render();
    }
}
