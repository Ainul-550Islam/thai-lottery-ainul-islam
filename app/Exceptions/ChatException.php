<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Refusal contract shared by every live-chat endpoint.
 */
final class ChatException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        private readonly string $machineCode,
        string $message,
        private readonly int $httpStatus = 422,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function machineCode(): string
    {
        return $this->machineCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function logContext(): array
    {
        return $this->context;
    }

    /**
     * @return array{status: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'status' => 'CHAT_REFUSED',
            'code' => $this->machineCode,
            'message' => $this->getMessage(),
        ];
    }

    public static function featureDisabled(): self
    {
        return new self('chat_disabled', 'Live chat is currently disabled.', 503);
    }

    public static function conversationNotFound(): self
    {
        return new self('chat_conversation_not_found', 'Conversation not found.', 404);
    }

    public static function alreadyClaimed(int $conversationId, ?int $assignedAgentId = null): self
    {
        return new self(
            'chat_already_claimed',
            'This conversation has already been claimed by another operator.',
            409,
            ['conversation_id' => $conversationId, 'assigned_agent_id' => $assignedAgentId],
        );
    }

    public static function notClaimable(int $conversationId, string $status): self
    {
        return new self(
            'chat_not_claimable',
            'This conversation is not in a claimable state.',
            409,
            ['conversation_id' => $conversationId, 'status' => $status],
        );
    }

    public static function conversationClosed(int $conversationId): self
    {
        return new self(
            'chat_conversation_closed',
            'This conversation is closed and no longer accepts messages.',
            409,
            ['conversation_id' => $conversationId],
        );
    }

    public static function alreadyClosed(int $conversationId): self
    {
        return new self(
            'chat_already_closed',
            'This conversation is already closed.',
            409,
            ['conversation_id' => $conversationId],
        );
    }

    public static function agentAtCapacity(int $agentUserId, int $max): self
    {
        return new self(
            'chat_agent_at_capacity',
            sprintf('Operator is already at the maximum capacity of %d active conversations.', $max),
            409,
            ['agent_user_id' => $agentUserId, 'max' => $max],
        );
    }

    public static function agentNotAvailable(int $agentUserId): self
    {
        return new self(
            'chat_agent_not_available',
            'The target operator is not currently available.',
            409,
            ['agent_user_id' => $agentUserId],
        );
    }

    public static function notParticipant(int $conversationId, ?int $userId = null): self
    {
        return new self(
            'chat_not_participant',
            'Conversation not found.',
            404,
            ['conversation_id' => $conversationId, 'user_id' => $userId],
        );
    }

    public static function notAssignedAgent(int $conversationId, int $userId): self
    {
        return new self(
            'chat_not_assigned_agent',
            'You are not the assigned operator for this conversation.',
            403,
            ['conversation_id' => $conversationId, 'user_id' => $userId],
        );
    }

    public static function guestIdentityRequired(): self
    {
        return new self('chat_identity_required', 'A valid chat identity is required.', 404);
    }

    public static function guestChatDisabled(): self
    {
        return new self('chat_guest_disabled', 'Guest chat is disabled. Please sign in to start a conversation.', 403);
    }

    public static function emptyMessage(): self
    {
        return new self('chat_message_empty', 'Message body cannot be empty.', 422);
    }

    public static function messageTooLong(int $maxLength): self
    {
        return new self(
            'chat_message_too_long',
            sprintf('Message exceeds the maximum length of %d characters.', $maxLength),
            422,
            ['max_length' => $maxLength],
        );
    }

    public static function kindNotWritableByVisitor(string $kind): self
    {
        return new self(
            'chat_kind_not_writable_by_visitor',
            'Visitors cannot write messages of this kind.',
            422,
            ['kind' => $kind],
        );
    }

    public static function attachmentNotAllowed(string $reason): self
    {
        return new self(
            'chat_attachment_not_allowed',
            'This attachment is not allowed.',
            422,
            ['reason' => $reason],
        );
    }

    public static function tooManyAttachments(int $max): self
    {
        return new self(
            'chat_too_many_attachments',
            sprintf('A message may carry at most %d attachments.', $max),
            422,
            ['max' => $max],
        );
    }

    public static function attachmentNotFound(int $index): self
    {
        return new self(
            'chat_attachment_not_found',
            'Attachment not found.',
            404,
            ['index' => $index],
        );
    }

    public static function invalidPriority(string $priority): self
    {
        return new self(
            'chat_invalid_priority',
            'Invalid conversation priority.',
            422,
            ['priority' => $priority],
        );
    }

    public static function invalidCloseReason(string $reason): self
    {
        return new self(
            'chat_invalid_close_reason',
            'Invalid conversation close reason.',
            422,
            ['reason' => $reason],
        );
    }

    public static function rateLimited(string $scope, int $retryAfterSeconds): self
    {
        $retryAfter = max(1, $retryAfterSeconds);

        return new self(
            'chat_rate_limited',
            'Too many chat requests. Please wait before trying again.',
            429,
            ['scope' => $scope, 'retry_after' => $retryAfter],
        );
    }

    public static function tooManyConversations(int $max): self
    {
        return new self(
            'chat_too_many_conversations',
            'Too many open conversations.',
            429,
            ['max' => $max],
        );
    }

    public static function cannedResponseNotFound(string $key): self
    {
        return new self(
            'chat_canned_response_not_found',
            'Canned response not found.',
            404,
            ['key' => $key],
        );
    }
}
