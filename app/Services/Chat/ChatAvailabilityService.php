<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\DTOs\Chat\ChatAvailabilityData;
use App\Models\Chat\ChatConversation;
use Illuminate\Support\Carbon;

/**
 * Resolves live-chat availability from observed operator heartbeats and queue depth.
 */
final class ChatAvailabilityService
{
    public function __construct(
        private readonly ChatPresenceService $presence,
    ) {}

    public function resolve(?Carbon $now = null): ChatAvailabilityData
    {
        if (! (bool) config('chat.enabled', true)) {
            return ChatAvailabilityData::disabled();
        }

        $now ??= Carbon::now();
        $waiting = ChatConversation::query()->waiting()->count();
        $onlineAgents = $this->presence->onlineAgentCount($now);

        if ($onlineAgents <= 0) {
            return ChatAvailabilityData::offline($waiting);
        }

        $availableAgents = $this->presence->availableAgentCount($now);

        if ($availableAgents > 0) {
            return new ChatAvailabilityData(
                state: ChatAvailabilityData::STATE_AVAILABLE,
                onlineAgents: $onlineAgents,
                waitingConversations: $waiting,
                estimatedWaitSeconds: null,
                messageKey: 'chat.availability_available',
                canStartConversation: true,
                offersTicketFallback: false,
            );
        }

        return new ChatAvailabilityData(
            state: ChatAvailabilityData::STATE_BUSY,
            onlineAgents: $onlineAgents,
            waitingConversations: $waiting,
            estimatedWaitSeconds: null,
            messageKey: 'chat.availability_busy',
            canStartConversation: true,
            offersTicketFallback: true,
        );
    }
}
