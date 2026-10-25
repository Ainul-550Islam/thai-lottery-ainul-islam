<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\QueueName;
use App\Models\ContactMessage;
use App\Support\Privacy\PrivacyMailCopy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An operator's answer to a stored support message.
 *
 * FROM IS THE PLATFORM, TO IS THE VISITOR. Sending with the visitor's address in
 * From would make the platform emit mail claiming to be them - failing SPF and
 * DMARC and turning support into a spoofing relay. So the reply comes from the
 * configured support mailbox and quotes the ticket reference for threading.
 *
 * THE VISITOR'S OWN MESSAGE IS QUOTED BACK, AND NOTHING ELSE IS. No internal
 * note, no other ticket, no operator name, no classification. A support reply is
 * a public document once it is sent; treat it as one.
 *
 * WHEN NO REPLY MAILBOX IS CONFIGURED the mailable reports that state to the
 * caller instead of sending from a fabricated address - see replyIsConfigured().
 */
final class SupportReplyMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly ContactMessage $message,
        public readonly string $body,
    ) {
        $this->afterCommit();
        $this->onQueue(QueueName::Notifications->value);

        if (is_string($message->locale) && in_array($message->locale, ['en', 'th', 'bn'], true)) {
            $this->locale($message->locale);
        }
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * Is there a mailbox an answer may legitimately be sent from?
     *
     * Both the address and the feature's own configuration must exist. A reply
     * from an address the transport is not authorised for is not a reply, it is
     * a bounce with the platform's name on it.
     */
    public static function replyIsConfigured(): bool
    {
        $address = config('support_tickets.reply_from_address');

        return is_string($address) && trim($address) !== '';
    }

    public function envelope(): Envelope
    {
        $subject = (string) trans('support_tickets.mail.reply.subject', [
            'subject' => (string) $this->message->subject,
            'reference' => (string) $this->message->public_reference,
        ], $this->locale ?? 'en');

        return new Envelope(
            subject: $subject,
            from: $this->fromAddress(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.privacy.support-reply',
            with: [
                'reference' => (string) $this->message->public_reference,
                'subject' => (string) $this->message->subject,
                'body' => $this->body,
                'contactUrl' => route('contact'),
                'locale' => $this->locale ?? 'en',
            ],
        );
    }

    /**
     * The From identity: the operator's reply mailbox, or the framework default.
     *
     * A null answer is possible only in a deployment that configured nothing at
     * all, and SupportTicketService refuses to queue this mail in that state -
     * so nothing is sent from an address the transport cannot authenticate.
     */
    private function fromAddress(): ?Address
    {
        $address = config('support_tickets.reply_from_address');
        $name = config('support_tickets.reply_from_name');

        if (is_string($address) && trim($address) !== '') {
            return new Address(trim($address), is_string($name) && $name !== '' ? $name : null);
        }

        return PrivacyMailCopy::fromAddress();
    }
}
