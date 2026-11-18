<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Chat\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One support message, as the member sees it.
 *
 * Internal notes never reach this class: the query that loads the messages uses
 * ChatConversation::visibleMessages(), whose filter is
 * ChatMessageKind::isVisibleToVisitor(). If a note ever appeared here it would mean
 * that relation was bypassed, which is why this resource does not filter by kind
 * itself — a second filter would mask the bug.
 *
 * @mixin ChatMessage
 */
final class SupportMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChatMessage $message */
        $message = $this->resource;

        return [
            'id' => (int) $message->getKey(),
            'sender_type' => $message->sender_type?->value ?? (string) $message->sender_type,
            'kind' => $message->kind?->value ?? (string) $message->kind,
            'body' => (string) $message->body,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
