<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Mail\SupportReplyMail;
use App\Models\ContactMessage;
use App\Models\ContactMessageDelivery;
use App\Models\ContactMessageEvent;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The staff side of a stored support message: states, notes and replies.
 *
 * =========================================================================
 * ONE TABLE OF LEGAL MOVES
 * =========================================================================
 * config('support_tickets.transitions') is the ONLY authority on what may
 * follow what. changeStatus() locks the row, re-reads the status inside the
 * transaction, refuses anything absent from that table, writes the message, then
 * appends an event. Two operators clicking at the same moment therefore cannot
 * produce a transition neither of them was allowed to make.
 *
 * =========================================================================
 * A REPLY IS MAIL, AND MAIL IS THE PART THAT CAN LIE
 * =========================================================================
 * This service refuses to queue SupportReplyMail unless a reply mailbox is
 * configured. Queueing it and recording "replied" while nothing can be sent
 * would be the exact dishonesty the rest of this project's support lane is
 * written to avoid. The delivery row it writes records PENDING (queued), not
 * SENT (accepted by a provider), because only the transport can say the latter.
 *
 * =========================================================================
 * WHAT IS NEVER WRITTEN OR REVEALED
 * =========================================================================
 * No internal note leaves the platform, no reply carries another ticket's
 * content, and nothing here touches contact_messages' sender_fingerprint or
 * content_fingerprint. Notes and reply bodies are stored encrypted.
 */
final class SupportTicketService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return list<string>
     */
    public function allowedTransitions(string $from): array
    {
        $map = (array) config('support_tickets.transitions', []);

        $targets = $map[$from] ?? [];

        return is_array($targets)
            ? array_values(array_map('strval', $targets))
            : [];
    }

    public function canTransition(string $from, string $to): bool
    {
        return $from !== $to && in_array($to, $this->allowedTransitions($from), true);
    }

    /**
     * Move a ticket, or refuse and write nothing.
     */
    public function changeStatus(
        ContactMessage $message,
        string $targetStatus,
        User $actor,
        ?string $note = null,
    ): ContactMessage {
        $targetStatus = trim($targetStatus);
        $note = $this->cleanBody($note);

        $updated = DB::transaction(function () use ($message, $targetStatus, $actor, $note): ContactMessage {
            /** @var ContactMessage $locked */
            $locked = ContactMessage::query()->lockForUpdate()->findOrFail($message->getKey());

            $from = (string) $locked->status;

            if (! $this->canTransition($from, $targetStatus)) {
                throw new InvalidArgumentException('support_ticket_invalid_transition');
            }

            $locked->status = $targetStatus;

            if ($targetStatus === ContactMessage::STATUS_RESOLVED) {
                $locked->resolved_at = Carbon::now();
            }

            if ($targetStatus === ContactMessage::STATUS_IN_REVIEW && $locked->resolved_at !== null) {
                // Reopening clears the resolution stamp: leaving it set would
                // tell a later sweep that a live ticket was finished with.
                $locked->resolved_at = null;
            }

            $locked->save();

            ContactMessageEvent::query()->create([
                'contact_message_id' => (int) $locked->getKey(),
                'actor_id' => (int) $actor->getKey(),
                'type' => ContactMessageEvent::TYPE_STATUS_CHANGE,
                'from_status' => $from,
                'to_status' => $targetStatus,
                'body_encrypted' => $note,
                'created_at' => Carbon::now(),
            ]);

            return $locked;
        }, 3);

        $this->audit->log(
            userId: (int) $actor->getKey(),
            action: AuditAction::Update,
            riskLevel: RiskLevel::Low,
            auditable: $updated,
            description: 'A support ticket changed status.',
            metadata: [
                'reference' => $updated->public_reference,
                'status' => $updated->status,
                'has_note' => $note !== null,
            ],
        );

        return $updated;
    }

    /**
     * An internal note. It changes nothing and is sent nowhere.
     */
    public function addNote(ContactMessage $message, User $actor, string $body): ContactMessageEvent
    {
        $body = $this->cleanBody($body);

        if ($body === null) {
            throw new InvalidArgumentException('support_ticket_note_required');
        }

        $event = ContactMessageEvent::query()->create([
            'contact_message_id' => (int) $message->getKey(),
            'actor_id' => (int) $actor->getKey(),
            'type' => ContactMessageEvent::TYPE_NOTE,
            'from_status' => (string) $message->status,
            'to_status' => (string) $message->status,
            'body_encrypted' => $body,
            'created_at' => Carbon::now(),
        ]);

        $this->audit->log(
            userId: (int) $actor->getKey(),
            action: AuditAction::Update,
            riskLevel: RiskLevel::Low,
            auditable: $message,
            description: 'An internal note was added to a support ticket.',
            metadata: ['reference' => $message->public_reference],
        );

        return $event;
    }

    /**
     * Answer the visitor by email.
     *
     * REFUSES WHEN NO MAILBOX IS CONFIGURED - see the class comment. The refusal
     * is an exception with a stable code so the panel can show a safe sentence
     * and the audit trail holds no half-truth.
     */
    public function reply(ContactMessage $message, User $actor, string $body): ContactMessageEvent
    {
        $body = $this->cleanBody($body);

        if ($body === null) {
            throw new InvalidArgumentException('support_ticket_reply_body_required');
        }

        if (! SupportReplyMail::replyIsConfigured()) {
            throw new RuntimeException('support_ticket_reply_not_configured');
        }

        $attempt = DB::transaction(function () use ($message, $actor, $body): ContactMessageDelivery {
            $delivery = ContactMessageDelivery::query()->create([
                'contact_message_id' => (int) $message->getKey(),
                'provider' => 'reply',
                // PENDING means "queued", never "delivered". Only the transport
                // can say SENT, and nothing here pretends otherwise.
                'state' => ContactMessage::DELIVERY_PENDING,
                'attempted_at' => Carbon::now(),
                'error_code' => null,
                'error_class' => null,
            ]);

            ContactMessageEvent::query()->create([
                'contact_message_id' => (int) $message->getKey(),
                'actor_id' => (int) $actor->getKey(),
                'type' => ContactMessageEvent::TYPE_REPLY,
                'from_status' => (string) $message->status,
                'to_status' => (string) $message->status,
                'body_encrypted' => $body,
                'created_at' => Carbon::now(),
            ]);

            return $delivery;
        }, 3);

        try {
            Mail::to((string) $message->email)->queue(new SupportReplyMail($message, $body));
        } catch (Throwable $exception) {
            $attempt->state = ContactMessage::DELIVERY_FAILED;
            $attempt->error_code = 'TRANSPORT_FAILURE';
            $attempt->error_class = $exception::class;
            $attempt->save();

            Log::warning('support_reply_queue_failed', [
                'reference' => $message->public_reference,
                'exception' => $exception::class,
            ]);

            throw new RuntimeException('support_ticket_reply_not_queued', 0, $exception);
        }

        $this->audit->log(
            userId: (int) $actor->getKey(),
            action: AuditAction::Update,
            riskLevel: RiskLevel::Low,
            auditable: $message,
            description: 'A support reply was queued for delivery.',
            metadata: [
                'reference' => $message->public_reference,
                'delivery_state' => ContactMessage::DELIVERY_PENDING,
            ],
        );

        return ContactMessageEvent::query()
            ->where('contact_message_id', (int) $message->getKey())
            ->where('type', ContactMessageEvent::TYPE_REPLY)
            ->latest('id')
            ->firstOrFail();
    }

    public function markSpam(ContactMessage $message, User $actor, ?string $note = null): ContactMessage
    {
        return $this->changeStatus($message, ContactMessage::STATUS_SPAM, $actor, $note);
    }

    public function archive(ContactMessage $message, User $actor, ?string $note = null): ContactMessage
    {
        return $this->changeStatus($message, ContactMessage::STATUS_ARCHIVED, $actor, $note);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, ContactMessageEvent>
     */
    public function timeline(ContactMessage $message): \Illuminate\Database\Eloquent\Collection
    {
        return ContactMessageEvent::query()
            ->where('contact_message_id', (int) $message->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Strip control characters that have no meaning in prose, keep newlines.
     *
     * The same rule the public intake applies to a visitor's message, applied to
     * an operator's: a note is prose, not markup and not a header.
     */
    private function cleanBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? '';
        $body = trim($body);

        return $body === '' ? null : $body;
    }
}
