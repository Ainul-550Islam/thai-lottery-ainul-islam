<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\OpenSupportConversationRequest;
use App\Http\Requests\Api\V1\RateSupportConversationRequest;
use App\Http\Requests\Api\V1\SendSupportMessageRequest;
use App\Http\Resources\SupportConversationResource;
use App\Http\Resources\SupportMessageResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Support\SupportConversationService;
use App\Services\Support\SupportMessageService;
use App\Services\Support\SupportRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member's support conversations, on the existing chat stack.
 *
 * No new inbox and no new tables: these endpoints are a member-scoped view of
 * chat_conversations / chat_messages, with the ownership check in the service so
 * every method here inherits it.
 */
final class SupportConversationController
{
    public function __construct(
        private readonly SupportConversationService $conversations,
        private readonly SupportMessageService $messages,
    ) {
    }

    /**
     * GET /me/support/conversations
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $rows = $this->conversations->listFor((int) $user->id, (int) ($validated['limit'] ?? 20));

        return ApiResponse::success(
            ['conversations' => SupportConversationResource::collection($rows)->resolve()],
            'Conversations loaded.',
        );
    }

    /**
     * POST /me/support/conversations
     */
    public function store(OpenSupportConversationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        try {
            $conversation = $this->conversations->open(
                (int) $user->id,
                (string) $validated['subject'],
                $validated['message'] ?? null,
            );

            if (isset($validated['message']) && is_string($validated['message']) && trim($validated['message']) !== '') {
                $this->messages->send((int) $user->id, (string) $conversation->getKey(), (string) $validated['message']);
            }
        } catch (SupportRefused $e) {
            return ApiResponse::error($e->reasonCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success(
            ['conversation' => (new SupportConversationResource($conversation->fresh() ?? $conversation))->resolve()],
            'Conversation opened.',
            201,
        );
    }

    /**
     * GET /me/support/conversations/{id}/messages
     */
    public function messages(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $rows = $this->messages->listFor((int) $user->id, $id, (int) ($validated['after_id'] ?? 0));
            $this->messages->markRead((int) $user->id, $id);
        } catch (SupportRefused $e) {
            return ApiResponse::error($e->reasonCode, $e->getMessage(), $e->status);
        }

        // The service returned only what visibleMessages() allowed through, so this
        // is a projection, not a filter. SupportMessageResource asserts nothing.
        return ApiResponse::success(
            ['messages' => SupportMessageResource::collection($rows)->resolve()],
            'Messages loaded.',
        );
    }

    /**
     * POST /me/support/conversations/{id}/messages
     */
    public function sendMessage(SendSupportMessageRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        try {
            $message = $this->messages->send(
                (int) $user->id,
                $id,
                (string) $validated['body'],
                $validated['client_token'] ?? null,
            );
        } catch (SupportRefused $e) {
            return ApiResponse::error($e->reasonCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success(
            ['message' => (new SupportMessageResource($message))->resolve()],
            'Message sent.',
            201,
        );
    }

    /**
     * POST /me/support/conversations/{id}/rating
     */
    public function rate(RateSupportConversationRequest $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        try {
            $conversation = $this->messages->rate(
                (int) $user->id,
                $id,
                (int) $validated['rating'],
                $validated['comment'] ?? null,
            );
        } catch (SupportRefused $e) {
            return ApiResponse::error($e->reasonCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success(
            ['conversation' => (new SupportConversationResource($conversation))->resolve()],
            'Rating recorded.',
        );
    }
}
