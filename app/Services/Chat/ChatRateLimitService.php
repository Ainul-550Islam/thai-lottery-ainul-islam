<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Exceptions\ChatException;
use App\Models\Chat\ChatConversation;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-IP, per-conversation, and per-operator rate limit enforcement for live chat.
 */
final class ChatRateLimitService
{
    public function ensureConversationAllowed(string $ip): void
    {
        $maxAttempts = max(1, (int) config('chat.rate_limits.start_per_ip_per_hour', 10));
        $key = 'chat:start:ip:'.$ip;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ChatException::rateLimited('start', max(1, RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, 3600);
    }

    public function ensureMessageAllowed(ChatConversation $conversation, ?string $guestTokenHash = null): void
    {
        $maxAttempts = max(1, (int) config('chat.rate_limits.send_per_conversation_per_minute', 30));
        $key = $this->conversationKey((int) $conversation->getKey());

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ChatException::rateLimited('message', max(1, RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, 60);
    }

    public function ensurePollAllowed(string $ip): void
    {
        $maxAttempts = max(1, (int) config('chat.rate_limits.poll_per_ip_per_minute', 240));
        $key = 'chat:poll:ip:'.$ip;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ChatException::rateLimited('poll', max(1, RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, 60);
    }

    public function ensureOperatorActionAllowed(int $userId): void
    {
        $maxAttempts = max(1, (int) config('chat.rate_limits.operator_action_per_minute', 180));
        $key = 'chat:operator:'.$userId;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ChatException::rateLimited('operator', max(1, RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, 60);
    }

    public function forgetConversation(int $conversationId): void
    {
        RateLimiter::clear($this->conversationKey($conversationId));
    }

    private function conversationKey(int $conversationId): string
    {
        return 'chat:send:conversation:'.$conversationId;
    }
}
