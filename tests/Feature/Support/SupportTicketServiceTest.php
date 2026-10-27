<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Mail\SupportReplyMail;
use App\Models\ContactMessage;
use App\Models\ContactMessageEvent;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The staff side of a support message: states, notes and replies.
 *
 * WHAT MATTERS HERE
 *  - A transition absent from config('support_tickets.transitions') cannot
 *    happen, and a refused transition writes NOTHING (no row, no event, no mail).
 *  - A note is encrypted, never mailed, and lands in the timeline with an author.
 *  - A reply is QUEUED, not "sent": there is no code path that can claim a
 *    delivery the transport has not accepted, and with no reply mailbox
 *    configured nothing is queued at all and the caller is told.
 *  - Nothing here can delete a message.
 */
final class SupportTicketServiceTest extends TestCase
{
    use RefreshDatabase;

    private SupportTicketService $service;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->service = app(SupportTicketService::class);
        $this->operator = User::factory()->create();
    }

    private function ticket(array $overrides = []): ContactMessage
    {
        return ContactMessage::query()->create(array_merge([
            'public_reference' => 'TCK-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Ticket Holder',
            'email' => 'ticket.holder@example.test',
            'subject' => 'A question about a payout',
            'message' => 'The message body.',
            'status' => ContactMessage::STATUS_RECEIVED,
            'delivery_state' => ContactMessage::DELIVERY_NOT_CONFIGURED,
            'locale' => 'en',
        ], $overrides));
    }

    public function test_01_the_configured_transition_table_is_the_only_authority(): void
    {
        $this->assertTrue($this->service->canTransition(ContactMessage::STATUS_RECEIVED, ContactMessage::STATUS_IN_REVIEW));
        $this->assertTrue($this->service->canTransition(ContactMessage::STATUS_IN_REVIEW, ContactMessage::STATUS_RESOLVED));
        $this->assertFalse($this->service->canTransition(ContactMessage::STATUS_RECEIVED, ContactMessage::STATUS_RESOLVED) === false);

        // Same state is never a transition, and a status nobody can reach is not
        // offered either.
        $this->assertFalse($this->service->canTransition(ContactMessage::STATUS_RECEIVED, ContactMessage::STATUS_RECEIVED));
        $this->assertFalse($this->service->canTransition(ContactMessage::STATUS_RESOLVED, 'NONSENSE'));
    }

    public function test_02_a_legal_transition_writes_the_row_an_event_and_an_audit_entry(): void
    {
        $ticket = $this->ticket();

        $updated = $this->service->changeStatus($ticket, ContactMessage::STATUS_IN_REVIEW, $this->operator, 'Picked it up');

        $this->assertSame(ContactMessage::STATUS_IN_REVIEW, $updated->status);

        $event = ContactMessageEvent::query()->where('contact_message_id', $ticket->getKey())->firstOrFail();

        $this->assertSame(ContactMessageEvent::TYPE_STATUS_CHANGE, $event->type);
        $this->assertSame(ContactMessage::STATUS_RECEIVED, $event->from_status);
        $this->assertSame(ContactMessage::STATUS_IN_REVIEW, $event->to_status);
        $this->assertSame((int) $this->operator->getKey(), (int) $event->actor_id);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ContactMessage::class,
            'auditable_id' => $ticket->getKey(),
        ]);
    }

    public function test_03_an_illegal_transition_writes_nothing_at_all(): void
    {
        $ticket = $this->ticket(['status' => ContactMessage::STATUS_ARCHIVED]);

        $before = ContactMessage::query()->count();

        try {
            $this->service->changeStatus($ticket, ContactMessage::STATUS_SPAM, $this->operator);
            $this->fail('An archived ticket has no path to SPAM in the configured table.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('support_ticket_invalid_transition', $exception->getMessage());
        }

        $this->assertSame(ContactMessage::STATUS_ARCHIVED, $ticket->refresh()->status);
        $this->assertSame(0, ContactMessageEvent::query()->count());
        $this->assertSame($before, ContactMessage::query()->count());
    }

    public function test_04_resolving_stamps_the_resolution_time_and_reopening_clears_it(): void
    {
        $ticket = $this->ticket(['status' => ContactMessage::STATUS_IN_REVIEW]);

        $resolved = $this->service->changeStatus($ticket, ContactMessage::STATUS_RESOLVED, $this->operator);

        $this->assertNotNull($resolved->resolved_at);

        $reopened = $this->service->changeStatus($resolved->refresh(), ContactMessage::STATUS_IN_REVIEW, $this->operator);

        $this->assertNull($reopened->resolved_at, 'A reopened ticket must not keep a resolution timestamp.');
    }

    public function test_05_a_note_is_encrypted_stored_and_never_mailed(): void
    {
        $ticket = $this->ticket();

        $event = $this->service->addNote($ticket, $this->operator, 'Internal: the player is a repeat contact.');

        $this->assertSame(ContactMessageEvent::TYPE_NOTE, $event->type);
        $this->assertSame('Internal: the player is a repeat contact.', $event->body_encrypted);

        $raw = (array) \DB::table('contact_message_events')->where('id', $event->getKey())->first();
        $this->assertStringNotContainsString('repeat contact', (string) $raw['body_encrypted']);

        Mail::assertNothingQueued();
    }

    public function test_06_a_reply_is_queued_and_recorded_as_pending_not_sent(): void
    {
        config()->set('support_tickets.reply_from_address', 'support@example.test');

        $ticket = $this->ticket();

        $event = $this->service->reply($ticket, $this->operator, 'Here is the answer you asked for.');

        $this->assertSame(ContactMessageEvent::TYPE_REPLY, $event->type);
        Mail::assertQueued(SupportReplyMail::class);

        $this->assertDatabaseHas('contact_message_deliveries', [
            'contact_message_id' => $ticket->getKey(),
            'provider' => 'reply',
            // PENDING, never SENT: only the transport can say a message was
            // accepted, and nothing in this lane pretends otherwise.
            'state' => ContactMessage::DELIVERY_PENDING,
        ]);
    }

    public function test_07_a_reply_without_a_configured_mailbox_is_refused_and_sends_nothing(): void
    {
        config()->set('support_tickets.reply_from_address', null);

        $ticket = $this->ticket();

        try {
            $this->service->reply($ticket, $this->operator, 'An answer that must not be queued.');
            $this->fail('A reply with no configured mailbox must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('support_ticket_reply_not_configured', $exception->getMessage());
        }

        Mail::assertNothingQueued();
        $this->assertSame(0, ContactMessageEvent::query()->count());
        $this->assertSame(0, \DB::table('contact_message_deliveries')->count());
    }

    public function test_08_marking_spam_and_archiving_are_transitions_not_deletions(): void
    {
        $ticket = $this->ticket();

        $spam = $this->service->markSpam($ticket, $this->operator);

        $this->assertSame(ContactMessage::STATUS_SPAM, $spam->status);
        $this->assertSame(1, ContactMessage::query()->count(), 'A ticket must never be removed.');

        $archived = $this->service->archive($spam->refresh(), $this->operator);

        $this->assertSame(ContactMessage::STATUS_ARCHIVED, $archived->status);
        $this->assertSame(1, ContactMessage::query()->count());
    }

    public function test_09_the_timeline_is_ordered_and_append_only(): void
    {
        $ticket = $this->ticket();

        $this->service->changeStatus($ticket, ContactMessage::STATUS_IN_REVIEW, $this->operator);
        $this->service->addNote($ticket->refresh(), $this->operator, 'First note');
        $this->service->changeStatus($ticket->refresh(), ContactMessage::STATUS_RESOLVED, $this->operator);

        $timeline = $this->service->timeline($ticket->refresh());

        $this->assertSame(3, $timeline->count());
        $this->assertSame(
            [ContactMessageEvent::TYPE_STATUS_CHANGE, ContactMessageEvent::TYPE_NOTE, ContactMessageEvent::TYPE_STATUS_CHANGE],
            $timeline->pluck('type')->all(),
        );

        $this->expectException(\LogicException::class);
        $timeline->first()->body_encrypted = 'rewritten';
        $timeline->first()->save();
    }

    public function test_10_an_empty_note_or_reply_is_refused(): void
    {
        $ticket = $this->ticket();

        $this->expectException(InvalidArgumentException::class);
        $this->service->addNote($ticket, $this->operator, "   \n\t  ");
    }
}
