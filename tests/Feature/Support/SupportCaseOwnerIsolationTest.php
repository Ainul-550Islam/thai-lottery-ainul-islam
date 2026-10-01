<?php

// TYPE: Laravel feature test
// PURPOSE: Verify owner-scoped support case creation, detail access, reply access, and cross-account isolation.

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportCase;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupportCaseOwnerIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_can_create_and_read_a_support_case(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('support.store'), [
            'category' => 'payment',
            'priority' => 'normal',
            'subject' => 'Synthetic payment support case',
            'body' => 'Synthetic test body; no production payment is implied.',
        ]);

        $response->assertRedirect(route('support.index'));
        $this->assertDatabaseHas('support_cases', [
            'owner_user_id' => $owner->getKey(),
            'category' => 'payment',
            'subject' => 'Synthetic payment support case',
            'status' => SupportCase::STATUS_OPEN,
        ]);

        $case = SupportCase::query()->where('owner_user_id', $owner->getKey())->firstOrFail();
        $this->assertDatabaseHas('support_messages', [
            'support_case_id' => $case->getKey(),
            'sender_user_id' => $owner->getKey(),
            'internal' => false,
        ]);

        $detail = $this->actingAs($owner)->get(route('support.show', ['reference' => $case->public_reference]));
        $detail->assertOk();
        $detail->assertSee('Synthetic payment support case');
        $detail->assertSee('Synthetic test body; no production payment is implied.');
    }

    public function test_a_second_owner_cannot_read_or_reply_to_the_first_owners_case(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $case = SupportCase::factory()->create(['owner_user_id' => $owner->getKey()]);

        $detail = $this->actingAs($otherOwner)->get(route('support.show', ['reference' => $case->public_reference]));
        $detail->assertNotFound();

        $reply = $this->actingAs($otherOwner)->post(route('support.reply', ['reference' => $case->public_reference]), [
            'body' => 'This cross-owner reply must not be accepted.',
        ]);
        $reply->assertNotFound();
        $this->assertDatabaseMissing('support_messages', [
            'support_case_id' => $case->getKey(),
            'body' => 'This cross-owner reply must not be accepted.',
        ]);
    }

    public function test_owner_can_reply_but_closed_case_cannot_receive_a_reply(): void
    {
        $owner = User::factory()->create();
        $case = SupportCase::factory()->create(['owner_user_id' => $owner->getKey()]);

        $response = $this->actingAs($owner)->post(route('support.reply', ['reference' => $case->public_reference]), [
            'body' => 'Synthetic owner reply.',
        ]);
        $response->assertRedirect(route('support.show', ['reference' => $case->public_reference]));
        $this->assertDatabaseHas('support_messages', [
            'support_case_id' => $case->getKey(),
            'sender_user_id' => $owner->getKey(),
            'body' => 'Synthetic owner reply.',
        ]);

        $case->forceFill(['status' => SupportCase::STATUS_CLOSED])->save();
        $closedReply = $this->actingAs($owner)->post(route('support.reply', ['reference' => $case->public_reference]), [
            'body' => 'This reply must be rejected after closure.',
        ]);
        $closedReply->assertNotFound();
        $this->assertDatabaseMissing('support_messages', [
            'support_case_id' => $case->getKey(),
            'body' => 'This reply must be rejected after closure.',
        ]);
    }

    public function test_support_message_model_hides_internal_identifiers_from_array_output(): void
    {
        $message = new SupportMessage([
            'support_case_id' => 10,
            'sender_user_id' => 20,
            'body' => 'Synthetic message.',
            'internal' => false,
        ]);

        $array = $message->toArray();
        self::assertArrayNotHasKey('support_case_id', $array);
        self::assertArrayNotHasKey('sender_user_id', $array);
        self::assertArrayNotHasKey('internal', $array);
    }
}
