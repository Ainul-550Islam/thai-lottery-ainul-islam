<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\DTOs\Chat\ChatStartData;
use App\Exceptions\ChatException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\UploadChatAttachmentsRequest;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatMessage;
use App\Services\Chat\ChatAttachmentService;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatIdentityService;
use App\Services\Chat\ChatMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Attachment upload and download.
 *
 * ============================================================================
 * UPLOAD = STORE, THEN SEND
 * ============================================================================
 * The visitor uploads files first and gets metadata back; the actual message
 * (with those attachments referenced) is sent through the normal message
 * endpoint afterwards. Two steps rather than one multipart-everything call
 * because:
 *
 *   - an upload that fails validation must not leave a half-sent message,
 *   - the composer can show upload progress without blocking the send
 *     button,
 *   - a message with zero attachments is the overwhelmingly common case and
 *     should not pay multipart-parsing costs.
 *
 * ============================================================================
 * DOWNLOAD = OWNERSHIP CHECK ON EVERY REQUEST
 * ============================================================================
 * The file itself lives on a private disk and is never addressed by URL.
 * This controller re-resolves the requester's right to the conversation on
 * EVERY download — a browser that loses the conversation (closed, expired
 * identity) loses the files too, with no cached link outliving that loss.
 */
final class ChatAttachmentController extends Controller
{
    public function __construct(
        private readonly ChatAttachmentService $attachments,
        private readonly ChatConversationService $conversations,
        private readonly ChatMessageService $messages,
        private readonly ChatIdentityService $identity,
    ) {}

    /**
     * POST /chat/conversation/attachments — upload up to the limit.
     *
     * Returns the stored metadata (public shape only: name, mime, size,
     * index). The client then passes this array into the send call, and the
     * message service persists it with the message.
     */
    public function upload(UploadChatAttachmentsRequest $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $conversation = $this->authorizeVisitor($request);

        $files = $request->attachmentFiles();

        $stored = $this->attachments->storeMany($conversation, $files);

        return response()->json([
            'status' => 'OK',
            'attachments' => array_map(static function (array $attachment): array {
                // The wire copy drops path/disk/sha256: the client needs to
                // display the files, not to know where they live.
                return [
                    'name' => (string) ($attachment['original_name'] ?? 'attachment'),
                    'mime' => (string) ($attachment['mime'] ?? 'application/octet-stream'),
                    'size' => (int) ($attachment['size'] ?? 0),
                ];
            }, $stored),
            // The client must echo this array back unchanged on send.
            'stored' => $stored,
        ], 201);
    }

    /**
     * GET /chat/conversation/{conversation}/messages/{message}/attachments/{index}
     *
     * Streams one attachment to an authorised requester.
     *
     * The index is positional (the Nth attachment on the message) rather
     * than a path or id: clients already know indexes from the message's
     * publicAttachments() list, and an index cannot be guessed into another
     * message's file because the message itself is re-verified below.
     */
    public function download(Request $request, int $conversationId, int $messageId, int $index): StreamedResponse
    {
        $this->assertFeatureEnabled();

        $conversation = ChatConversation::query()->whereKey($conversationId)->first();

        if (! $conversation instanceof ChatConversation) {
            throw ChatException::conversationNotFound();
        }

        // Ownership, same rule as every other visitor endpoint.
        if (! $conversation->isAccessibleBy(
            $request->user()?->getAuthIdentifier(),
            $this->identity->tokenHashFromRequest($request),
        )) {
            throw ChatException::notParticipant($conversationId, $request->user()?->getAuthIdentifier());
        }

        $message = ChatMessage::query()
            ->whereKey($messageId)
            ->where('chat_conversation_id', $conversationId)
            ->first();

        if (! $message instanceof ChatMessage) {
            // A message id from another conversation must not download
            // anything: checking the conversation column above is what makes
            // this airtight.
            throw ChatException::attachmentNotFound($index);
        }

        // Internal notes are never downloadable by the visitor, even though
        // they can never contain one in practice — the double guard costs a
        // line and removes a whole class of future mistake.
        if (! $request->user() && ! $message->isVisibleToVisitor()) {
            throw ChatException::attachmentNotFound($index);
        }

        $resolved = $this->attachments->resolveForDownload($message, $index);

        $disk = $resolved['disk'];
        $path = $resolved['path'];
        $mime = $resolved['mime'];
        $originalName = $resolved['original_name'];

        // Streamed, not read-into-memory: a 5 MB scan must not cost 5 MB of
        // PHP heap per download. Content-Disposition is attachment (never
        // inline) so a browser cannot be tricked into rendering a stored
        // HTML/SVG as part of this origin.
        return $disk->download($path, $originalName, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ========================================================================

    private function assertFeatureEnabled(): void
    {
        if (! (bool) config('chat.enabled', true)) {
            throw ChatException::featureDisabled();
        }
    }

    private function authorizeVisitor(Request $request): ChatConversation
    {
        $data = ChatStartData::fromRequest($request, $this->identity);

        $conversation = $this->conversations->findOpenFor($data);

        if (! $conversation instanceof ChatConversation) {
            throw ChatException::conversationNotFound();
        }

        if (! $conversation->isAccessibleBy(
            $request->user()?->getAuthIdentifier(),
            $this->identity->tokenHashFromRequest($request),
        )) {
            throw ChatException::notParticipant(
                (int) $conversation->getKey(),
                $request->user()?->getAuthIdentifier(),
            );
        }

        return $conversation;
    }
}
