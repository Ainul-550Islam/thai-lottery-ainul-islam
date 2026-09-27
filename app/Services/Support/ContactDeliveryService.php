<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ContactMessage;
use App\Models\ContactMessageDelivery;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Hands a stored contact message to an outbound provider, and says honestly
 * what happened (PROMPT 10).
 *
 * THE ONE RULE. This class returns SENT only when a configured provider
 * accepted the message. Not when the message was stored, not when mail is
 * "probably working", not when an exception was swallowed. Everything else is
 * NOT_CONFIGURED or FAILED, and both mean the visitor is told their message
 * was received and a reply may take longer - which is true.
 *
 * FROM IS OURS, REPLY-TO IS THEIRS. Sending with the visitor's address in From
 * would make the platform emit mail claiming to be them: it fails SPF and
 * DMARC, it damages the domain's deliverability, and it turns the form into a
 * spoofing relay. Reply-To gives support a one-click reply with none of that.
 *
 * NOTHING FROM THE PROVIDER REACHES THE VISITOR OR THE DATABASE. A transport
 * exception routinely carries a hostname, a port, a username and sometimes a
 * credential. What is recorded is the exception's CLASS and a short code of
 * our own. The full exception goes to the application log, which is already a
 * privileged surface, and nowhere else.
 */
class ContactDeliveryService
{
    public const PROVIDER = 'mail';

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Is there anything to deliver with?
     *
     * Three things must all be true: the feature is on, a From address exists,
     * and a destination exists. A missing destination is the common case in a
     * fresh clone, and it must read as NOT_CONFIGURED rather than as a failure
     * - nothing is broken, nothing was set up.
     */
    public function isConfigured(): bool
    {
        return (bool) $this->config->get('contact.delivery.enabled', false)
            && $this->fromAddress() !== null
            && $this->destination() !== null;
    }

    /**
     * The honest provider state, for the health command and the page.
     *
     * @return array{state: string, provider: string, destination_configured: bool, from_configured: bool}
     */
    public function status(): array
    {
        return [
            'state' => $this->isConfigured()
                ? ContactMessage::DELIVERY_NOT_ATTEMPTED
                : ContactMessage::DELIVERY_NOT_CONFIGURED,
            'provider' => self::PROVIDER,
            'destination_configured' => $this->destination() !== null,
            'from_configured' => $this->fromAddress() !== null,
        ];
    }

    /**
     * Attempt delivery for an already-persisted message.
     *
     * The message exists before this runs and continues to exist whatever
     * happens here. That ordering is the whole design: an SMTP outage must not
     * be able to destroy a support request.
     */
    public function deliver(ContactMessage $message): string
    {
        if (! $this->isConfigured()) {
            return $this->record($message, ContactMessage::DELIVERY_NOT_CONFIGURED, 'PROVIDER_NOT_CONFIGURED', null);
        }

        $destination = (string) $this->destination();
        $fromAddress = (string) $this->fromAddress();
        $fromName = $this->config->get('contact.delivery.from_name');
        $fromName = is_string($fromName) && $fromName !== '' ? $fromName : config('app.name');

        $prefix = (string) $this->config->get('contact.delivery.subject_prefix', '[Contact]');

        try {
            // The body is plain text on purpose. A contact message is prose
            // typed by a stranger; rendering it as HTML anywhere - including
            // in a support inbox - turns it into markup somebody's client will
            // interpret.
            Mail::raw($this->plainTextBody($message), function (Message $mail) use (
                $destination, $fromAddress, $fromName, $prefix, $message
            ): void {
                $mail->to($destination)
                    ->from($fromAddress, (string) $fromName)
                    // The visitor's validated address, so support can reply -
                    // and only here, never in From.
                    ->replyTo($message->email, $message->name)
                    ->subject(trim($prefix.' '.$message->subject));
            });

            return $this->record($message, ContactMessage::DELIVERY_SENT, null, null);
        } catch (Throwable $exception) {
            // The full exception goes to the log, which is already privileged.
            // Only its class survives into the database.
            Log::warning('contact_delivery_failed', [
                'public_reference' => $message->public_reference,
                'exception' => $exception::class,
            ]);

            return $this->record(
                $message,
                ContactMessage::DELIVERY_FAILED,
                'TRANSPORT_FAILURE',
                $exception::class,
            );
        }
    }

    /**
     * Write the attempt and move the message's current state in one place.
     */
    private function record(ContactMessage $message, string $state, ?string $errorCode, ?string $errorClass): string
    {
        ContactMessageDelivery::query()->create([
            'contact_message_id' => $message->getKey(),
            'provider' => self::PROVIDER,
            'state' => $state,
            'attempted_at' => now(),
            'error_code' => $errorCode,
            'error_class' => $errorClass,
        ]);

        $message->delivery_state = $state;
        $message->save();

        return $state;
    }

    /**
     * The message as plain text, with the visitor's content clearly fenced.
     *
     * The fence matters: without it, a message that itself contains something
     * looking like a header line is ambiguous to whoever reads the mail.
     */
    private function plainTextBody(ContactMessage $message): string
    {
        return implode("\n", [
            'Reference: '.$message->public_reference,
            'From: '.$message->name,
            'Email: '.$message->email,
            'Subject: '.$message->subject,
            '',
            '--- message ---',
            $message->message,
            '--- end ---',
        ]);
    }

    private function fromAddress(): ?string
    {
        $value = $this->config->get('contact.delivery.from_address');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Where a forwarded message goes: the configured public support address.
     *
     * There is no separate "admin email" setting. A second, private
     * destination would be an operator identity living in the same config as a
     * public one, and the two get confused and rendered.
     */
    private function destination(): ?string
    {
        $value = $this->config->get('contact.support.email');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
