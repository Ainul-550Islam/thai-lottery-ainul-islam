<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

use App\Enums\Chat\ChatMessageKind;
use App\Enums\Chat\ChatSenderType;

/**
 * One message as it crosses a boundary: into the service, or out to a client.
 *
 * ============================================================================
 * THE SAME SHAPE ON BOTH SIDES, ON PURPOSE
 * ============================================================================
 * Inbound (a visitor typing) and outbound (a poll response) carry the same
 * fields, so the client can render a message from the poll payload with the
 * same code it uses to render an optimistic local echo. One shape means one
 * place for a rendering bug to live instead of two.
 *
 * The `index` on attachments is the OPAQUE handle the client sends to the
 * download endpoint. Paths and disk names are deliberately absent — see
 * ChatMessage::publicAttachments().
 *
 * ============================================================================
 * @phpstan-type ChatAttachmentPayload array<int, array{index: int, name: string, mime: string, size: int}>
 */
final readonly class ChatMessageData
{
    /**
     * @param  ChatAttachmentPayload  $attachments
     */
    public function __construct(
        /**
         * Database id, or null for a message that has not been persisted yet.
         */
        public ?int $id,

        /**
         * Which side wrote it. Derived from the authenticated context by the
         * service — never taken from client input, or a visitor could post a
         * message that renders as though an agent wrote it.
         */
        public ChatSenderType $senderType,

        /**
         * The account id, when the sender had one.
         */
        public ?int $senderUserId,

        /**
         * Display name captured AT SEND TIME.
         */
        public ?string $senderDisplayName,

        public ChatMessageKind $kind,

        /**
         * The text. Already trimmed and length-checked by the service that
         * built this object.
         */
        public string $body,

        /**
         * Opaque attachment handles; empty when none.
         *
         * @var ChatAttachmentPayload
         */
        public array $attachments = [],

        /**
         * ISO-8601 timestamp for display, or null when not yet persisted.
         */
        public ?string $createdAtIso = null,

        /**
         * Whether the OTHER side has read it. Drives the read receipt.
         */
        public bool $isRead = false,

        /**
         * Client-supplied de-duplication token, echoed back so a retrying
         * client can tell "my message landed" from "somebody else's arrived".
         */
        public ?string $clientToken = null,
    ) {}

    /**
     * The wire format sent to a browser.
     *
     * Keys here ARE the API. Renaming one is a breaking change for any
     * deployment that has customised the widget, so they are written
     * explicitly rather than serialising the object and letting the property
     * names leak out as the contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sender' => $this->senderType->value,
            'sender_user_id' => $this->senderUserId,
            'sender_display_name' => $this->senderDisplayName,
            'kind' => $this->kind->value,
            'body' => $this->body,
            'attachments' => $this->attachments,
            'created_at' => $this->createdAtIso,
            'is_read' => $this->isRead,
            'client_token' => $this->clientToken,
        ];
    }

    /**
     * Is this a system line rather than a person speaking?
     */
    public function isSystem(): bool
    {
        return $this->kind === ChatMessageKind::System;
    }

    /**
     * May the visitor be shown this message?
     *
     * Delegates to the enum so the one rule has one home. A DTO that
     * answered this question itself would be a second place for the
     * internal-note rule to be got wrong.
     */
    public function isVisibleToVisitor(): bool
    {
        return $this->kind->isVisibleToVisitor();
    }
}
