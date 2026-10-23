<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\Chat\ChatAgentStatus;
use App\Models\Chat\ChatAgentPresence;
use App\Models\Chat\ChatConversation;
use Illuminate\Support\Carbon;

/**
 * Operator heartbeat and availability tracking.
 */
final class ChatPresenceService
{
    public function heartbeat(int $userId, ?string $currentPage = null): ChatAgentPresence
    {
        $now = Carbon::now();

        /** @var ChatAgentPresence $presence */
        $presence = ChatAgentPresence::query()->firstOrNew(['user_id' => $userId]);

        if (! $presence->exists) {
            $presence->accepts_new_conversations = true;
            $presence->session_started_at = $now;
        } elseif ($presence->session_started_at === null) {
            $presence->session_started_at = $now;
        }

        if ($currentPage !== null) {
            $presence->current_page = $currentPage;
        }

        $presence->last_heartbeat_at = $now;
        $presence->status = ChatAgentStatus::Online;
        $presence->save();

        return $presence;
    }

    public function signOff(int $userId, bool $pause = false): void
    {
        /** @var ChatAgentPresence $presence */
        $presence = ChatAgentPresence::query()->firstOrNew(['user_id' => $userId]);
        $presence->accepts_new_conversations = false;

        if (! $pause) {
            $presence->status = ChatAgentStatus::Offline;
            $presence->last_heartbeat_at = null;
        } else {
            $presence->last_heartbeat_at = Carbon::now();
            $presence->status = ChatAgentStatus::Online;
        }

        $presence->save();
    }

    public function resumeIntake(int $userId): void
    {
        $now = Carbon::now();

        /** @var ChatAgentPresence $presence */
        $presence = ChatAgentPresence::query()->firstOrNew(['user_id' => $userId]);
        $presence->accepts_new_conversations = true;
        $presence->last_heartbeat_at = $now;
        $presence->session_started_at ??= $now;
        $presence->status = ChatAgentStatus::Online;
        $presence->save();
    }

    public function onlineAgentCount(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        return ChatAgentPresence::query()->online($now)->count();
    }

    public function availableAgentCount(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $defaultMax = max(1, (int) config('chat.queue.max_concurrent_per_agent', 8));

        $onlineRows = ChatAgentPresence::query()
            ->online($now)
            ->where('accepts_new_conversations', true)
            ->get();

        $available = 0;
        foreach ($onlineRows as $row) {
            $cap = $row->max_conversations !== null && $row->max_conversations > 0
                ? (int) $row->max_conversations
                : $defaultMax;
            $activeCount = ChatConversation::query()
                ->active()
                ->where('assigned_agent_id', $row->user_id)
                ->count();

            if ($activeCount < $cap) {
                $available++;
            }
        }

        return $available;
    }

    /**
     * @return array{online_agents: int, available_agents: int, waiting_conversations: int, active_conversations: int}
     */
    public function summary(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return [
            'online_agents' => $this->onlineAgentCount($now),
            'available_agents' => $this->availableAgentCount($now),
            'waiting_conversations' => ChatConversation::query()->waiting()->count(),
            'active_conversations' => ChatConversation::query()->active()->count(),
        ];
    }
}
