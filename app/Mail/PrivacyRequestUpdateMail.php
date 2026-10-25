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
 * A state change, or a request for more information.
 *
 * WHAT IT MAY CONTAIN. The reference, the new state, the due date and - when a
 * reviewer chooses to include one - their note. It contains no copy of the
 * requester's data and no internal classification: this mail travels outside
 * the platform, so the only personal data in it is the address it is sent to.
 *
 * WHAT IT MUST NOT DO. It cannot be sent to an unverified address, and it cannot
 * announce a refusal without its reason being recorded on the request first -
 * the service writes the reason code and the timeline entry before queueing
 * this mail, so the mail and the record cannot disagree.
 */
final class PrivacyRequestUpdateMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly DataSubjectRequest $request,
        public readonly ?string $reviewerNote = null,
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
            subject: PrivacyMailCopy::for('mail.update.subject')
                ->locate($this->request->locale)
                ->reference($this->request->reference)
                ->render(),
            from: PrivacyMailCopy::fromAddress(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.privacy.request-update',
            with: [
                'reference' => $this->request->reference,
                'statusLabel' => (string) trans($this->request->status->labelKey(), [], $this->request->locale),
                'dueAt' => $this->request->due_at?->format('Y-m-d') ?? '',
                'reviewerNote' => $this->reviewerNote,
                'portalUrl' => route('account.privacy-requests.index'),
                'locale' => $this->request->locale,
            ],
        );
    }
}
