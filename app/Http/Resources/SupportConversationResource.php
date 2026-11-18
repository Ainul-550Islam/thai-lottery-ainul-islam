<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Chat\ChatConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member's support conversation.
 *
 * ABSENT: guest fields, agent identity, SLA timers, internal close reasons and the
 * raw meta blob. A member needs the state of their own conversation, not the
 * operator's view of it.
 *
 * @mixin ChatConversation
 */
final class SupportConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChatConversation $conversation */
        $conversation = $this->resource;

        $meta = $conversation->meta;

        return [
            'id' => (int) $conversation->getKey(),
            'reference' => $conversation->public_reference,
            'status' => $conversation->status->value,
            'priority' => $conversation->priority?->value,
            'subject' => is_array($meta) ? ($meta['subject'] ?? null) : null,
            'message_count' => (int) ($conversation->message_count ?? 0),
            'unread_count' => (int) ($conversation->visitor_unread_count ?? 0),
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'closed_at' => $conversation->closed_at?->toIso8601String(),
            'rating' => $conversation->getAttribute('rating'),
            'rated_at' => $conversation->getAttribute('rated_at'),
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }
}
