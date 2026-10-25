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

/**
 * Acknowledgement of a VERIFIED privacy request.
 *
 * THE DUE DATE IS THE POINT. A requester is entitled to know when an answer is
 * owed, so the date comes from the row's own due_at - the same value the staff
 * panel sorts and the SLA sweep measures - never from a recomputation that
 * could disagree after an operator changes the configured window.
 *
 * SENT ONLY AFTER VERIFICATION. Sending an acknowledgement to an unverified
 * address would tell whoever opened the mail that a request exists in their
 * name, which is exactly the disclosure a verification step prevents.
 */
final class PrivacyRequestReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly DataSubjectRequest $request)
    {
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
            subject: PrivacyMailCopy::for('mail.received.subject')
                ->locate($this->request->locale)
                ->reference($this->request->reference)
                ->render(),
            from: PrivacyMailCopy::fromAddress(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.privacy.request-received',
            with: [
                'reference' => $this->request->reference,
                'typeLabel' => (string) trans($this->request->type->labelKey(), [], $this->request->locale),
                'receivedAt' => $this->request->received_at?->format('Y-m-d') ?? '',
                'dueAt' => $this->request->due_at?->format('Y-m-d') ?? '',
                'locale' => $this->request->locale,
            ],
        );
    }
}
