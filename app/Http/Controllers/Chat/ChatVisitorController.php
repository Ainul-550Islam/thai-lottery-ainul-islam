<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\DTOs\Chat\ChatStartData;
use App\Exceptions\ChatException;
use App\Http\Controllers\Controller;
use App\Models\Chat\ChatConversation;
use App\Services\Chat\ChatAvailabilityService;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatIdentityService;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatRateLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The visitor's entire live-chat API.
 *
 * ============================================================================
 * WHY ONE CONTROLLER FOR BOTH GUEST AND MEMBER
 * ============================================================================
 * A visitor may start as a guest and become a member mid-conversation by
 * signing in. If those were two controllers with two route groups, the
 * hand-off would live in the gap between them — exactly where identity bugs
 * hide. One controller, one middleware chain, one authorization helper:
 * the conversation is the subject, and the caller must prove they own it,
 * whichever way they arrived.
 *
 * ============================================================================
 * THE OWNERSHIP RULE IS THE WHOLE SECURITY MODEL
 * ============================================================================
 * Every endpoint except availability and start runs through
 * authorizeVisitor(). It resolves the request to a conversation and then
 * asks the model whether this caller owns it — matching either the signed-in
 * user id OR the hashed guest token. A forged or guessed conversation id
 * therefore produces a 404 (not a 403 — see ChatException::notParticipant),
 * so the endpoint never confirms that an id exists.
 *
 * ============================================================================
 * SHAPE OF EVERY RESPONSE
 * ============================================================================
 * Successes return 200/201 with a JSON envelope carrying the chat payload.
 * Refusals return the status chosen by ChatException with a stable machine
 * code. There is no HTML on this surface: the widget consumes JSON, and a
 * JSON error contract is what lets the widget render the right message for
 * each refusal.
 */
final class ChatVisitorController extends Controller
{
    public function __construct(
        private readonly ChatConversationService $conversations,
        private readonly ChatMessageService $messages,
        private readonly ChatAvailabilityService $availability,
        private readonly ChatIdentityService $identity,
        private readonly ChatRateLimitService $rateLimits,
    ) {}

    /**
     * GET /chat/availability — the widget's first question.
     *
     * Public and unauthenticated BY DESIGN: the widget needs the honest
     * state before it can decide whether to offer the composer, and making
     * that call require anything would defeat its purpose.
     */
    public function availability(): JsonResponse
    {
        // Deliberately NOT assertFeatureEnabled(): the widget needs the
        // honest "disabled" state to render at all, and resolve() already
        // returns it. Refusing here with a 503 would leave the widget with
        // no truthful state to show. Mutating endpoints still refuse.
        return response()->json($this->availability->resolve()->toArray());
    }

    /**
     * GET /chat/widget — minimal config for the launcher.
     *
     * Position, title and greeting keys, and whether the widget belongs on
     * the page at all. Kept separate from availability so a page can render
     * the launcher from cache and poll availability live.
     */
    public function widgetConfig(Request $request): JsonResponse
    {
        // Same reasoning as availability(): the launcher needs to know the
        // feature is off so it can stay hidden, not receive an error.
        $section = $this->pageSection($request);
        $showOn = config('chat.widget.show_on');

        $show = is_array($showOn) ? (bool) ($showOn[$section] ?? false) : false;

        return response()->json([
            'status' => 'OK',
            'enabled' => (bool) config('chat.enabled', true) && $show,
            'position' => (string) config('chat.widget.position', 'bottom-right'),
            'title_key' => (string) config('chat.widget.title_key'),
            'greeting_key' => (string) config('chat.widget.greeting_key'),
            'offline_message_key' => (string) config('chat.widget.offline_message_key'),
            'composer_placeholder_key' => (string) config('chat.widget.composer_placeholder_key'),
        ]);
    }

    /**
     * POST /chat/start — open or resume a conversation.
     *
     * Idempotent by construction: an existing open conversation is resumed,
     * not duplicated, so a double-click or a retry can never fork the
     * visitor into two queues. The response carries the identity cookie when
     * this browser had no token yet — that is the only moment one is issued.
     */
    public function start(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();
        $this->rateLimits->ensureConversationAllowed($request->ip() ?? 'unknown');

        $data = ChatStartData::fromRequest($request, $this->identity);

        $conversation = $this->conversations->open($data);

        // Identity issuance is NOT done here: the EnsureChatGuestToken
        // middleware owns it, so the token that created this conversation is
        // guaranteed to be the one the browser keeps. Issuing here as well
        // would produce a cookie matching nothing.
        return response()->json([
            'status' => 'OK',
            'conversation' => $this->conversationPayload($conversation),
        ], 201);
    }

    /**
     * GET /chat/conversation — the visitor's own open conversation.
     *
     * 404 when there is none: the widget treats "not found" as "show the
     * start form", which is exactly right.
     */
    public function current(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $data = ChatStartData::fromRequest($request, $this->identity);
        $conversation = $this->conversations->findOpenFor($data);

        if (! $conversation instanceof ChatConversation) {
            throw ChatException::conversationNotFound();
        }

        return response()->json([
            'status' => 'OK',
            'conversation' => $this->conversationPayload($conversation),
        ]);
    }

    /**
     * POST /chat/conversation/messages — send a message.
     *
     * Accepts an optional client_token for retry de-duplication; see
     * ChatMessageService::send for why the conversation row is locked for
     * the whole write.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();
        $this->rateLimits->ensurePollAllowed($request->ip() ?? 'unknown');

        $conversation = $this->authorizeVisitor($request);

        $this->rateLimits->ensureMessageAllowed($conversation, $this->identity->tokenHashFromRequest($request));

        $body = (string) $request->input('body', '');
        $clientToken = $request->input('client_token');

        $message = $this->messages->sendFromVisitor(
            conversation: $conversation,
            body: $body,
            visitorUserId: $request->user()?->getAuthIdentifier(),
            displayName: null,
            attachments: [],
            clientToken: is_string($clientToken) && $clientToken !== '' ? $clientToken : null,
        );

        return response()->json([
            'status' => 'OK',
            'message' => $this->messages->toData($message, isAgent: false)->toArray(),
        ], 201);
    }

    /**
     * GET /chat/conversation/poll — incremental read.
     *
     * The cursor is the last message id the client holds; the server returns
     * everything after it. ID cursors (rather than timestamps) cannot skip
     * messages created in the same second.
     */
    public function poll(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();
        $this->rateLimits->ensurePollAllowed($request->ip() ?? 'unknown');

        $conversation = $this->authorizeVisitor($request);

        $afterId = (int) $request->query('after', 0);

        $result = $this->messages->poll($conversation, $afterId, isAgent: false);

        return response()->json([
            'status' => 'OK',
            'poll' => $result->toArray(),
        ]);
    }

    /**
     * GET /chat/conversation/transcript — full history, paginated backward.
     */
    public function transcript(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $conversation = $this->authorizeVisitor($request);

        $beforeId = $request->query('before');
        $limit = $request->query('limit');

        $messages = $this->messages->transcript(
            conversation: $conversation,
            isAgent: false,
            beforeId: is_numeric($beforeId) ? (int) $beforeId : null,
            limit: is_numeric($limit) ? (int) $limit : null,
        );

        return response()->json([
            'status' => 'OK',
            'messages' => array_map(fn ($m) => $m->toArray(), $messages),
        ]);
    }

    /**
     * POST /chat/conversation/read — visitor read receipt.
     *
     * Marks agent messages read and zeroes the visitor's unread counter.
     * Cheap and idempotent, so the widget can call it on every successful
     * poll without harm.
     */
    public function markRead(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $conversation = $this->authorizeVisitor($request);

        $marked = $this->messages->markRead($conversation, isAgent: false);

        return response()->json([
            'status' => 'OK',
            'marked_read' => $marked,
        ]);
    }

    /**
     * POST /chat/conversation/close — visitor ends the chat.
     *
     * Recorded with close_reason = visitor, so the desk can distinguish
     * "visitor left" from "agent resolved". A closed conversation is then
     * refused new messages — see ChatMessageService.
     */
    public function close(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();

        $conversation = $this->authorizeVisitor($request);

        $closed = $this->conversations->close(
            $conversation,
            \App\Enums\Chat\ChatCloseReason::Visitor,
            $request->user(),
        );

        $this->rateLimits->forgetConversation((int) $closed->getKey());

        return response()->json([
            'status' => 'OK',
            'conversation' => $this->conversationPayload($closed),
        ]);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Resolve the request to the caller's conversation and prove ownership.
     *
     * This is the one authorization gate for the whole visitor surface:
     * every mutating or reading endpoint funnels through it, so there is no
     * second place to forget the check.
     */
    private function authorizeVisitor(Request $request): ChatConversation
    {
        $data = ChatStartData::fromRequest($request, $this->identity);

        // findLatestFor (not findOpenFor): a closed conversation must remain
        // readable for its transcript, and a send into it must reach the
        // service's 409 refusal instead of collapsing into a 404.
        $conversation = $this->conversations->findLatestFor($data, lock: false);

        if (! $conversation instanceof ChatConversation) {
            // No conversation matches this identity. Returning 404 (rather
            // than 403) means probing for conversation ids learns nothing.
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

    /**
     * The 503 every endpoint shares when the feature is off.
     */
    private function assertFeatureEnabled(): void
    {
        if (! (bool) config('chat.enabled', true)) {
            throw ChatException::featureDisabled();
        }
    }

    /**
     * The wire shape for a conversation as the visitor sees it.
     *
     * Deliberately small: the visitor needs the reference, the status and
     * who (if anyone) is with them. Internal fields — meta, guest hash,
     * counters for the OTHER side — are not the visitor's business.
     *
     * @return array<string, mixed>
     */
    private function conversationPayload(ChatConversation $conversation): array
    {
        return [
            'reference' => $conversation->public_reference,
            'status' => $conversation->status->value,
            'has_agent' => $conversation->assigned_agent_id !== null,
            'visitor_unread' => (int) $conversation->visitor_unread_count,
            'created_at' => $conversation->created_at?->toIso8601String(),
        ];
    }

    /**
     * Which widget section a page belongs to, from the route name.
     *
     * The widget.show_on config keys off these three buckets; anything that
     * does not resolve defaults to 'public' so an unrouted page still gets
     * the widget rather than silently losing support.
     */
    private function pageSection(Request $request): string
    {
        $routeName = $request->route()?->getName();

        if (! is_string($routeName)) {
            return 'public';
        }

        if (str_starts_with($routeName, 'player.') || str_starts_with($routeName, 'wallet.')) {
            return 'player';
        }

        if (str_starts_with($routeName, 'login') || str_starts_with($routeName, 'register')
            || str_starts_with($routeName, 'auth.') || str_starts_with($routeName, 'password.')) {
            return 'auth';
        }

        return 'public';
    }
}
