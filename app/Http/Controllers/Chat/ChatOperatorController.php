<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Enums\Chat\ChatCloseReason;
use App\Enums\Chat\ChatPriority;
use App\Exceptions\ChatException;
use App\Http\Controllers\Controller;
use App\Models\Chat\ChatConversation;
use App\Models\User;
use App\Services\Chat\ChatCannedResponseService;
use App\Services\Chat\ChatConversationService;
use App\Services\Chat\ChatHealthService;
use App\Services\Chat\ChatMessageService;
use App\Services\Chat\ChatPresenceService;
use App\Services\Chat\ChatQueueService;
use App\Services\Chat\ChatRateLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The operator console API.
 *
 * ============================================================================
 * AUTHORIZATION MODEL
 * ============================================================================
 * Every endpoint requires an authenticated user with the matching chat
 * permission (chat.view / chat.reply / chat.assign / chat.close /
 * chat.manage_canned, see ChatConversationPolicy). The `access-admin` gate
 * is applied at the route level, so nothing here can be reached by a
 * player account even if a permission were mis-granted.
 *
 * ============================================================================
 * THE CONSOLE IS A POLLING CLIENT TOO
 * ============================================================================
 * The operator's browser polls exactly like the visitor's widget, just
 * against the operator endpoints and at the operator cadence. That symmetry
 * is deliberate: one transport, one set of cursor semantics, one set of
 * rate limits — and the console gets its heartbeat in on every poll, so an
 * operator who is watching the queue is provably present.
 */
final class ChatOperatorController extends Controller
{
    public function __construct(
        private readonly ChatQueueService $queue,
        private readonly ChatConversationService $conversations,
        private readonly ChatMessageService $messages,
        private readonly ChatPresenceService $presence,
        private readonly ChatCannedResponseService $canned,
        private readonly ChatHealthService $health,
        private readonly ChatRateLimitService $rateLimits,
    ) {}

    /**
     * GET /operator/chat — the console page itself.
     *
     * Renders only the SHELL: every number arrives through the JSON API
     * below, so this view carries no data that could go stale and no query
     * that a future endpoint could drift from. The permission gate is the
     * same one every API endpoint applies.
     */
    public function console(Request $request): \Illuminate\View\View
    {
        $this->authorizeChatAbility($request, 'view');

        return view('chat.operator');
    }

    /**
     * GET /operator/chat/dashboard — the console's one-stop read.
     *
     * Queue page, the operator's workload, presence summary and health
     * warnings in a single response, so the console renders its shell with
     * one request and then polls the lighter endpoints for updates.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $this->authorizeChatAbility($request, 'view');

        $user = $this->operator($request);
        $this->presence->heartbeat((int) $user->getAuthIdentifier());

        $perPage = (int) $request->query('per_page', (int) config('chat.operator.queue_page_size', 25));

        return response()->json([
            'status' => 'OK',
            'queue' => $this->queue->waitingQueue($perPage)->items(),
            'workload' => $this->queue->workloadFor((int) $user->getAuthIdentifier()),
            'presence' => $this->presence->summary(),
            'health_warnings' => $this->health->collectWarnings(now()),
            'next_poll_seconds' => (int) config('chat.poll.operator_interval_seconds', 5),
        ]);
    }

    /**
     * POST /operator/chat/poll — operator-side incremental read.
     *
     * Doubles as the heartbeat: an operator who can poll is an operator who
     * is at the console, so presence is refreshed on the same request rather
     * than by a second round trip.
     */
    public function poll(Request $request, int $conversationId): JsonResponse
    {
        $this->authorizeChatAbility($request, 'view');
        $this->rateLimits->ensurePollAllowed($request->ip() ?? 'unknown');

        $user = $this->operator($request);
        $this->presence->heartbeat((int) $user->getAuthIdentifier());

        $conversation = $this->findConversation($conversationId);

        $afterId = (int) $request->query('after', 0);
        $result = $this->messages->poll($conversation, $afterId, isAgent: true);

        return response()->json([
            'status' => 'OK',
            'poll' => $result->toArray(),
        ]);
    }

    /**
     * POST /operator/chat/take — claim the next waiting conversation.
     *
     * Returns the claimed conversation, or a 204-ish empty queue answer so the
     * console can say "nothing waiting" instead of spinning.
     */
    public function takeNext(Request $request): JsonResponse
    {
        $this->authorizeChatAbility($request, 'claim');
        $this->operatorAction($request);

        $user = $this->operator($request);

        $conversation = $this->queue->takeNext((int) $user->getAuthIdentifier());

        if (! $conversation instanceof ChatConversation) {
            return response()->json([
                'status' => 'EMPTY_QUEUE',
                'code' => 'chat_queue_empty',
            ]);
        }

        return response()->json([
            'status' => 'OK',
            'conversation' => $conversation,
        ]);
    }

    /**
     * POST /operator/chat/{conversation}/take — claim a specific one.
     */
    public function takeSpecific(Request $request, int $conversationId): JsonResponse
    {
        $this->authorizeChatAbility($request, 'claim');
        $this->operatorAction($request);

        $user = $this->operator($request);

        $conversation = $this->queue->takeSpecific(
            (int) $user->getAuthIdentifier(),
            $conversationId,
        );

        return response()->json([
            'status' => 'OK',
            'conversation' => $conversation,
        ]);
    }

    /**
     * POST /operator/chat/{conversation}/message — reply or note.
     *
     * `kind=note` writes an internal note (never visible to the visitor);
     * anything else is a visible reply. The distinction matters enough that
     * the console renders the two in different colours — see the service
     * layer for why a note can never count as a first response.
     */
    public function sendMessage(Request $request, int $conversationId): JsonResponse
    {
        $this->operatorAction($request);

        $user = $this->operator($request);
        $conversation = $this->findConversation($conversationId);
        $this->authorizeOn($request, 'reply', $conversation);

        $body = (string) $request->input('body', '');
        $kind = (string) $request->input('kind', 'text');

        $message = $kind === 'note'
            ? $this->messages->addNote($conversation, $body, $user)
            : $this->messages->sendFromAgent($conversation, $body, $user);

        return response()->json([
            'status' => 'OK',
            'message' => $this->messages->toData($message, isAgent: true)->toArray(),
        ], 201);
    }

    /**
     * POST /operator/chat/{conversation}/read — agent read receipt.
     */
    public function markRead(Request $request, int $conversationId): JsonResponse
    {
        $conversation = $this->findConversation($conversationId);
        $this->authorizeOn($request, 'reply', $conversation);

        $marked = $this->messages->markRead($conversation, isAgent: true);

        return response()->json([
            'status' => 'OK',
            'marked_read' => $marked,
        ]);
    }

    /**
     * POST /operator/chat/{conversation}/assign — hand to another agent.
     */
    public function assign(Request $request, int $conversationId): JsonResponse
    {
        $this->operatorAction($request);

        $user = $this->operator($request);
        $conversation = $this->findConversation($conversationId);
        $this->authorizeOn($request, 'assign', $conversation);

        $targetUserId = (int) $request->input('agent_user_id');

        if ($targetUserId <= 0) {
            throw ChatException::agentNotAvailable(0);
        }

        $assigned = $this->conversations->assign($conversation, $targetUserId, $user);

        return response()->json([
            'status' => 'OK',
            'conversation' => $assigned,
        ]);
    }

    /**
     * POST /operator/chat/{conversation}/release — back to the queue.
     */
    public function release(Request $request, int $conversationId): JsonResponse
    {
        $this->operatorAction($request);

        $user = $this->operator($request);
        $conversation = $this->findConversation($conversationId);
        $this->authorizeOn($request, 'assign', $conversation);

        $released = $this->conversations->release($conversation, $user);

        return response()->json([
            'status' => 'OK',
            'conversation' => $released,
        ]);
    }

    /**
     * POST /operator/chat/{conversation}/close — end the conversation.
     */
    public function close(Request $request, int $conversationId): JsonResponse
    {
        $this->operatorAction($request);

        $user = $this->operator($request);
        $conversation = $this->findConversation($conversationId);
        $this->authorizeOn($request, 'close', $conversation);

        $reasonInput = (string) $request->input('reason', ChatCloseReason::Agent->value);
        $reason = ChatCloseReason::tryFrom($reasonInput);

        if (! $reason instanceof ChatCloseReason || $reason === ChatCloseReason::Idle) {
            // Idle is reserved for the automated job; an operator closing a
            // chat by hand is an Agent close whatever button label they used.
            $reason = ChatCloseReason::Agent;
        }

        $closed = $this->conversations->close($conversation, $reason, $user);

        $this->rateLimits->forgetConversation((int) $closed->getKey());

        return response()->json([
            'status' => 'OK',
            'conversation' => $closed,
        ]);
    }

    /**
     * POST /operator/chat/{conversation}/priority — raise or lower.
     */
    public function setPriority(Request $request, int $conversationId): JsonResponse
    {
        $this->operatorAction($request);

        $user = $this->operator($request);
        $conversation = $this->findConversation($conversationId);
        $this->authorizeOn($request, 'assign', $conversation);

        $priority = ChatPriority::tryFrom((string) $request->input('priority'));

        if (! $priority instanceof ChatPriority) {
            throw ChatException::invalidPriority((string) $request->input('priority'));
        }

        $updated = $this->conversations->setPriority($conversation, $priority, $user);

        return response()->json([
            'status' => 'OK',
            'conversation' => $updated,
        ]);
    }

    /**
     * GET /operator/chat/canned — the picker list.
     */
    public function cannedIndex(Request $request): JsonResponse
    {
        $this->authorizeChatAbility($request, 'view');

        return response()->json([
            'status' => 'OK',
            // The operator's own negotiated locale (SetLocale), not a literal 'en'
            // and not the query string: the picker is fetched with no query at all,
            // so this used to hand every Thai or Bangla operator the English list
            // even when translated replies existed.
            'groups' => $this->canned->forPicker((string) app()->getLocale()),
        ]);
    }

    /**
     * POST /operator/chat/pause — stop accepting new conversations.
     */
    public function pause(Request $request): JsonResponse
    {
        $this->authorizeChatAbility($request, 'view');

        $user = $this->operator($request);
        $this->presence->signOff((int) $user->getAuthIdentifier(), pause: true);

        return response()->json(['status' => 'OK', 'accepting' => false]);
    }

    /**
     * POST /operator/chat/resume — accept new conversations again.
     */
    public function resume(Request $request): JsonResponse
    {
        $this->authorizeChatAbility($request, 'view');

        $user = $this->operator($request);
        $this->presence->resumeIntake((int) $user->getAuthIdentifier());

        return response()->json(['status' => 'OK', 'accepting' => true]);
    }

    /**
     * GET /operator/chat/health — the desk health report.
     */
    public function health(Request $request): JsonResponse
    {
        $this->authorizeChatAbility($request, 'view');

        return response()->json([
            'status' => 'OK',
            'report' => $this->health->report(),
        ]);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function operator(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Unreachable behind the auth middleware, but the guard makes
            // the contract explicit rather than assumed.
            throw ChatException::notParticipant(0, null);
        }

        return $user;
    }

    /**
     * Authorization for abilities that do not act on a specific conversation
     * (viewing the queue, claiming from it).
     *
     * @param  'view'|'claim'|'manage_canned'  $ability
     */
    private function authorizeChatAbility(Request $request, string $ability): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ChatException::notParticipant(0, null);
        }

        if (! $user->can($ability, ChatConversation::class)) {
            throw ChatException::notParticipant(0, (int) $user->getAuthIdentifier());
        }
    }

    /**
     * Authorization for abilities that act on one specific conversation.
     *
     * The instance is passed to the policy, which is what lets reply/assign/
     * close see the conversation's state (a closed conversation refuses
     * reply, see the policy).
     *
     * @param  'reply'|'assign'|'close'  $ability
     */
    private function authorizeOn(Request $request, string $ability, ChatConversation $conversation): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw ChatException::notParticipant((int) $conversation->getKey(), null);
        }

        if (! $user->can($ability, $conversation)) {
            throw ChatException::notParticipant(
                (int) $conversation->getKey(),
                (int) $user->getAuthIdentifier(),
            );
        }
    }

    private function operatorAction(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->rateLimits->ensureOperatorActionAllowed((int) $user->getAuthIdentifier());
        }
    }

    private function findConversation(int $conversationId): ChatConversation
    {
        $conversation = ChatConversation::query()->whereKey($conversationId)->first();

        if (! $conversation instanceof ChatConversation) {
            throw ChatException::conversationNotFound();
        }

        return $conversation;
    }
}
