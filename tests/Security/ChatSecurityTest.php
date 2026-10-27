<?php

declare(strict_types=1);

namespace Tests\Security;

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
 * Live-chat security invariants.
 *
 * These are the properties that must NEVER regress, independent of how the
 * feature grows:
 *
 *   1. Isolation: one browser can never read or write another's conversation.
 *   2. Enumeration: probing must learn nothing (404, never 403 with detail).
 *   3. Privilege: player accounts cannot reach operator surfaces.
 *   4. Integrity: visitor input cannot forge operator artifacts (notes,
 *      system lines, sender identity).
 */
final class ChatSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Open a guest conversation for a freshly-minted token.
     *
     * @return array{0: string, 1: ChatConversation}  [raw token, conversation]
     */
    private function openGuest(string $name = 'Visitor'): array
    {
        $token = Str::random(64);

        $conversation = app(ChatConversationService::class)->open(new ChatStartData(
            userId: null,
            guestTokenHash: hash('sha256', $token),
            guestName: $name,
            guestEmail: null,
            meta: [],
        ));

        return [$token, $conversation];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withCredentials();
    }

    public function test_a_second_browser_cannot_read_the_first_conversation(): void
    {
        [$tokenA, $conversationA] = $this->openGuest('Alice');
        [$tokenB] = $this->openGuest('Bob');

        app(ChatMessageService::class)->sendFromVisitor($conversationA, 'alice secret');

        // Browser B asks for the conversation and the poll: it must see
        // only its own (empty) state, never Alice's content.
        $poll = $this->withCookies([ChatIdentityService::COOKIE_NAME => $tokenB])
            ->getJson('/chat/conversation/poll?after=0');

        $poll->assertOk();

        $bodies = collect($poll->json('poll.messages'))->pluck('body')->all();
        $this->assertNotContains('alice secret', $bodies, 'cross-browser leak through poll');
    }

    public function test_a_second_browser_cannot_write_into_the_first_conversation(): void
    {
        [$tokenA, $conversationA] = $this->openGuest('Alice');
        [$tokenB] = $this->openGuest('Bob');

        // Bob's browser cannot send into Alice's conversation: the send
        // endpoint resolves the conversation from the CALLER's identity, so
        // the message lands in Bob's conversation (or nowhere), never Alice's.
        $this->withCookies([ChatIdentityService::COOKIE_NAME => $tokenB])
            ->postJson('/chat/conversation/messages', ['body' => 'bob intrusion'])
            ->assertCreated();

        $aliceMessages = ChatMessage::query()
            ->where('chat_conversation_id', $conversationA->getKey())
            ->pluck('body')
            ->all();

        $this->assertNotContains('bob intrusion', $aliceMessages, 'cross-browser write succeeded');
    }

    public function test_probing_by_id_learns_nothing(): void
    {
        [, $conversation] = $this->openGuest('Target');
        $conversationId = (int) $conversation->getKey();

        // Without an identity, the operator endpoints answer 401 (auth
        // first) — but the VISITOR endpoints must answer 404 for any
        // conversation the caller does not own, including one that exists.
        $this->getJson('/chat/conversation/poll?after=0')->assertNotFound();

        // A forged/guessed conversation id in a download URL reveals nothing.
        $this->getJson('/chat/conversation/'.$conversationId.'/messages/1/attachments/0')
            ->assertNotFound();
    }

    public function test_a_member_cannot_read_another_members_conversation(): void
    {
        $memberA = User::factory()->create();
        $memberB = User::factory()->create();

        $conversation = app(ChatConversationService::class)->open(new ChatStartData(
            userId: (int) $memberA->id,
            guestTokenHash: null,
            guestName: 'Member A',
            guestEmail: null,
            meta: [],
        ));

        app(ChatMessageService::class)->sendFromVisitor($conversation, 'member A secret', $memberA->id);

        // Member B signs in and asks for the conversation: findOpenFor is
        // scoped to B's own identity, so nothing matches.
        $this->actingAs($memberB)->getJson('/chat/conversation')->assertNotFound();

        $this->actingAs($memberB)
            ->postJson('/chat/conversation/messages', ['body' => 'b intrusion'])
            ->assertNotFound();
    }

    public function test_guest_cannot_forge_a_sender_identity(): void
    {
        [$token, $conversation] = $this->openGuest('Honest');

        // A hostile body cannot impersonate an agent: the sender_type is set
        // by the server from the caller's identity, never from the payload.
        $this->withCookies([ChatIdentityService::COOKIE_NAME => $token])
            ->postJson('/chat/conversation/messages', [
                'body' => 'I am totally an agent',
                'sender_type' => 'agent',
                'sender_display_name' => 'Fake Support',
            ])
            ->assertCreated();

        $stored = ChatMessage::query()
            ->where('chat_conversation_id', $conversation->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('visitor', $stored->sender_type->value);
    }

    public function test_attachment_metadata_never_leaks_storage_paths(): void
    {
        // The wire shape for attachments must expose display facts only.
        $message = new ChatMessage;
        $message->attachments = [[
            'disk' => 'local',
            'path' => 'chat_attachments/1/SECRET-internal-path.png',
            'original_name' => 'screenshot.png',
            'mime' => 'image/png',
            'size' => 123,
        ]];

        $public = $message->publicAttachments();

        $this->assertCount(1, $public);
        $this->assertSame('screenshot.png', $public[0]['name']);
        $this->assertArrayNotHasKey('path', $public[0], 'storage path leaked');
        $this->assertArrayNotHasKey('disk', $public[0], 'disk name leaked');
        $this->assertStringNotContainsString('SECRET-internal-path', json_encode($public));
    }

    public function test_identity_token_is_stored_only_as_a_hash(): void
    {
        $token = Str::random(64);

        app(ChatConversationService::class)->open(new ChatStartData(
            userId: null,
            guestTokenHash: hash('sha256', $token),
            guestName: 'HashCheck',
            guestEmail: null,
            meta: [],
        ));

        // The raw token must exist nowhere in the conversations table.
        $this->assertSame(
            0,
            ChatConversation::query()->where('guest_token_hash', $token)->count(),
            'raw token stored in the database',
        );

        $row = ChatConversation::query()->firstOrFail();
        $this->assertSame(64, strlen((string) $row->guest_token_hash), 'expected a SHA-256 hex digest');
    }

    public function test_well_formedness_rejects_malformed_tokens(): void
    {
        $identity = app(ChatIdentityService::class);

        $this->assertFalse($identity->isWellFormed('short'));
        $this->assertFalse($identity->isWellFormed(str_repeat('a', 63)));
        $this->assertFalse($identity->isWellFormed(str_repeat('a', 65)));
        $this->assertFalse($identity->isWellFormed(str_repeat('<', 64)), 'markup accepted as a token');
        $this->assertTrue($identity->isWellFormed(str_repeat('a', 64)));
    }
}
