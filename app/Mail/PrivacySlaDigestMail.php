<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\QueueName;
use App\Support\Privacy\PrivacyMailCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The daily digest sent to the compliance desk.
 *
 * INTERNAL, AND STILL SPARING. It names references, request types and due
 * dates - never a requester's address and never what they wrote - because an
 * operations mailbox is a shared surface. The detail lives in the panel, behind
 * a permission.
 *
 * A Mailable rather than Mail::raw so the digest is a testable, inspectable
 * object with a subject line that cannot drift from its body. Its own rendered
 * text is plain.
 */
final class PrivacySlaDigestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /**
     * @param  list<string>  $lines  already-localized one-line summaries
     */
    public function __construct(
        public readonly int $overdueCount,
        public readonly int $dueSoonCount,
        public readonly array $lines,
    ) {
        $this->afterCommit();
        $this->onQueue(QueueName::Notifications->value);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) trans('privacy_requests.sla.subject', [
                'overdue' => $this->overdueCount,
                'due_soon' => $this->dueSoonCount,
            ], 'en'),
            from: PrivacyMailCopy::fromAddress(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.privacy.sla-digest',
            with: [
                'lines' => $this->lines,
                'footer' => (string) trans('privacy_requests.sla.footer', [], 'en'),
            ],
        );
    }
}
