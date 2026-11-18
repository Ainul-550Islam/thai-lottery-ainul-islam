<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Chat\ChatConversation;
use App\Models\User;
use App\Services\Support\SupportConversationService;
use App\Services\Support\SupportMessageService;
use App\Services\Support\SupportRefused;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Support = the existing chat stack, reused. This class proves it was reused rather
 * than duplicated.
 *
 * WHAT "REUSED" IS TESTED AS
 *   - a conversation opened from the member API is a ChatConversation row, reachable
 *     by the same relations the operator console uses
 *   - ownership is enforced by the member id, not by a new token scheme
 *   - a rating is written once and cannot be overwritten
 *
 * If a future change introduced a parallel support_conversations table, these tests
 * would still pass individually but the ChatConversation assertions would fail first.
 */
final class SupportConversationServiceTest extends TestCase
{
    use DatabaseTruncation;

    private SupportConversationService $support;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = app(SupportConversationService::class);
    }

    public function test_opening_a_conversation_creates_a_chat_conversation_row(): void
    {
        $user = User::factory()->create();

        $conversation = $this->support->open((int) $user->id, 'Withdrawal not received');

        $this->assertInstanceOf(ChatConversation::class, $conversation);
        $this->assertSame((int) $user->id, (int) $conversation->user_id);
        $this->assertNotNull($conversation->public_reference);
    }

    public function test_the_subject_is_kept_with_the_conversation(): void
    {
        $user = User::factory()->create();

        $conversation = $this->support->open((int) $user->id, 'Bonus question');

        $this->assertSame('Bonus question', $conversation->meta['subject'] ?? null);
    }

    public function test_the_same_subject_reuses_the_open_conversation(): void
    {
        $user = User::factory()->create();

        $first = $this->support->open((int) $user->id, 'Withdrawal not received');
        $second = $this->support->open((int) $user->id, 'Withdrawal not received');

        // A member double-tapping "start chat" should not open two threads for the
        // same question; the operator would answer one of them.
        $this->assertSame($first->id, $second->id);
    }

    public function test_the_list_is_scoped_to_the_member(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->support->open((int) $mine->id, 'My question');
        $this->support->open((int) $theirs->id, 'Their question');

        $mineList = $this->support->listFor((int) $mine->id);

        foreach ($mineList as $conversation) {
            $this->assertSame((int) $mine->id, (int) $conversation->user_id);
        }

        $this->assertCount(1, $mineList);
    }

    public function test_showing_another_members_conversation_is_refused(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $theirConversation = $this->support->open((int) $theirs->id, 'Their question');

        // findOwned() answers null rather than throwing: "does not exist" and "is not
        // yours" must be indistinguishable to the caller, or the endpoint becomes an
        // oracle for which conversation ids exist.
        $this->assertNull(
            $this->support->findOwned((int) $mine->id, (int) $theirConversation->id),
            'Another member\'s conversation must not be reachable by id.',
        );
    }

    public function test_rating_a_conversation_stores_it_once(): void
    {
        $user = User::factory()->create();

        $conversation = $this->support->open((int) $user->id, 'Question about my ticket');

        // Rating is only meaningful once the conversation is closed.
        $conversation->status = \App\Enums\Chat\ChatConversationStatus::Closed;
        $conversation->closed_at = now();
        $conversation->save();

        $rated = app(SupportMessageService::class)->rate((int) $user->id, (string) $conversation->public_reference, 5, 'Quick answer, thanks.');

        $this->assertSame(5, (int) $rated->getAttribute('rating'));
        $this->assertNotNull($rated->getAttribute('rated_at'));
        $this->assertSame('Quick answer, thanks.', $rated->getAttribute('rating_comment'));
    }

    public function test_an_open_conversation_cannot_be_rated(): void
    {
        $user = User::factory()->create();

        $conversation = $this->support->open((int) $user->id, 'Still open');

        $this->expectException(SupportRefused::class);

        try {
            app(SupportMessageService::class)->rate((int) $user->id, (string) $conversation->public_reference, 4, null);
        } catch (SupportRefused $e) {
            $this->assertContains($e->reasonCode, ['conversation_still_open', 'not_closed']);

            throw $e;
        }
    }

    public function test_a_conversation_cannot_be_rated_twice(): void
    {
        $user = User::factory()->create();
        $conversation = $this->support->open((int) $user->id, 'Rate me once');

        $conversation->status = \App\Enums\Chat\ChatConversationStatus::Closed;
        $conversation->closed_at = now();
        $conversation->save();

        app(SupportMessageService::class)->rate((int) $user->id, (string) $conversation->public_reference, 5, null);

        $this->expectException(SupportRefused::class);

        try {
            app(SupportMessageService::class)->rate((int) $user->id, (string) $conversation->public_reference, 1, 'Changed my mind.');
        } catch (SupportRefused $e) {
            $this->assertContains($e->reasonCode, ['already_rated', 'rating_exists']);

            throw $e;
        }
    }

    public function test_a_guest_conversation_belongs_to_nobody(): void
    {
        // The member endpoint must never adopt an anonymous conversation: doing so
        // would hand a member somebody else's transcript.
        $user = User::factory()->create();

        $guest = new ChatConversation();
        $guest->uuid = (string) \Illuminate\Support\Str::uuid();
        $guest->user_id = null;
        $guest->public_reference = 'GUEST-'.strtoupper(bin2hex(random_bytes(4)));
        $guest->status = \App\Enums\Chat\ChatConversationStatus::Waiting;
        $guest->save();

        $this->assertFalse(
            app(\App\Policies\SupportConversationPolicy::class)->view($user, $guest),
            'A guest conversation is not owned by any member.',
        );
    }
}
