<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\DTOs\Chat\ChatStartData;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatIdentityService;
use App\Services\Chat\ChatMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The visitor's chat API, end to end over HTTP.
 *
 * What this suite refuses to let regress:
 *
 *   - a browser with no identity gets NOTHING (404), never someone else's
 *     conversation,
 *   - the same browser always comes back to its own conversation (resume,
 *     never duplicate),
 *   - internal notes never leak to the visitor's poll or transcript,
 *   - a closed conversation stops accepting messages with a 409.
 *
 * HOW GUEST IDENTITY IS SET UP IN TESTS
 * Laravel test cookies are encrypted on the way out and re-encrypted when a
 * test re-sends them, so round-tripping a cookie value read off a response
 * would double-encrypt it. Instead these tests mint a token the way the
 * identity service does, open the conversation with its hash, and then
 * present the RAW token with withCookies() — exactly what a real browser
 * holding the HttpOnly cookie would do.
 */
final class ChatVisitorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // JSON test requests only carry cookies when credentials mode is on
        // (prepareCookiesForJsonRequest), and the whole visitor surface is
        // cookie-identified — so it is the default for this suite.
        $this->withCredentials();
    }

    /**
     * Open a guest conversation and return [cookies, conversation].
     *
     * @return array{0: array<string, string>, 1: ChatConversation}
     */
    private function openGuestConversation(string $name = 'Somchai'): array
    {
        $token = Str::random(64);
        $hash = hash('sha256', $token);

        $conversation = app(ChatConversationService::class)->open(new ChatStartData(
            userId: null,
            guestTokenHash: $hash,
            guestName: $name,
            guestEmail: null,
            meta: ['page_url' => '/results'],
        ));

        // withCookies() encrypts (with the CookieValuePrefix) on the way out,
        // so the RAW token goes in here and arrives at the middleware in the
        // same form a browser's HttpOnly cookie would.
        return [[ChatIdentityService::COOKIE_NAME => $token], $conversation];
    }

    public function test_availability_is_public_and_honest(): void
    {
        $response = $this->getJson('/chat/availability');

        $response->assertOk();
        $response->assertJsonStructure(['state', 'online_agents', 'waiting_conversations',
            'can_start_conversation', 'offers_ticket_fallback']);

        // With no agents heartbeating, the honest state is offline, and the
        // composer must be closed rather than offering a dead queue.
        $response->assertJsonPath('state', 'offline');
        $response->assertJsonPath('can_start_conversation', false);
        $response->assertJsonPath('offers_ticket_fallback', true);
    }

    public function test_guest_can_open_a_conversation_and_gets_identity_cookie(): void
    {
        $response = $this->postJson('/chat/start', [
            'guest_name' => '  Somchai   Jaidee  ',
            'guest_email' => 'SOMCHAI@Example.COM',
            'meta' => ['page_url' => '/deposit', 'evil' => 'dropped'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('conversation.status', 'waiting');

        $reference = (string) $response->json('conversation.reference');
        $this->assertStringStartsWith('CH-', $reference);

        $conversation = ChatConversation::query()->where('public_reference', $reference)->first();

        $this->assertInstanceOf(ChatConversation::class, $conversation);
        $this->assertSame('Somchai Jaidee', $conversation->guest_name);
        $this->assertSame('somchai@example.com', $conversation->guest_email);
        $this->assertArrayHasKey('page_url', $conversation->meta ?? []);
        $this->assertArrayNotHasKey('evil', $conversation->meta ?? [], 'meta allow-list must drop unknown keys');

        $this->assertNotNull(
            collect($response->headers->getCookies())->first(
                fn ($cookie) => $cookie->getName() === ChatIdentityService::COOKIE_NAME,
            ),
            'a guest without a cookie must be issued one',
        );
    }

    public function test_start_is_idempotent_resume_not_duplicate(): void
    {
        [$cookies, $conversation] = $this->openGuestConversation();

        $response = $this->withCookies($cookies)->postJson('/chat/start', ['guest_name' => 'Somchai']);

        $response->assertCreated();
        $this->assertSame(1, ChatConversation::query()->count(), 'resume must not fork the visitor');
        $this->assertSame((int) $conversation->getKey(), (int) ChatConversation::query()->first()->getKey());
        $this->assertSame((string) $conversation->public_reference, (string) $response->json('conversation.reference'));
    }

    public function test_unknown_browser_gets_404_not_someone_elses_chat(): void
    {
        $this->openGuestConversation();

        // A fresh browser (no cookies) asking for the conversation or its
        // poll must see nothing: 404, never the conversation, never a 403
        // that would confirm the conversation exists.
        $this->getJson('/chat/conversation')->assertNotFound();
        $this->getJson('/chat/conversation/poll?after=0')->assertNotFound();
        $this->postJson('/chat/conversation/messages', ['body' => 'hello'])->assertNotFound();
    }

    public function test_visitor_can_send_and_poll_their_own_message(): void
    {
        [$cookies] = $this->openGuestConversation();

        $send = $this->withCookies($cookies)->postJson('/chat/conversation/messages', [
            'body' => 'My deposit is stuck',
            'client_token' => 'tok-1',
        ]);

        $send->assertCreated()->assertJsonPath('message.body', 'My deposit is stuck');

        $poll = $this->withCookies($cookies)->getJson('/chat/conversation/poll?after=0');

        $poll->assertOk();

        $bodies = collect($poll->json('poll.messages'))->pluck('body')->all();
        $this->assertContains('My deposit is stuck', $bodies);
    }

    public function test_client_token_retry_does_not_duplicate(): void
    {
        [$cookies] = $this->openGuestConversation();

        $payload = ['body' => 'retry me', 'client_token' => 'tok-dup'];

        $first = $this->withCookies($cookies)->postJson('/chat/conversation/messages', $payload);
        $second = $this->withCookies($cookies)->postJson('/chat/conversation/messages', $payload);

        $first->assertCreated();
        $second->assertCreated();

        $this->assertSame($first->json('message.id'), $second->json('message.id'));
        $this->assertSame(1, ChatMessage::query()->count());
    }

    public function test_empty_and_oversized_messages_are_refused_with_codes(): void
    {
        [$cookies] = $this->openGuestConversation();

        $this->withCookies($cookies)
            ->postJson('/chat/conversation/messages', ['body' => "   \n\t "])
            ->assertStatus(422)
            ->assertJsonPath('code', 'chat_message_empty');

        $this->withCookies($cookies)
            ->postJson('/chat/conversation/messages', ['body' => str_repeat('x', 2001)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'chat_message_too_long');

        $this->assertSame(0, ChatMessage::query()->count(), 'refused messages must not be stored');
    }

    public function test_internal_notes_never_reach_the_visitor(): void
    {
        [$cookies, $conversation] = $this->openGuestConversation();

        $agent = User::factory()->create();

        // The note and a real reply, written through the service exactly as
        // the operator endpoints would.
        app(ChatMessageService::class)->addNote($conversation, 'VIP - handle carefully', $agent);
        app(ChatMessageService::class)->sendFromAgent($conversation, 'How can I help?', $agent);

        $poll = $this->withCookies($cookies)->getJson('/chat/conversation/poll?after=0');
        $poll->assertOk();

        $bodies = collect($poll->json('poll.messages'))->pluck('body')->all();

        $this->assertContains('How can I help?', $bodies);
        $this->assertNotContains('VIP - handle carefully', $bodies, 'note leaked to the visitor poll');

        $transcript = $this->withCookies($cookies)->getJson('/chat/conversation/transcript');
        $transcript->assertOk();

        $transcriptBodies = collect($transcript->json('messages'))->pluck('body')->all();
        $this->assertNotContains('VIP - handle carefully', $transcriptBodies, 'note leaked to the transcript');
    }

    public function test_close_is_final_for_sends_but_keeps_the_thread_readable(): void
    {
        [$cookies, $conversation] = $this->openGuestConversation();

        app(ChatMessageService::class)->sendFromVisitor($conversation, 'hello');

        $this->withCookies($cookies)->postJson('/chat/conversation/close')->assertOk()
            ->assertJsonPath('conversation.status', 'closed');

        // The transcript must survive the close — that is the visitor's copy.
        $this->withCookies($cookies)->getJson('/chat/conversation/transcript')->assertOk();

        // But writing is over: 409 with the stable machine code.
        $this->withCookies($cookies)
            ->postJson('/chat/conversation/messages', ['body' => 'one more'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'chat_conversation_closed');
    }

    public function test_mark_read_zeroes_the_visitor_counter_idempotently(): void
    {
        [$cookies, $conversation] = $this->openGuestConversation();

        $agent = User::factory()->create();
        app(ChatMessageService::class)->sendFromAgent($conversation, 'reply one', $agent);
        app(ChatMessageService::class)->sendFromAgent($conversation, 'reply two', $agent);

        $this->assertSame(2, (int) $conversation->fresh()->visitor_unread_count);

        $this->withCookies($cookies)->postJson('/chat/conversation/read')->assertOk()
            ->assertJsonPath('marked_read', 2);

        $this->assertSame(0, (int) $conversation->fresh()->visitor_unread_count);

        $this->withCookies($cookies)->postJson('/chat/conversation/read')->assertOk()
            ->assertJsonPath('marked_read', 0);
    }

    public function test_signed_in_member_claims_the_guest_conversation_without_losing_it(): void
    {
        [$cookies, $conversation] = $this->openGuestConversation();

        $member = User::factory()->create();

        $opened = app(ChatConversationService::class)->open(new ChatStartData(
            userId: (int) $member->getAuthIdentifier(),
            guestTokenHash: (string) $conversation->guest_token_hash,
            guestName: 'Somchai',
            guestEmail: null,
            meta: [],
        ));

        $this->assertSame((int) $conversation->getKey(), (int) $opened->getKey(),
            'signing in must claim the existing conversation, not create a second');
        $this->assertSame((int) $member->getAuthIdentifier(), $opened->fresh()->user_id);

        // Still reachable by the browser that started it.
        $this->withCookies($cookies)->getJson('/chat/conversation')->assertOk();
    }

    public function test_widget_config_respects_master_switch(): void
    {
        $this->getJson('/chat/widget')->assertOk()->assertJsonPath('enabled', true);

        config(['chat.enabled' => false]);

        // Both read endpoints stay 200 with the honest state; the widget
        // hides itself from `enabled=false` and the availability DTO says
        // `disabled`. The 503 refusal belongs to the mutating endpoints.
        $this->getJson('/chat/widget')->assertOk()->assertJsonPath('enabled', false);
        $this->getJson('/chat/availability')->assertOk()->assertJsonPath('state', 'disabled');

        // And the mutating surface refuses with the stable code.
        $this->postJson('/chat/start', ['guest_name' => 'X'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'chat_disabled');
    }
}
