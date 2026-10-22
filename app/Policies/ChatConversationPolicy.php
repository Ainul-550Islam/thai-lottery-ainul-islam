<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Chat\ChatConversation;
use App\Models\User;
use Throwable;

/**
 * Who may do what to a chat conversation.
 *
 * ============================================================================
 * PERMISSIONS COME FROM CONFIG, NOT FROM HARD-CODED STRINGS
 * ============================================================================
 * The permission names live in `chat.operator.permissions` so a deployment
 * can rename them without touching code. Every ability below resolves its
 * name from that map at call time.
 *
 * ============================================================================
 * FAIL-CLOSED WHEN A PERMISSION IS NOT SEEDED
 * ============================================================================
 * spatie/laravel-permission throws when asked about a permission that has
 * never been created. That exception is caught here and treated as "not
 * granted". The alternative — letting it bubble — would turn an unseeded
 * deployment into a 500 on every console click, and treating it as granted
 * would be the one unforgivable choice. So: no seed, no access, no crash.
 *
 * ============================================================================
 * ABILITIES ARE ACTIONS, NOT ROLES
 * ============================================================================
 * The desk separates SEEING the queue (view), ANSWERING (reply), MOVING
 * conversations around (assign) and ENDING them (close). Those are four
 * different grants because they are four different mistakes when given to
 * the wrong person. A role bundles them later; the policy never knows about
 * roles.
 */
final class ChatConversationPolicy
{
    /**
     * See the queue and open a conversation's transcript.
     */
    public function view(User $user, ?ChatConversation $conversation = null): bool
    {
        return $this->has($user, 'view');
    }

    /**
     * Send a reply or a note into a conversation.
     *
     * Deliberately does NOT require the caller to be the assigned agent:
     * a supervisor answering for a colleague is a legitimate desk action,
     * and the assigned-agent check is a UI nicety, not a security boundary.
     * (The security boundary is `view` + `reply` themselves.)
     */
    public function reply(User $user, ChatConversation $conversation): bool
    {
        if (! $this->has($user, 'reply')) {
            return false;
        }

        // A closed conversation refuses content at the service layer too;
        // refusing it here as well means the console shows the honest state
        // instead of a composer that will 409 on send.
        return ! $conversation->isClosed();
    }

    /**
     * Claim from the queue (take the next, or take a specific one).
     */
    public function claim(User $user): bool
    {
        return $this->has($user, 'reply');
    }

    /**
     * Hand a conversation to another agent, or release it back to the queue.
     */
    public function assign(User $user, ChatConversation $conversation): bool
    {
        return $this->has($user, 'assign');
    }

    /**
     * End a conversation.
     */
    public function close(User $user, ChatConversation $conversation): bool
    {
        return $this->has($user, 'close');
    }

    /**
     * Administer canned responses.
     */
    public function manageCanned(User $user): bool
    {
        return $this->has($user, 'manage_canned');
    }

    /**
     * Resolve one configured permission for this user.
     *
     * @param  'view'|'reply'|'assign'|'close'|'manage_canned'  $abilityKey
     */
    private function has(User $user, string $abilityKey): bool
    {
        $permission = config('chat.operator.permissions.'.$abilityKey);

        if (! is_string($permission) || $permission === '') {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (Throwable) {
            // Unknown permission (never seeded) is a refusal, not an error.
            return false;
        }
    }
}
