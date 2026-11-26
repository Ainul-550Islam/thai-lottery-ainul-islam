<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Chat\ChatConversation;
use App\Models\User;

/**
 * A member may only read and rate their own support conversation.
 *
 * AGENTS ARE NOT GRANTED ANYTHING HERE
 * The operator side of the chat stack has its own permission model
 * (GloEnsurePermission, the operator console's role checks and the agent
 * assignment rules). Granting an operator access through this policy would be a
 * second, weaker path into a member's conversation — the exact thing the operator
 * console's audit trail exists to prevent.
 */
final class SupportConversationPolicy
{
    public function view(User $user, ChatConversation $conversation): bool
    {
        return $conversation->user_id !== null
            && (int) $conversation->user_id === (int) $user->getKey();
    }

    public function sendMessage(User $user, ChatConversation $conversation): bool
    {
        return $this->view($user, $conversation) && $conversation->status->acceptsMessages();
    }

    /**
     * Rating is only meaningful once the conversation is closed, and only once.
     */
    public function rate(User $user, ChatConversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $conversation->isClosed()
            && $conversation->getAttribute('rating') === null;
    }
}
