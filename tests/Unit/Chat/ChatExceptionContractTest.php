<?php

declare(strict_types=1);

namespace Tests\Unit\Chat;

use App\Exceptions\ChatException;
use PHPUnit\Framework\TestCase;

/**
 * ChatException is the refusal contract every chat endpoint shares. The
 * widget and the console both switch on `code`, and the HTTP status is what
 * decides whether a client retries or gives up. Pinning both here means a
 * careless change to either cannot ship unnoticed:
 *
 *   - a 409 that became a 500 would make a widget show "server error" for
 *     a perfectly normal race ("another agent took it"),
 *   - a code rename would silently disable every client-side branch that
 *     special-cases it.
 */
final class ChatExceptionContractTest extends TestCase
{
    /**
     * @return array<string, array{ChatException, string, int}>
     */
    public static function refusalProvider(): array
    {
        return [
            'feature disabled' => [ChatException::featureDisabled(), 'chat_disabled', 503],
            'not found' => [ChatException::conversationNotFound(), 'chat_conversation_not_found', 404],
            'already claimed' => [ChatException::alreadyClaimed(1, 2), 'chat_already_claimed', 409],
            'not claimable' => [ChatException::notClaimable(1, 'active'), 'chat_not_claimable', 409],
            'conversation closed' => [ChatException::conversationClosed(1), 'chat_conversation_closed', 409],
            'already closed' => [ChatException::alreadyClosed(1), 'chat_already_closed', 409],
            'agent at capacity' => [ChatException::agentAtCapacity(1, 8), 'chat_agent_at_capacity', 409],
            'agent unavailable' => [ChatException::agentNotAvailable(1), 'chat_agent_not_available', 409],
            'not participant' => [ChatException::notParticipant(1, 2), 'chat_not_participant', 404],
            'not assigned agent' => [ChatException::notAssignedAgent(1, 2), 'chat_not_assigned_agent', 403],
            'identity required' => [ChatException::guestIdentityRequired(), 'chat_identity_required', 404],
            'guest disabled' => [ChatException::guestChatDisabled(), 'chat_guest_disabled', 403],
            'empty message' => [ChatException::emptyMessage(), 'chat_message_empty', 422],
            'too long' => [ChatException::messageTooLong(2000), 'chat_message_too_long', 422],
            'kind not writable' => [ChatException::kindNotWritableByVisitor('note'), 'chat_kind_not_writable_by_visitor', 422],
            'attachment refused' => [ChatException::attachmentNotAllowed('x'), 'chat_attachment_not_allowed', 422],
            'too many attachments' => [ChatException::tooManyAttachments(3), 'chat_too_many_attachments', 422],
            'attachment missing' => [ChatException::attachmentNotFound(0), 'chat_attachment_not_found', 404],
            'bad priority' => [ChatException::invalidPriority('x'), 'chat_invalid_priority', 422],
            'bad close reason' => [ChatException::invalidCloseReason('x'), 'chat_invalid_close_reason', 422],
            'rate limited' => [ChatException::rateLimited('message', 5), 'chat_rate_limited', 429],
            'too many conversations' => [ChatException::tooManyConversations(1), 'chat_too_many_conversations', 429],
            'canned missing' => [ChatException::cannedResponseNotFound('k'), 'chat_canned_response_not_found', 404],
        ];
    }

    /**
     * @dataProvider refusalProvider
     */
    public function test_machine_code_and_status_are_the_contract(
        ChatException $exception,
        string $expectedCode,
        int $expectedStatus,
    ): void {
        $this->assertSame($expectedCode, $exception->machineCode());
        $this->assertSame($expectedStatus, $exception->httpStatus());
    }

    /**
     * @dataProvider refusalProvider
     */
    public function test_wire_envelope_is_stable(
        ChatException $exception,
        string $expectedCode,
    ): void {
        $payload = $exception->toArray();

        $this->assertSame('CHAT_REFUSED', $payload['status']);
        $this->assertSame($expectedCode, $payload['code']);
        $this->assertIsString($payload['message']);
        $this->assertNotSame('', $payload['message']);
    }

    public function test_ownership_denial_is_indistinguishable_from_absence(): void
    {
        // A probe for someone else's conversation must learn NOTHING: the
        // notParticipant refusal therefore returns 404, the same status as
        // a conversation that does not exist. If this ever changes to 403,
        // ids become enumerable.
        $this->assertSame(
            ChatException::conversationNotFound()->httpStatus(),
            ChatException::notParticipant(1, null)->httpStatus(),
        );
    }

    public function test_rate_limit_payload_keeps_its_scope(): void
    {
        $exception = ChatException::rateLimited('message', 7);

        $this->assertSame('message', $exception->logContext()['scope']);
        $this->assertSame(7, $exception->logContext()['retry_after']);
    }
}
