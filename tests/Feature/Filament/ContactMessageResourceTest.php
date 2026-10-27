<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ContactMessageResource;
use App\Filament\Resources\ContactMessageResource\Pages\ListContactMessages;
use App\Mail\SupportReplyMail;
use App\Models\ContactMessage;
use App\Models\ContactMessageEvent;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The support inbox in the panel.
 *
 * The visitor's message is the only copy of what they said, so the invariants
 * here are about not losing it and not pretending about it:
 *  - status moves are transitions, never deletions;
 *  - internal notes are encrypted, never mailed, and cannot be edited later;
 *  - a reply is QUEUED and recorded as pending, and is refused outright when no
 *    reply mailbox is configured rather than silently dropped;
 *  - opening a record is audited, because that page shows the message body.
 */
final class ContactMessageResourceTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = \DB::connection()->getDatabaseName();

        $this->assertContains(
            basename((string) $database),
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolePermissionSeeder::class);

        Mail::fake();

        config()->set('support_tickets.reply_from_address', 'support@example.test');
        config()->set('support_tickets.reply_from_name', 'Player Support');
    }

    private function operator(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    private function ticket(array $overrides = []): ContactMessage
    {
        return ContactMessage::query()->create(array_merge([
            'public_reference' => 'TCK-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Ticket Holder',
            'email' => 'ticket.holder@example.test',
            'subject' => 'A question about a payout',
            'message' => 'The message body the visitor wrote.',
            'status' => ContactMessage::STATUS_RECEIVED,
            'delivery_state' => ContactMessage::DELIVERY_NOT_CONFIGURED,
            'locale' => 'en',
        ], $overrides));
    }

    // =====================================================================
    // Reads
    // =====================================================================

    public function test_01_the_inbox_and_a_record_render(): void
    {
        $ticket = $this->ticket();
        $admin = $this->operator();

        $this->actingAs($admin)->get(ContactMessageResource::getUrl('index'))->assertSuccessful();

        Livewire::actingAs($admin)
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->assertOk()
            ->assertSee($ticket->public_reference);

        $this->actingAs($admin)
            ->get(ContactMessageResource::getUrl('view', ['record' => $ticket]))
            ->assertSuccessful()
            ->assertSee($ticket->public_reference)
            ->assertSee('The message body the visitor wrote.');
    }

    public function test_02_opening_a_message_is_audited(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operator())
            ->get(ContactMessageResource::getUrl('view', ['record' => $ticket]))
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => ContactMessage::class,
            'auditable_id' => $ticket->getKey(),
            'action' => 'data_export',
        ]);
    }

    // =====================================================================
    // The working life-cycle
    // =====================================================================

    public function test_03_start_review_moves_the_ticket_and_records_an_event(): void
    {
        $ticket = $this->ticket();

        Livewire::actingAs($this->operator())
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->callTableAction('start_review', record: $ticket)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ContactMessage::STATUS_IN_REVIEW, $ticket->refresh()->status);
        $this->assertDatabaseHas('contact_message_events', [
            'contact_message_id' => $ticket->getKey(),
            'type' => ContactMessageEvent::TYPE_STATUS_CHANGE,
            'to_status' => ContactMessage::STATUS_IN_REVIEW,
        ]);
    }

    public function test_04_resolving_stamps_the_resolution_time_and_reopening_clears_it(): void
    {
        $ticket = $this->ticket(['status' => ContactMessage::STATUS_IN_REVIEW]);

        Livewire::actingAs($this->operator())
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->callTableAction('resolve', record: $ticket)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ContactMessage::STATUS_RESOLVED, $ticket->refresh()->status);
        $this->assertNotNull($ticket->refresh()->resolved_at);

        Livewire::actingAs($this->operator())
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->callTableAction('reopen', record: $ticket->refresh())
            ->assertHasNoTableActionErrors();

        $this->assertSame(ContactMessage::STATUS_IN_REVIEW, $ticket->refresh()->status);
        $this->assertNull($ticket->refresh()->resolved_at, 'A reopened ticket must not keep a resolution timestamp.');
    }

    public function test_05_an_internal_note_is_encrypted_stored_and_never_mailed(): void
    {
        $ticket = $this->ticket();
        $operator = $this->operator();

        Livewire::actingAs($operator)
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->callTableAction('add_note', record: $ticket, data: ['body' => 'Repeat contact; previous ticket was about the same payout.'])
            ->assertHasNoTableActionErrors();

        $event = ContactMessageEvent::query()->where('contact_message_id', $ticket->getKey())->firstOrFail();

        $this->assertSame(ContactMessageEvent::TYPE_NOTE, $event->type);
        $this->assertStringContainsString('Repeat contact', (string) $event->body_encrypted);
        $this->assertSame((int) $operator->getKey(), (int) $event->actor_id);

        $raw = (array) DB::table('contact_message_events')->where('id', $event->getKey())->first();
        $this->assertStringNotContainsString('Repeat contact', (string) $raw['body_encrypted']);

        Mail::assertNothingQueued();
    }

    public function test_06_a_reply_is_queued_and_recorded_as_pending(): void
    {
        $ticket = $this->ticket(['status' => ContactMessage::STATUS_IN_REVIEW]);

        Livewire::actingAs($this->operator())
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->callTableAction('reply', record: $ticket, data: ['body' => 'Here is the answer to the question you asked.'])
            ->assertHasNoTableActionErrors();

        Mail::assertQueued(SupportReplyMail::class);
        $this->assertDatabaseHas('contact_message_deliveries', [
            'contact_message_id' => $ticket->getKey(),
            'provider' => 'reply',
            'state' => ContactMessage::DELIVERY_PENDING,
        ]);
    }

    public function test_07_the_reply_action_hides_itself_without_a_configured_mailbox(): void
    {
        $ticket = $this->ticket();

        Livewire::actingAs($this->operator())
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('reply', record: $ticket);

        config()->set('support_tickets.reply_from_address', null);

        Livewire::actingAs($this->operator())
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('reply', record: $ticket);
    }

    public function test_08_spam_and_archive_are_transitions_not_deletions(): void
    {
        $ticket = $this->ticket();

        Livewire::actingAs($this->operator())
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->callTableAction('mark_spam', record: $ticket)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ContactMessage::STATUS_SPAM, $ticket->refresh()->status);

        Livewire::actingAs($this->operator())
            ->test(ListContactMessages::class, ['activeTab' => 'all'])
            ->callTableAction('archive', record: $ticket->refresh())
            ->assertHasNoTableActionErrors();

        $this->assertSame(ContactMessage::STATUS_ARCHIVED, $ticket->refresh()->status);
        $this->assertSame(1, ContactMessage::query()->count(), 'A support message must never be removed.');
    }

    // =====================================================================
    // Layout and authorization
    // =====================================================================

    public function test_09_the_resource_offers_no_create_edit_or_delete(): void
    {
        $ticket = $this->ticket();

        Auth::login($this->operator('super-admin'));

        $this->assertFalse(ContactMessageResource::canCreate());
        $this->assertFalse(ContactMessageResource::canDeleteAny());
        $this->assertFalse(ContactMessageResource::canEdit($ticket));
        $this->assertFalse(ContactMessageResource::canDelete($ticket));
        $this->assertArrayNotHasKey('create', ContactMessageResource::getPages());
        $this->assertArrayNotHasKey('edit', ContactMessageResource::getPages());
    }

    public function test_10_a_player_cannot_reach_the_inbox(): void
    {
        $ticket = $this->ticket();
        $player = $this->operator('player');

        $this->actingAs($player)->get(ContactMessageResource::getUrl('index'))->assertForbidden();
        $this->actingAs($player)->get(ContactMessageResource::getUrl('view', ['record' => $ticket]))->assertForbidden();

        Auth::login($player);

        $this->assertFalse(Gate::allows('view', $ticket));
        $this->assertFalse(Gate::allows('reply', $ticket));
        $this->assertFalse(Gate::allows('delete', $ticket));
    }

    public function test_11_the_timeline_is_ordered_and_append_only(): void
    {
        $ticket = $this->ticket();
        $operator = $this->operator();
        $service = app(SupportTicketService::class);

        $service->changeStatus($ticket, ContactMessage::STATUS_IN_REVIEW, $operator);
        $service->addNote($ticket->refresh(), $operator, 'First note');
        $service->changeStatus($ticket->refresh(), ContactMessage::STATUS_RESOLVED, $operator);

        $this->actingAs($operator)
            ->get(ContactMessageResource::getUrl('view', ['record' => $ticket->refresh()]))
            ->assertSuccessful()
            ->assertSee('First note');

        $this->expectException(\LogicException::class);

        $first = ContactMessageEvent::query()->where('contact_message_id', $ticket->getKey())->orderBy('id')->firstOrFail();
        $first->body_encrypted = 'rewritten history';
        $first->save();
    }
}
