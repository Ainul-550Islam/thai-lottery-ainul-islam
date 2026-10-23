<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\Chat\ChatConversationStatus;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatEvent;
use App\Models\Chat\ChatMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Chunked retention sweep for closed chat transcripts, events, and private attachments.
 */
final class ChatRetentionService
{
    /**
     * @return array{conversations: int, messages: int, events: int, attachments: int}
     */
    public function sweepUntilDone(?Carbon $now = null, int $maxSweeps = 20): array
    {
        $now ??= Carbon::now();
        $days = max(1, (int) config('chat.retention.closed_transcript_days', 90));
        $chunkSize = max(1, (int) config('chat.retention.purge_chunk_size', 250));
        $cutoff = $now->copy()->subDays($days);

        $totals = [
            'conversations' => 0,
            'messages' => 0,
            'events' => 0,
            'attachments' => 0,
        ];

        $sweeps = max(1, $maxSweeps);

        for ($i = 0; $i < $sweeps; $i++) {
            $batch = ChatConversation::query()
                ->where('status', ChatConversationStatus::Closed)
                ->whereNotNull('closed_at')
                ->where('closed_at', '<=', $cutoff)
                ->orderBy('id')
                ->limit($chunkSize)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $ids = $batch->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            $attachmentRows = ChatMessage::query()
                ->whereIn('chat_conversation_id', $ids)
                ->whereNotNull('attachments')
                ->pluck('attachments');

            foreach ($attachmentRows as $attachments) {
                if (! is_array($attachments)) {
                    continue;
                }

                foreach ($attachments as $attachment) {
                    if (! is_array($attachment)) {
                        continue;
                    }

                    $disk = (string) ($attachment['disk'] ?? config('chat.attachments.disk', 'local'));
                    $path = (string) ($attachment['path'] ?? '');

                    if ($path !== '' && Storage::disk($disk)->exists($path)) {
                        Storage::disk($disk)->delete($path);
                        $totals['attachments']++;
                    }
                }
            }

            $totals['messages'] += ChatMessage::query()
                ->whereIn('chat_conversation_id', $ids)
                ->delete();

            $totals['events'] += ChatEvent::query()
                ->whereIn('chat_conversation_id', $ids)
                ->delete();

            $totals['conversations'] += ChatConversation::query()
                ->whereIn('id', $ids)
                ->delete();

            if ($batch->count() < $chunkSize) {
                break;
            }
        }

        return $totals;
    }
}
