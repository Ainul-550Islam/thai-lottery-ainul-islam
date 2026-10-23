<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Chat\ChatConversation;
use Illuminate\Support\Carbon;

/**
 * Operational health checks and warning aggregation for the live-chat subsystem.
 */
final class ChatHealthService
{
    public function __construct(
        private readonly ChatPresenceService $presence,
    ) {}

    /**
     * @return list<string>
     */
    public function collectWarnings(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $warnings = [];

        if (! (bool) config('chat.enabled', true)) {
            $warnings[] = 'Live chat is currently disabled by configuration.';
        }

        $summary = $this->presence->summary($now);
        $waiting = (int) $summary['waiting_conversations'];
        $online = (int) $summary['online_agents'];

        if ($waiting > 0 && $online === 0) {
            $warnings[] = "There are {$waiting} waiting conversations with 0 online operators.";
        }

        if ($waiting >= 25) {
            $warnings[] = "High waiting queue backlog: {$waiting} conversations waiting.";
        }

        $firstResponseTarget = max(1, (int) config('chat.queue.first_response_target_seconds', 120));
        $overdueWaiting = ChatConversation::query()
            ->waiting()
            ->whereNull('first_response_at')
            ->where('queued_at', '<=', $now->copy()->subSeconds($firstResponseTarget))
            ->count();

        if ($overdueWaiting > 0) {
            $warnings[] = "{$overdueWaiting} waiting conversation(s) have exceeded the first-response SLA target.";
        }

        if ((string) config('chat.attachments.disk', 'local') === 'public') {
            $warnings[] = 'Chat attachments disk is set to public; private storage is required.';
        }

        return $warnings;
    }

    /**
     * @return array{healthy: bool, enabled: bool, warnings: list<string>, presence: array<string, int>}
     */
    public function report(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $warnings = $this->collectWarnings($now);

        return [
            'healthy' => $warnings === [],
            'enabled' => (bool) config('chat.enabled', true),
            'warnings' => $warnings,
            'presence' => $this->presence->summary($now),
        ];
    }
}
