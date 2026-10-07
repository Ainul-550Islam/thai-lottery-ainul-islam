<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\GloDealerRequestType;
use App\Enums\GloSourceState;
use App\Exceptions\GloClaimException;
use App\Exceptions\GloDealerException;
use App\Exceptions\GloFreezeException;
use App\Http\Responses\ApiResponse;
use App\Models\Draw;
use App\Models\GloDealerChangeRequest;
use App\Models\GloNotificationDelivery;
use App\Models\GloPrizeClaim;
use App\Models\GloTicket;
use App\Models\GloTicketFreeze;
use App\Services\Lottery\GloDataMatrixParser;
use App\Services\Lottery\GloDealerChangeRequestService;
use App\Services\Lottery\GloDealerService;
use App\Services\Lottery\GloLiveDrawService;
use App\Services\Lottery\GloPrizeCatalogue;
use App\Services\Lottery\GloPrizeClaimService;
use App\Services\Lottery\GloPublicResultService;
use App\Services\Lottery\GloPublicTicketVerificationService;
use App\Services\Lottery\GloResultNotificationService;
use App\Services\Lottery\GloResultService;
use App\Services\Lottery\GloSalesPointService;
use App\Services\Lottery\GloSavedTicketService;
use App\Services\Lottery\GloTicketChecker;
use App\Services\Lottery\GloTicketFreezeService;
use App\Support\Admin\AdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/*
 * GLO API surface: read-only catalogue/result/check (pre-existing) plus the
 * GLO-11/12/14 freeze and claim mutations and the GLO-13 public status route.
 *
 * Authorization: mutations sit behind auth:sanctum + glo.permission middleware
 * (default deny). Service methods re-check freeze/hold/age/settlement gates —
 * the controller never trusts body fields for status, money, age or hold flags.
 */
class GloController
{
    public function __construct(
        private readonly GloPrizeCatalogue $catalogue,
        private readonly GloResultService $results,
        private readonly GloTicketChecker $checker,
        private readonly GloTicketFreezeService $freezes,
        private readonly GloPrizeClaimService $claims,
        private readonly GloPublicTicketVerificationService $publicStatus,
        private readonly GloPublicResultService $publicResults,
        private readonly GloSalesPointService $salesPoints,
        private readonly GloSavedTicketService $savedTickets,
        private readonly GloDealerService $dealers,
        private readonly GloDealerChangeRequestService $changeRequests,
        private readonly GloResultNotificationService $resultNotifications,
        private readonly GloDataMatrixParser $dataMatrix,
        private readonly GloLiveDrawService $liveDraw,
    ) {}

    public function prizes(): JsonResponse
    {
        return ApiResponse::success($this->catalogue->all(), 'GLO official prize structure');
    }

    public function draw(Request $request, string $draw): JsonResponse
    {
        $model = ctype_digit($draw)
            ? Draw::query()->find((int) $draw)
            : Draw::query()->where('draw_number', $draw)->first();

        if ($model === null) {
            return ApiResponse::error('not_found', 'Draw not found', 404);
        }

        return ApiResponse::success(
            $this->results->recordedForDraw((int) $model->getKey()),
            'GLO recorded official numbers for draw',
        );
    }

    public function checkTicket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'draw_id' => ['required', 'integer', 'min:1'],
            'ticket_number' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $draw = Draw::query()->find((int) $validated['draw_id']);

        if ($draw === null) {
            return ApiResponse::error('not_found', 'Draw not found', 404);
        }

        try {
            $result = $this->checker->check((int) $draw->getKey(), (string) $validated['ticket_number']);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error('invalid_ticket', $e->getMessage(), 422);
        }

        return ApiResponse::success($result, 'Ticket checked against official numbers (informational only)');
    }

    /*
    |--------------------------------------------------------------------------
    | GLO-11 Freeze mutations
    |--------------------------------------------------------------------------
    */

    public function requestFreeze(Request $request, string $ticket): JsonResponse
    {
        $user = $request->user();

        if (! AdminAccess::allows($user, AdminAccess::REQUEST_GLO_FREEZES)) {
            return ApiResponse::error('glo_forbidden', 'Missing freeze request permission.', 403);
        }

        $validated = $request->validate([
            'draw_id' => ['required', 'integer', 'min:1'],
            'product' => ['required', 'string', 'in:l6,n3'],
            'ticket_number' => ['required', 'string', 'regex:/^\d{3,6}$/'],
            'set_series' => ['nullable', 'string', 'max:32'],
            'requesting_authority' => ['required', 'string', 'max:255'],
            'jurisdiction' => ['required', 'string', 'max:128'],
            'case_reference' => ['required', 'string', 'max:128'],
            'legal_reference_number' => ['nullable', 'string', 'max:128'],
            'evidence_reference' => ['required', 'string', 'max:128'],
            'evidence_type' => ['nullable', 'string', 'max:64'],
            'evidence_document_id' => ['nullable', 'string', 'max:64'],
            'evidence_content_hash' => ['nullable', 'string', 'max:64'],
            'evidence_mime_type' => ['nullable', 'string', 'max:128'],
            'evidence_submission_metadata' => ['nullable', 'array'],
            'evidence_received_at' => ['nullable', 'date'],
            'expiry_at' => ['nullable', 'date'],
        ]);

        // Route ticket must match body ticket identity (exact match, no mass freeze).
        $routeTicket = $this->resolveRouteTicket($ticket);

        if ($routeTicket !== null) {
            if ((int) $routeTicket->draw_id !== (int) $validated['draw_id']
                || $routeTicket->ticket_number !== $validated['ticket_number']
                || $routeTicket->product !== $validated['product']) {
                return ApiResponse::error('invalid_ticket', 'Route ticket and body ticket identity do not match.', 422);
            }
        }

        try {
            $freeze = $this->freezes->requestFreeze($validated, $user);
        } catch (GloFreezeException $e) {
            return ApiResponse::error('glo_freeze_rejected', $e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return ApiResponse::success($this->freezePayload($freeze), 'Freeze request recorded', 201);
    }

    public function reviewFreeze(Request $request, string $freeze): JsonResponse
    {
        return $this->freezeTransition($request, $freeze, 'review');
    }

    public function approveFreeze(Request $request, string $freeze): JsonResponse
    {
        return $this->freezeTransition($request, $freeze, 'approve');
    }

    public function rejectFreeze(Request $request, string $freeze): JsonResponse
    {
        return $this->freezeTransition($request, $freeze, 'reject');
    }

    public function releaseFreeze(Request $request, string $freeze): JsonResponse
    {
        return $this->freezeTransition($request, $freeze, 'release');
    }

    /*
    |--------------------------------------------------------------------------
    | GLO-12/14 Claim mutations
    |--------------------------------------------------------------------------
    */

    public function submitClaim(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || ! $user->isActive()) {
            return ApiResponse::error('unauthorized', 'Active claimant session required.', 401);
        }

        $validated = $request->validate([
            'ticket_id' => ['required', 'integer', 'min:1'],
            'prize_category' => ['required', 'string', 'max:32'],
            'claim_channel' => ['required', 'string', 'in:glo_office,provincial_office,bank,partner_platform'],
            'original_ticket_evidenced' => ['sometimes', 'boolean'],
            'identity_document_evidenced' => ['sometimes', 'boolean'],
        ]);

        // Explicitly drop any client-supplied trust fields.
        unset(
            $validated['status'],
            $validated['payment_status'],
            $validated['hold_status'],
            $validated['is_frozen'],
            $validated['payment_hold'],
            $validated['age'],
            $validated['gross_prize'],
            $validated['stamp_duty'],
            $validated['net_prize'],
        );

        try {
            $claim = $this->claims->submit($validated, $user);
        } catch (GloClaimException $e) {
            return ApiResponse::error('glo_claim_rejected', $e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return ApiResponse::success($this->claimPayload($claim), 'GLO prize claim submitted', 201);
    }

    public function claim(Request $request, string $claim): JsonResponse
    {
        $model = GloPrizeClaim::query()
            ->where('claim_reference', $claim)
            ->orWhere('id', ctype_digit($claim) ? (int) $claim : 0)
            ->first();

        if ($model === null) {
            return ApiResponse::error('not_found', 'Claim not found', 404);
        }

        $user = $request->user();
        $isOwner = $user !== null && (int) $model->claimant_user_id === (int) $user->getKey();

        if (! $isOwner
            && ! AdminAccess::allowsAny($user, [AdminAccess::MANAGE_GLO_PRIZE_CLAIMS, AdminAccess::EXECUTE_GLO_PRIZE_PAYMENTS, AdminAccess::VIEW_AUDIT_LOGS])) {
            return ApiResponse::error('forbidden', 'Not your claim.', 403);
        }

        return ApiResponse::success($this->claimPayload($model), 'GLO prize claim');
    }

    public function reviewClaim(Request $request, string $claim): JsonResponse
    {
        $user = $request->user();

        if (! AdminAccess::allows($user, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS)) {
            return ApiResponse::error('glo_forbidden', 'Missing claim review permission.', 403);
        }

        $model = $this->findClaim($claim);

        if ($model === null) {
            return ApiResponse::error('not_found', 'Claim not found', 404);
        }

        try {
            $updated = $this->claims->reviewEligible($model, $user);
        } catch (GloClaimException $e) {
            return ApiResponse::error('glo_claim_rejected', $e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return ApiResponse::success($this->claimPayload($updated), 'Claim reviewed');
    }

    public function approveClaim(Request $request, string $claim): JsonResponse
    {
        $user = $request->user();

        if (! AdminAccess::allows($user, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS)) {
            return ApiResponse::error('glo_forbidden', 'Missing claim approval permission.', 403);
        }

        $model = $this->findClaim($claim);

        if ($model === null) {
            return ApiResponse::error('not_found', 'Claim not found', 404);
        }

        try {
            $updated = $this->claims->approve($model, $user);
        } catch (GloClaimException $e) {
            return ApiResponse::error('glo_claim_rejected', $e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return ApiResponse::success($this->claimPayload($updated), 'Claim approved');
    }

    public function rejectClaim(Request $request, string $claim): JsonResponse
    {
        $user = $request->user();

        if (! AdminAccess::allows($user, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS)) {
            return ApiResponse::error('glo_forbidden', 'Missing claim review permission.', 403);
        }

        $model = $this->findClaim($claim);

        if ($model === null) {
            return ApiResponse::error('not_found', 'Claim not found', 404);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->claims->reject($model, $user, $validated['reason']);
        } catch (GloClaimException $e) {
            return ApiResponse::error('glo_claim_rejected', $e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return ApiResponse::success($this->claimPayload($updated), 'Claim rejected');
    }

    public function payClaim(Request $request, string $claim): JsonResponse
    {
        $user = $request->user();

        if (! AdminAccess::allows($user, AdminAccess::EXECUTE_GLO_PRIZE_PAYMENTS)) {
            return ApiResponse::error('glo_forbidden', 'Missing GLO payment execution permission.', 403);
        }

        $model = $this->findClaim($claim);

        if ($model === null) {
            return ApiResponse::error('not_found', 'Claim not found', 404);
        }

        $validated = $request->validate([
            'transaction_reference' => ['nullable', 'string', 'max:96'],
        ]);

        try {
            $updated = $this->claims->pay($model, $user, $validated['transaction_reference'] ?? null);
        } catch (GloClaimException $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;
            $slug = str_contains($e->getMessage(), 'ALREADY_PAID') ? 'already_paid' : 'glo_claim_rejected';

            return ApiResponse::error($slug, $e->getMessage(), $code);
        }

        return ApiResponse::success($this->claimPayload($updated), 'Claim paid');
    }

    public function cancelClaim(Request $request, string $claim): JsonResponse
    {
        $user = $request->user();
        $model = $this->findClaim($claim);

        if ($model === null) {
            return ApiResponse::error('not_found', 'Claim not found', 404);
        }

        $isOwner = $user !== null && (int) $model->claimant_user_id === (int) $user->getKey();

        if (! $isOwner && ! AdminAccess::allows($user, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS)) {
            return ApiResponse::error('glo_forbidden', 'Missing permission to cancel this claim.', 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->claims->cancel($model, $user, $validated['reason']);
        } catch (GloClaimException $e) {
            return ApiResponse::error('glo_claim_rejected', $e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return ApiResponse::success($this->claimPayload($updated), 'Claim cancelled');
    }

    /*
    |--------------------------------------------------------------------------
    | GLO-13 public status (unauthenticated, throttled at the route)
    |--------------------------------------------------------------------------
    */

    public function publicTicketStatus(Request $request, string $reference): JsonResponse
    {
        $payload = $this->publicStatus->verify($reference);

        return ApiResponse::success($payload, 'Public ticket status', 200, [
            'status_code' => $payload['status'],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function freezeTransition(Request $request, string $freeze, string $mode): JsonResponse
    {
        $user = $request->user();

        if (! AdminAccess::allows($user, AdminAccess::REVIEW_GLO_FREEZES)) {
            return ApiResponse::error('glo_forbidden', 'Missing freeze review permission.', 403);
        }

        $model = GloTicketFreeze::query()
            ->where('freeze_case_id', $freeze)
            ->orWhere('id', ctype_digit($freeze) ? (int) $freeze : 0)
            ->first();

        if ($model === null) {
            return ApiResponse::error('not_found', 'Freeze case not found', 404);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $validated['reason'] ?? null;

        try {
            $updated = match ($mode) {
                'review' => $this->freezes->startReview($model, $user),
                'approve' => $this->freezes->approveToFrozen($model, $user, $reason),
                'reject' => $this->freezes->reject($model, $user, $reason ?? 'Rejected'),
                'release' => $this->freezes->release($model, $user, $reason ?? 'Released'),
            };
        } catch (GloFreezeException $e) {
            return ApiResponse::error('glo_freeze_rejected', $e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return ApiResponse::success($this->freezePayload($updated), 'Freeze case updated');
    }

    private function findClaim(string $claim): ?GloPrizeClaim
    {
        return GloPrizeClaim::query()
            ->where('claim_reference', $claim)
            ->orWhere('id', ctype_digit($claim) ? (int) $claim : 0)
            ->first();
    }

    private function resolveRouteTicket(string $ticket): ?GloTicket
    {
        if (ctype_digit($ticket)) {
            return GloTicket::query()->find((int) $ticket);
        }

        return GloTicket::query()->where('ticket_reference', $ticket)->first();
    }

    /**
     * Public-safe freeze payload: case id + status only; no evidence body,
     * no claimant PII, no internal notes beyond resolution reason when final.
     *
     * @return array<string, mixed>
     */
    private function freezePayload(GloTicketFreeze $freeze): array
    {
        return [
            'freeze_case_id' => $freeze->freeze_case_id,
            'status' => $freeze->status->value,
            'status_label' => $freeze->status->label(),
            'ticket_reference' => $freeze->ticket?->ticket_reference,
            'draw_id' => (int) $freeze->draw_id,
            'product' => $freeze->product,
            // Digit string preserved:
            'ticket_number' => $freeze->ticket_number,
            'case_reference' => $freeze->case_reference,
            'requested_at' => $freeze->requested_at?->toIso8601String(),
            'effective_at' => $freeze->effective_at?->toIso8601String(),
            'released_at' => $freeze->released_at?->toIso8601String(),
            'expiry_at' => $freeze->expiry_at?->toIso8601String(),
        ];
    }

    /**
     * Claim payload: claimant-visible fields + operator status. Money as
     * strings. No raw KYC document numbers.
     *
     * @return array<string, mixed>
     */
    private function claimPayload(GloPrizeClaim $claim): array
    {
        return [
            'claim_reference' => $claim->claim_reference,
            'ticket_id' => (int) $claim->ticket_id,
            'draw_id' => (int) $claim->draw_id,
            'product' => $claim->product,
            'prize_category' => $claim->prize_category,
            'ticket_number' => $claim->ticket_number,
            'gross_prize' => (string) $claim->gross_prize,
            'stamp_duty' => (string) $claim->stamp_duty,
            'net_prize' => (string) $claim->net_prize,
            'claim_channel' => $claim->claim_channel->value,
            'status' => $claim->status->value,
            'payment_status' => $claim->payment_status,
            'hold_status' => $claim->hold_status,
            'hold_reason' => $claim->hold_reason,
            'age_verification_result' => $claim->age_verification_result,
            'verified_age_years' => $claim->verified_age_years,
            'submitted_at' => $claim->submitted_at?->toIso8601String(),
            'approved_at' => $claim->approved_at?->toIso8601String(),
            'paid_at' => $claim->paid_at?->toIso8601String(),
            'payment_transaction_reference' => $claim->payment_transaction_reference,
            // Never expose client-submitted age or trust flags.
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | GLO-16 public sales points
    |--------------------------------------------------------------------------
    */

    public function salesPoints(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'latitude' => ['nullable', 'numeric', 'between:-90.0,90.0'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'radius' => ['nullable', 'numeric', 'min:0.1'],
                'province' => ['nullable', 'string', 'max:120'],
                'district' => ['nullable', 'string', 'max:120'],
                'q' => ['nullable', 'string', 'max:120'],
                'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            ]);
        } catch (ValidationException $e) {
            return ApiResponse::error('validation_failed', $e->getMessage(), 422, $e->errors()->toArray());
        }

        try {
            $page = $this->salesPoints->publicSearch($validated);
        } catch (GloDealerException $e) {
            return ApiResponse::error('invalid_coordinates', $e->getMessage(), 422);
        }

        return ApiResponse::success(
            $page->items(),
            'Public sales points',
            200,
            [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    public function updateDailySalesLocation(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'province' => ['nullable', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'subdistrict' => ['nullable', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90.0,90.0'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'public_contact' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $dealer = $this->dealers->dealerForUser($user);
            $history = $this->salesPoints->updateDailyLocation($dealer, $validated, $user);
        } catch (GloDealerException $e) {
            return ApiResponse::error('dealer_error', $e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return ApiResponse::success([
            'effective_date' => $history->effective_date,
            'sales_point_id' => $history->sales_point_id,
            'display_name' => $history->display_name,
            'recorded' => true,
            'history_preserved' => true,
        ], 'Daily sales location recorded');
    }

    /*
    |--------------------------------------------------------------------------
    | GLO-15 dealer e-Service
    |--------------------------------------------------------------------------
    */

    public function dealerProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        $dealer = $this->dealers->dealerForUser($user);

        return ApiResponse::success($this->dealers->ownProfile($dealer), 'Dealer profile');
    }

    public function dealerHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        $dealer = $this->dealers->dealerForUser($user);
        $perPage = (int) $request->query('per_page', 20);

        return ApiResponse::success(
            $this->dealers->ownHistory($dealer, $perPage),
            'Dealer history (internal application data, labeled)',
        );
    }

    public function submitDealerChangeRequest(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        $validated = $request->validate([
            'request_type' => ['required', 'string', 'in:name,address,phone,sales_location'],
            'requested_value' => ['required', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $type = GloDealerRequestType::from($validated['request_type']);
            $dealer = $this->dealers->dealerForUser($user);
            $row = $this->changeRequests->submit(
                $dealer,
                $user,
                $type,
                (string) $validated['requested_value'],
                $validated['reason'] ?? null,
            );
        } catch (GloDealerException $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;

            return ApiResponse::error('dealer_request', $e->getMessage(), $code);
        }

        return ApiResponse::success([
            'request_reference' => $row->request_reference,
            'request_type' => $row->request_type->value,
            'status' => $row->status->value,
            'submitted_at' => $row->submitted_at?->toIso8601String(),
        ], 'Change request submitted', 201);
    }

    public function dealerChangeRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        $dealer = $this->dealers->dealerForUser($user);

        return ApiResponse::success(
            $this->changeRequests->ownRequests($dealer),
            'Own change requests',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Operator: dealer request review (permission via route middleware)
    |--------------------------------------------------------------------------
    */

    public function operatorDealerRequests(Request $request): JsonResponse
    {
        $status = (string) $request->query('status', 'submitted');
        $query = GloDealerChangeRequest::query()->orderByDesc('submitted_at')->limit(100);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        $rows = $query->get()->map(static fn (GloDealerChangeRequest $r): array => [
            'request_reference' => $r->request_reference,
            'dealer_id' => $r->dealer_id,
            'request_type' => $r->request_type->value,
            'status' => $r->status->value,
            'old_value' => $r->old_value,
            'requested_value' => $r->requested_value,
            'reason' => $r->reason,
            'reviewed_by' => $r->reviewed_by,
            'review_note' => $r->review_note,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'audit_fingerprint' => $r->audit_fingerprint,
        ])->all();

        return ApiResponse::success($rows, 'Dealer change requests');
    }

    public function operatorApproveDealerRequest(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        $row = GloDealerChangeRequest::query()
            ->where('request_reference', $reference)
            ->first();

        if ($row === null) {
            return ApiResponse::error('not_found', 'Request not found', 404);
        }

        try {
            $reviewed = $this->changeRequests->startReview($row, $user);
            $reviewed = $this->changeRequests->approve($reviewed, $user, $validated['review_note'] ?? null);
        } catch (GloDealerException $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 403;

            return ApiResponse::error('forbidden_or_illegal', $e->getMessage(), $code);
        }

        return ApiResponse::success([
            'request_reference' => $reviewed->request_reference,
            'status' => $reviewed->status->value,
            'decided_at' => $reviewed->decided_at?->toIso8601String(),
        ], 'Request approved');
    }

    public function operatorRejectDealerRequest(Request $request, string $reference): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        $row = GloDealerChangeRequest::query()
            ->where('request_reference', $reference)
            ->first();

        if ($row === null) {
            return ApiResponse::error('not_found', 'Request not found', 404);
        }

        try {
            $reviewed = $this->changeRequests->startReview($row, $user);
            $reviewed = $this->changeRequests->reject($reviewed, $user, $validated['review_note'] ?? null);
        } catch (GloDealerException $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 403;

            return ApiResponse::error('forbidden_or_illegal', $e->getMessage(), $code);
        }

        return ApiResponse::success([
            'request_reference' => $reviewed->request_reference,
            'status' => $reviewed->status->value,
            'decided_at' => $reviewed->decided_at?->toIso8601String(),
        ], 'Request rejected');
    }

    /*
    |--------------------------------------------------------------------------
    | GLO-17 saved tickets + notification status
    |--------------------------------------------------------------------------
    */

    public function listMySavedTickets(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        $includeInactive = (string) $request->query('include_inactive', '') === '1';

        return ApiResponse::success(
            $this->savedTickets->listOwn($user, $includeInactive),
            'Saved tickets',
        );
    }

    public function saveMyTicket(Request $request, string $ticket): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        if (! ctype_digit($ticket)) {
            return ApiResponse::error('invalid_ticket', 'Ticket id must be numeric', 422);
        }

        try {
            $result = $this->savedTickets->save($user, (int) $ticket);
        } catch (GloDealerException $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;

            return ApiResponse::error('save_ticket', $e->getMessage(), $code);
        }

        return ApiResponse::success([
            'ticket_reference' => $result['saved']->ticket_reference,
            'status' => $result['saved']->status->value,
            'saved_at' => $result['saved']->saved_at?->toIso8601String(),
        ], 'Ticket saved', 201);
    }

    public function removeMySavedTicket(Request $request, string $ticket): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        if (! ctype_digit($ticket)) {
            return ApiResponse::error('invalid_ticket', 'Ticket id must be numeric', 422);
        }

        try {
            $saved = $this->savedTickets->remove($user, (int) $ticket);
        } catch (GloDealerException $e) {
            $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409;

            return ApiResponse::error('remove_ticket', $e->getMessage(), $code);
        }

        return ApiResponse::success([
            'ticket_reference' => $saved->ticket_reference,
            'status' => $saved->status->value,
            'removed_at' => $saved->removed_at?->toIso8601String(),
            'evidence_retained' => true,
        ], 'Ticket removed from saved list');
    }

    public function myGloNotifications(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication required', 401);
        }

        $rows = GloNotificationDelivery::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('queued_at')
            ->limit(50)
            ->get()
            ->map(static fn (GloNotificationDelivery $d): array => [
                'ticket_reference' => $d->ticket?->ticket_number,
                'draw_id' => $d->draw_id,
                'delivery_state' => $d->delivery_state,
                'channel' => $d->channel,
                'provider' => $d->provider,
                'notification_type' => $d->notification_type,
                'result_version' => $d->result_version,
                'queued_at' => $d->queued_at?->toIso8601String(),
                'sent_at' => $d->sent_at?->toIso8601String(),
                'summary' => $d->payload_summary['summary'] ?? null,
            ])
            ->all();

        return ApiResponse::success($rows, 'GLO result notification deliveries');
    }

    /*
    |--------------------------------------------------------------------------
    | GLO-18 public result experience
    |--------------------------------------------------------------------------
    */

    public function publicResults(Request $request): JsonResponse
    {
        $draw = (string) $request->query('draw', '');

        try {
            $payload = $draw !== ''
                ? $this->publicResults->cachedResult($draw)
                : $this->publicResults->cachedResult(null);
        } catch (GloDealerException $e) {
            return ApiResponse::error('result_unavailable', $e->getMessage(), 404);
        }

        return ApiResponse::success($payload, 'Current GLO result');
    }

    public function publicResultHistory(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'from' => ['nullable', 'date'],
                'to' => ['nullable', 'date'],
                'draw_date' => ['nullable', 'date'],
                'product' => ['nullable', 'string', 'max:8'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);
            $page = $this->publicResults->history($validated);
        } catch (ValidationException $e) {
            return ApiResponse::error('validation_failed', $e->getMessage(), 422, $e->errors()->toArray());
        } catch (GloDealerException $e) {
            return ApiResponse::error('history_window', $e->getMessage(), 422);
        }

        return ApiResponse::success(
            $page->items(),
            'GLO result history (configured public window)',
            200,
            [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'history_years' => (int) config('glo.result_experience.history_years', 2),
            ],
        );
    }

    public function publicCheckSixDigit(Request $request): JsonResponse
    {
        $number = (string) ($request->route('number') ?? $request->input('number', ''));
        $draw = $request->input('draw', $request->route('draw'));

        $validated = ['number' => $number, 'draw' => $draw];
        $validator = validator($validated, [
            'number' => ['required', 'string', 'regex:/^\d{6}$/'],
            'draw' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('validation_failed', 'number must be exactly six numeric characters (leading zeros preserved)', 422, $validator->errors()->toArray());
        }

        try {
            $result = $this->publicResults->checkSixDigit(
                (string) $validated['number'],
                $validated['draw'] !== null && $validated['draw'] !== '' ? (string) $validated['draw'] : null,
            );
        } catch (GloDealerException $e) {
            return ApiResponse::error('check_failed', $e->getMessage(), 422);
        }

        return ApiResponse::success($result, 'Six-digit result check');
    }

    public function publicResultSheet(Request $request): JsonResponse
    {
        $draw = (string) $request->query('draw', '');

        try {
            $sheet = $this->publicResults->resultSheet($draw !== '' ? $draw : null);
        } catch (GloDealerException $e) {
            return ApiResponse::error('sheet_unavailable', $e->getMessage(), 404);
        }

        return ApiResponse::success($sheet, 'Result check sheet');
    }

    public function verifyDataMatrix(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payload' => ['required', 'string', 'max:4096'],
        ]);

        $parsed = $this->dataMatrix->parse((string) $validated['payload']);

        if ($parsed['payload'] === null) {
            return ApiResponse::error(
                $parsed['status'],
                (string) ($parsed['reason'] ?? 'Data Matrix check unavailable'),
                503,
                [
                    'source_state' => $parsed['source_state'],
                    'schema' => $parsed['schema'],
                ],
            );
        }

        $ticketNumber = (string) $parsed['payload']['ticket_number'];
        $check = null;
        $drawNumber = $parsed['payload']['draw_number'] ?? null;

        try {
            $check = $this->publicResults->checkSixDigit(
                $ticketNumber,
                is_string($drawNumber) && $drawNumber !== '' ? $drawNumber : null,
            );
        } catch (GloDealerException) {
            $check = null;
        }

        return ApiResponse::success([
            'parse_status' => $parsed['status'],
            'source_state' => $parsed['source_state'],
            'schema' => $parsed['schema'],
            'ticket_number' => $ticketNumber,
            'synthetic' => true,
            'note' => $parsed['reason'],
            'check' => $check,
        ], 'Data Matrix fixture verification');
    }

    public function liveDrawStatus(): JsonResponse
    {
        return ApiResponse::success($this->liveDraw->liveStatus(), 'Live draw / replay provider status');
    }

    public function replayStatus(): JsonResponse
    {
        try {
            $replays = $this->liveDraw->replays();

            return ApiResponse::success([
                'replay_status' => 'configured',
                'items' => $replays,
            ], 'Historical draw replay catalog');
        } catch (GloDealerException $e) {
            return ApiResponse::success([
                'replay_status' => GloSourceState::NotConfigured->value,
                'items' => [],
                'message' => $e->getMessage(),
            ], 'Replay provider status');
        }
    }

    public function publicResultByDraw(Request $request, string $draw): JsonResponse
    {
        try {
            $payload = $this->publicResults->cachedResult($draw);
        } catch (GloDealerException $e) {
            return ApiResponse::error('result_unavailable', $e->getMessage(), 404);
        }

        return ApiResponse::success($payload, 'GLO result by draw');
    }
}
