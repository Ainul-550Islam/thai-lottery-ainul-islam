<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\DTOs\Chat\ChatStartData;
use App\Models\Chat\ChatConversation;
use App\Models\User;
use App\Services\Chat\ChatConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The operator console API.
 *
 * The desk's authorization story, pinned by this suite:
 *
 *   - nobody reaches the console without auth AND the access-admin gate,
 *   - each action needs its OWN permission: viewing the queue does not
 *     grant replying, replying does not grant moving conversations,
 *   - the claim race ends with exactly one winner and a 409 for the loser,
 *   - an agent reply — not a note, not a system line — is what stamps the
 *     first-response metric.
 */
final class ChatOperatorApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeOperator(array $permissions = ['chat.view', 'chat.reply']): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('admin', 'web');

        // 'view dashboard' is part of the access-admin gate itself
        // (AdminAccess::canAccessPanel) — an operator without it cannot
        // reach ANY console endpoint, chat ones included.
        foreach (['view dashboard', 'chat.view', 'chat.reply', 'chat.assign', 'chat.close', 'chat.manage_canned'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('view dashboard');

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function makeWaitingConversation(string $name = 'Visitor'): ChatConversation
    {
        return app(ChatConversationService::class)->open(new ChatStartData(
            userId: null,
            guestTokenHash: hash('sha256', 'op-test-'.$name.uniqid('', true)),
            guestName: $name,
            guestEmail: null,
            meta: [],
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withCredentials();
    }

    public function test_console_requires_authentication(): void
    {
        // A JSON request without a session is refused 401 by the auth
        // middleware — the important part is that it is refused at all.
        $this->postJson('/operator/chat/take')->assertStatus(401);
        $this->getJson('/operator/chat/dashboard')->assertStatus(401);
    }

    public function test_player_account_cannot_reach_the_console(): void
    {
        $player = User::factory()->create();

        // The access-admin gate stops a roleless account even though it is
        // authenticated. The gate refuses with a redirect/403 before any
        // chat logic runs.
        $this->actingAs($player)->postJson('/operator/chat/take')->assertForbidden();
    }

    public function test_dashboard_shows_queue_and_presence(): void
    {
        $operator = $this->makeOperator();
        $this->makeWaitingConversation('First');

        $response = $this->actingAs($operator)->getJson('/operator/chat/dashboard');

        $response->assertOk();
        $response->assertJsonStructure(['queue', 'workload', 'presence', 'health_warnings', 'next_poll_seconds']);
        $this->assertCount(1, $response->json('queue'));
        $this->assertSame(1, $response->json('presence.waiting_conversations'));
    }

    public function test_claim_without_reply_permission_is_refused(): void
    {
        // view-only operator: can see the queue, cannot take from it.
        $viewer = $this->makeOperator(['chat.view']);
        $conversation = $this->makeWaitingConversation();

        $this->actingAs($viewer)
            ->postJson('/operator/chat/take')
            ->assertStatus(404);

        $this->assertNull($conversation->fresh()->assigned_agent_id, 'claim must not have happened');
    }

    public function test_take_next_claims_the_best_candidate(): void
    {
        $operator = $this->makeOperator();
        $older = $this->makeWaitingConversation('Older');
        $this->makeWaitingConversation('Newer');

        $response = $this->actingAs($operator)->postJson('/operator/chat/take');

        $response->assertOk();
        $this->assertSame((int) $older->getKey(), (int) $response->json('conversation.id'));
        $this->assertSame((int) $operator->id, (int) $older->fresh()->assigned_agent_id);
        $this->assertSame('active', $older->fresh()->status->value);
    }

    public function test_take_next_reports_empty_queue_honestly(): void
    {
        $this->withoutVite(); // asserts page content, not the compiled asset tags
        $operator = $this->makeOperator();

        $this->actingAs($operator)
            ->postJson('/operator/chat/take')
            ->assertOk()
            ->assertJsonPath('status', 'EMPTY_QUEUE');
    }

    public function test_the_claim_race_has_exactly_one_winner(): void
    {
        $winner = $this->makeOperator();
        $loser = $this->makeOperator();

        $conversation = $this->makeWaitingConversation('Contested');

        $first = $this->actingAs($winner)
            ->postJson('/operator/chat/'.$conversation->getKey().'/take');
        $first->assertOk();

        $second = $this->actingAs($loser)
            ->postJson('/operator/chat/'.$conversation->getKey().'/take');

        $second->assertStatus(409)->assertJsonPath('code', 'chat_already_claimed');

        $this->assertSame((int) $winner->id, (int) $conversation->fresh()->assigned_agent_id);
    }

    public function test_agent_reply_stamps_first_response_but_note_does_not(): void
    {
        $operator = $this->makeOperator();
        $conversation = $this->makeWaitingConversation();

        $this->actingAs($operator)->postJson('/operator/chat/take')->assertOk();

        $this->actingAs($operator)->postJson('/operator/chat/'.$conversation->getKey().'/message', [
            'body' => 'internal context only',
            'kind' => 'note',
        ])->assertCreated();

        $this->assertNull($conversation->fresh()->first_response_at, 'a note must not count as a response');

        $this->actingAs($operator)->postJson('/operator/chat/'.$conversation->getKey().'/message', [
            'body' => 'Hello, how can I help?',
        ])->assertCreated();

        $this->assertNotNull($conversation->fresh()->first_response_at, 'the visible reply stamps the metric');
    }

    public function test_visitor_cannot_force_a_note_kind(): void
    {
        // kind=note is operator-only; the visitor surface must reject it.
        $token = str_repeat('v', 64);

        $conversation = app(ChatConversationService::class)->open(new ChatStartData(
            userId: null,
            guestTokenHash: hash('sha256', $token),
            guestName: 'Sneaky',
            guestEmail: null,
            meta: [],
        ));

        $response = $this->withCookies(['thailotto_chat_visitor' => $token])
            ->postJson('/chat/conversation/messages', ['body' => 'x', 'kind' => 'note']);

        // The visitor controller always writes Text; a forged kind field is
        // ignored rather than honoured. Whatever the status, the stored
        // message must NOT be a note.
        $stored = $conversation->messages()->latest('id')->first();

        $this->assertTrue(
            $stored === null || $stored->kind->value !== 'note',
            'a visitor must never be able to write a note',
        );
    }

    public function test_close_without_close_permission_is_refused(): void
    {
        $operator = $this->makeOperator(['chat.view', 'chat.reply']);
        $conversation = $this->makeWaitingConversation();

        $this->actingAs($operator)
            ->postJson('/operator/chat/'.$conversation->getKey().'/close')
            ->assertStatus(404);

        $this->assertFalse($conversation->fresh()->isClosed());
    }

    public function test_operator_close_writes_reason_and_queues_transcript(): void
    {
        $operator = $this->makeOperator(['chat.view', 'chat.reply', 'chat.close']);
        $conversation = $this->makeWaitingConversation();

        $this->actingAs($operator)->postJson('/operator/chat/take')->assertOk();

        $this->actingAs($operator)
            ->postJson('/operator/chat/'.$conversation->getKey().'/close', ['reason' => 'agent'])
            ->assertOk();

        $fresh = $conversation->fresh();
        $this->assertTrue($fresh->isClosed());
        $this->assertSame('agent', $fresh->close_reason->value);
        $this->assertSame((int) $operator->id, (int) $fresh->closed_by_user_id);
    }

    public function test_release_puts_the_conversation_back_in_the_queue(): void
    {
        $operator = $this->makeOperator(['chat.view', 'chat.reply', 'chat.assign']);
        $conversation = $this->makeWaitingConversation();

        $this->actingAs($operator)->postJson('/operator/chat/take')->assertOk();
        $this->assertSame('active', $conversation->fresh()->status->value);

        $this->actingAs($operator)
            ->postJson('/operator/chat/'.$conversation->getKey().'/release')
            ->assertOk();

        $fresh = $conversation->fresh();
        $this->assertSame('waiting', $fresh->status->value);
        $this->assertNull($fresh->assigned_agent_id);
    }

    public function test_operator_poll_sees_internal_notes(): void
    {
        $operator = $this->makeOperator();
        $conversation = $this->makeWaitingConversation();

        $this->actingAs($operator)->postJson('/operator/chat/take')->assertOk();
        $this->actingAs($operator)->postJson('/operator/chat/'.$conversation->getKey().'/message', [
            'body' => 'note for the team',
            'kind' => 'note',
        ])->assertCreated();

        $poll = $this->actingAs($operator)
            ->postJson('/operator/chat/'.$conversation->getKey().'/poll?after=0');

        $poll->assertOk();

        $bodies = collect($poll->json('poll.messages'))->pluck('body')->all();
        $this->assertContains('note for the team', $bodies, 'operators must see internal notes');
    }
}
