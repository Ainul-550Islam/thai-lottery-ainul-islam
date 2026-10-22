<?php

declare(strict_types=1);

namespace App\Http\Controllers\Transfer;

use App\DTOs\Transfer\TransferOutcome;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Exceptions\BalanceTransferException;
use App\Http\Requests\Transfer\StoreBalanceTransferRequest;
use App\Http\Responses\ApiResponse;
use App\Models\BalanceTransfer;
use App\Models\User;
use App\Services\Transfer\BalanceTransferService;
use App\Services\Transfer\TransferLimitService;
use App\Services\Transfer\TransferPanelService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Player-facing balance-transfer endpoints.
 *
 * EVERYTHING IS SCOPED TO THE AUTHENTICATED USER.
 * No endpoint here accepts a user id, a wallet id or a transfer id from the
 * caller as an authority. The wallets are resolved from Auth::user(), and a
 * transfer is looked up by its public reference AND filtered to the caller's own
 * rows, so there is no parameter to tamper with.
 *
 * REFUSALS ARE NOT 500s.
 * BalanceTransferException carries its own HTTP status and machine code, and is
 * rendered from that. A genuine fault (anything else) is logged and surfaced as a
 * 500 with no internals, because a player must never be shown a stack trace and
 * an operator must never lose the detail.
 */
final class BalanceTransferController extends Controller
{
    public function __construct(
        private readonly BalanceTransferService $transfers,
        private readonly TransferLimitService $limits,
        private readonly TransferPanelService $panelService,
    ) {}

    /**
     * Price a transfer without moving money.
     *
     * Accepts GET (a plain preview link) and POST (a JSON client quoting before
     * submitting). Both return the identical shape.
     */
    public function quote(Request $request): JsonResponse
    {
        $user = $this->user();

        if ($user === null) {
            return $this->unauthenticated();
        }

        $direction = TransferDirection::tryFromInput($request->input('direction'));

        if ($direction === null) {
            return ApiResponse::error(
                'transfer_unknown_direction',
                __('transfer.validation.direction_invalid', [
                    'allowed' => implode(', ', TransferDirection::values()),
                ]),
                422,
            );
        }

        $amount = trim((string) $request->input('amount', ''));

        if ($amount === '') {
            return ApiResponse::error(
                'transfer_amount_required',
                __('transfer.validation.amount_required'),
                422,
            );
        }

        try {
            $quote = $this->transfers->quote($user, $direction, $amount);
        } catch (BalanceTransferException $e) {
            return $this->refusal($e);
        } catch (Throwable $e) {
            return $this->fault($e, 'quote', $user, $direction);
        }

        return ApiResponse::success([
            'quote' => $quote->toArray(),
            'allowance' => $this->limits->remainingAllowance((int) $user->getKey(), $direction),
        ]);
    }

    /**
     * Execute a transfer.
     */
    public function store(StoreBalanceTransferRequest $request): JsonResponse
    {
        $user = $this->user();

        if ($user === null) {
            return $this->unauthenticated();
        }

        $direction = $request->direction();

        if ($direction === null) {
            // Unreachable once validation has passed; kept explicit so a future
            // rule change cannot turn this into a null dereference.
            return ApiResponse::error(
                'transfer_unknown_direction',
                __('transfer.validation.direction_invalid', [
                    'allowed' => implode(', ', TransferDirection::values()),
                ]),
                422,
            );
        }

        try {
            $outcome = $this->transfers->transfer(
                user: $user,
                direction: $direction,
                amount: $request->amount(),
                idempotencyKey: $request->idempotencyKey(),
                requestContext: [
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ],
            );
        } catch (BalanceTransferException $e) {
            return $this->refusal($e);
        } catch (Throwable $e) {
            return $this->fault($e, 'store', $user, $direction);
        }

        return $this->outcomeResponse($outcome);
    }

    /**
     * The caller's own transfer history.
     *
     * Content-negotiated: a browser following the panel's "see all transfers" link gets
     * the page, an XHR or API client gets the documented JSON envelope. One query, one
     * set of filters, two renderings - so the two can never disagree about what the
     * player's history contains.
     */
    public function index(Request $request): JsonResponse|Response
    {
        $user = $this->user();

        if ($user === null) {
            return $this->unauthenticated();
        }

        $query = BalanceTransfer::query()
            ->forUser((int) $user->getKey())
            ->latest('id');

        // An unrecognised filter value is IGNORED rather than treated as an empty set.
        // `?status=settled` returning nothing would look to the player like they have no
        // history at all, which is a worse answer than the unfiltered list.
        $direction = TransferDirection::tryFromInput($request->query('direction'));

        if ($direction !== null) {
            $query->inDirection($direction);
        }

        $status = TransferStatus::tryFromValue($request->query('status'));

        if ($status !== null) {
            $query->withStatus($status);
        }

        /** @var LengthAwarePaginator<BalanceTransfer> $page */
        $page = $query->paginate($this->pageSize($request));

        $transfers = collect($page->items())
            ->map(static fn (BalanceTransfer $transfer): array => $transfer->toPlayerArray())
            ->all();

        $meta = [
            'page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
            'filters' => [
                'direction' => $direction?->value,
                'status' => $status?->value,
            ],
        ];

        // Everything a pagination link has to carry forward.
        //
        // Laravel's AbstractPaginator::url() builds a link from appends() ALONE and never
        // from the incoming request query, so whatever is omitted here is silently lost.
        // A player on page 2 of a five-row filtered view would otherwise land on an
        // unfiltered page at the default size - a list of transfers they had explicitly
        // asked not to see, which is the worst possible failure mode for a filter.
        //
        // Built from the RESOLVED enums, not from the raw query string: a value the
        // controller ignored as unrecognised must not be echoed back into a link.
        $activeFilters = array_filter([
            'direction' => $direction?->value,
            'status' => $status?->value,
        ], static fn ($value): bool => is_string($value) && $value !== '');

        // per_page joins the set only when it differs from the configured default, so the
        // ordinary case keeps clean URLs while an explicit page size survives.
        $perPage = $page->perPage();

        if ($perPage !== $this->defaultPageSize()) {
            $activeFilters['per_page'] = (string) $perPage;
        }

        if (! $request->expectsJson()) {
            return response()->view('player.transfers', [
                'transfers' => $transfers,
                'page' => $page,
                'meta' => $meta,
                'activeDirection' => $direction,
                'activeStatus' => $status,
                'activeFilters' => $activeFilters,
                'currency' => (string) config('transfer.currency', 'THB'),
            ]);
        }

        return ApiResponse::success(['transfers' => $transfers], 'OK', 200, $meta);
    }

    /**
     * The requested page size, clamped to a safe range.
     *
     * Bounded on both sides: an unbounded page size is how a listing endpoint becomes a
     * denial of service, and a zero or negative one would be an empty page that reads as
     * an empty history.
     */
    private function pageSize(Request $request): int
    {
        $requested = $request->query('per_page');

        $perPage = is_numeric($requested)
            ? (int) $requested
            : $this->defaultPageSize();

        return min(max($perPage, 1), self::MAX_PAGE_SIZE);
    }

    /**
     * The page size used when the caller did not ask for one.
     *
     * Split out from pageSize() because index() needs it too, to decide whether an
     * explicit per_page is worth putting in a pagination link. Duplicating the config
     * lookup there would let the two drift apart.
     */
    private function defaultPageSize(): int
    {
        $configured = (int) config('transfer.history_per_page', 20);

        return min(max($configured, 1), self::MAX_PAGE_SIZE);
    }

    /**
     * One transfer, by public reference, owned by the caller.
     *
     * A reference belonging to someone else answers 404 with the SAME body as a
     * reference that does not exist. Distinguishing them would turn the reference
     * into an oracle for discovering which transfers exist.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $user = $this->user();

        if ($user === null) {
            return $this->unauthenticated();
        }

        $transfer = BalanceTransfer::query()
            ->forUser((int) $user->getKey())
            ->where('reference_number', $reference)
            ->first();

        if (! $transfer instanceof BalanceTransfer) {
            return ApiResponse::error(
                'transfer_not_found',
                __('transfer.errors.transfer_not_found'),
                404,
            );
        }

        return ApiResponse::success(['transfer' => $transfer->toPlayerArray()]);
    }

    /**
     * The two pockets and the lane rules, for rendering the transfer panel.
     *
     * Delegates to TransferPanelService, which is the SAME implementation the
     * server-rendered wallet page uses, so the JSON and the HTML can never
     * disagree about a player's balance.
     */
    public function panel(Request $request): JsonResponse
    {
        $user = $this->user();

        if ($user === null) {
            return $this->unauthenticated();
        }

        return ApiResponse::success($this->panelService->panelData($user));
    }

    // ------------------------------------------------------------------
    // Responses
    // ------------------------------------------------------------------

    private function outcomeResponse(TransferOutcome $outcome): JsonResponse
    {
        $transfer = $outcome->transfer;

        return ApiResponse::success(
            [
                'transfer' => $transfer->toPlayerArray(),
                // Echoed back so a client that timed out and retried can tell a
                // replay from a fresh move without comparing timestamps.
                'replayed' => $outcome->isReplay(),
                'balances' => [
                    'source_after' => $transfer->source_balance_after,
                    'destination_after' => $transfer->destination_balance_after,
                ],
            ],
            $outcome->isReplay()
                ? __('transfer.messages.already_applied')
                : __('transfer.messages.completed'),
            $outcome->httpStatus(),
            // ApiResponse documents `meta` as the place for non-payload context and
            // names replay flags specifically. A client that only inspects meta can
            // therefore tell a repeat from a fresh move without reaching into data.
            ['replayed' => $outcome->isReplay()],
        );
    }

    /**
     * Render a domain refusal from its own code and status.
     *
     * THE MACHINE CODE IS THE CONTRACT, THE SENTENCE IS PRESENTATION.
     * A client branches on `code`; the human-readable `message` is translated here
     * from lang/<locale>/transfer.php using the exception's own context as the
     * placeholders. The message the exception carries is the English fallback for a
     * code this locale has no sentence for, so a missing translation degrades to
     * readable English rather than to a raw key on a money screen.
     */
    private function refusal(BalanceTransferException $e): JsonResponse
    {
        $headers = [];

        $retryAfter = $e->retryAfter();

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return ApiResponse::error(
            $e->machineCode(),
            // translatedMessage() and publicContext() live on the exception so this
            // controller and the legacy portal cannot drift apart: one vocabulary, one
            // filter, two callers.
            $e->translatedMessage(),
            $e->httpStatus(),
            // publicContext(), not context(): the diagnostic copy carries internal
            // identifiers - a wallet id, which side failed - that a browser has no use
            // for and that are an invitation to start passing them back.
            $e->publicContext(),
            $headers,
        );
    }

    /**
     * Log a genuine fault and return a body with nothing internal in it.
     */
    private function fault(Throwable $e, string $action, User $user, TransferDirection $direction): JsonResponse
    {
        Log::error('transfer.unhandled_fault', [
            'action' => $action,
            'user_id' => (int) $user->getKey(),
            'direction' => $direction->value,
            'exception' => $e::class,
        ]);

        return ApiResponse::error(
            'transfer_unavailable',
            __('transfer.errors.unavailable'),
            503,
        );
    }

    private function unauthenticated(): JsonResponse
    {
        return ApiResponse::error('unauthenticated', __('transfer.errors.unauthenticated'), 401);
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The largest page the history endpoint will serve.
     *
     * A ceiling rather than a default: config supplies what a normal page looks like and
     * this bounds what a caller may ask for. Without it `?per_page=100000` is a request to
     * serialise the player's entire history into one response.
     */
    private const MAX_PAGE_SIZE = 100;
}
