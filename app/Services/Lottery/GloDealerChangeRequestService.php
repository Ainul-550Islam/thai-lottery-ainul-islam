<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\DTOs\Notification\NotificationMessageData;
use App\Enums\AuditAction;
use App\Enums\GloDealerRequestStatus;
use App\Enums\GloDealerRequestType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\NotificationPriority;
use App\Enums\RiskLevel;
use App\Exceptions\GloDealerException;
use App\Models\AuditLog;
use App\Models\GloDealer;
use App\Models\GloDealerChangeRequest;
use App\Models\User;
use App\Services\Notification\NotificationDispatchService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Dealer change-request workflow (GLO-15 e-Service).
 *
 * submitted → under_review → approved | rejected
 * approved → cancelled (optional)
 *
 * - Dealers submit for themselves only.
 * - Operators start review and decide; dealers never self-approve.
 * - Approval applies the requested value to GloDealer under lock and audits
 *   old/new/reason/actors/fingerprint.
 * - Open request uniqueness per type under lock (concurrent duplicate → 409).
 */
class GloDealerChangeRequestService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly NotificationDispatchService $notifications,
    ) {}

    public function submit(GloDealer $dealer, User $actor, GloDealerRequestType $type, string $requestedValue, ?string $reason = null): GloDealerChangeRequest
    {
        if ((int) $dealer->user_id !== (int) $actor->getKey()) {
            throw GloDealerException::forbidden('submit change requests for another dealer');
        }

        if (! $dealer->status->maySubmitChangeRequests()) {
            throw GloDealerException::notActive('submit a change request');
        }

        $requestedValue = trim($requestedValue);
        $this->assertRequestedValue($type, $requestedValue);

        $fingerprintBase = implode('|', [
            'glo-dealer-req',
            (string) $dealer->getKey(),
            $type->value,
            $requestedValue,
            now()->format('Y-m-d\TH:i:s.u'),
        ]);

        return $this->db->connection()->transaction(function () use ($dealer, $actor, $type, $requestedValue, $reason, $fingerprintBase): GloDealerChangeRequest {
            // One open request per type per dealer.
            $open = GloDealerChangeRequest::query()
                ->where('dealer_id', $dealer->getKey())
                ->where('request_type', $type->value)
                ->whereIn('status', [
                    GloDealerRequestStatus::Submitted->value,
                    GloDealerRequestStatus::UnderReview->value,
                ])
                ->lockForUpdate()
                ->exists();

            if ($open) {
                throw GloDealerException::openRequestExists($type->value);
            }

            $oldValue = match ($type) {
                GloDealerRequestType::Name => $dealer->display_name,
                GloDealerRequestType::Address => $dealer->address,
                GloDealerRequestType::Phone => $dealer->phone,
                GloDealerRequestType::SalesLocation => $dealer->sales_location,
            };

            $row = GloDealerChangeRequest::create([
                'request_reference' => 'GLODR-'.Str::upper(Str::random(16)),
                'dealer_id' => $dealer->getKey(),
                'requested_by' => $actor->getKey(),
                'request_type' => $type,
                'status' => GloDealerRequestStatus::Submitted,
                'old_value' => $oldValue,
                'requested_value' => $requestedValue,
                'reason' => $reason !== null ? mb_substr(trim($reason), 0, 500) : null,
                'submitted_at' => now(),
                'audit_fingerprint' => hash('sha256', $fingerprintBase),
                'metadata' => ['dealer_ref' => $dealer->dealer_ref],
            ]);

            $this->audit($actor, $row, 'glo_dealer_request_submitted', RiskLevel::Medium);

            return $row;
        });
    }

    public function startReview(GloDealerChangeRequest $request, User $operator): GloDealerChangeRequest
    {
        $this->assertNotSelfActor($request, $operator, 'review');
        $this->assertOperator($operator);

        return $this->transition($request, GloDealerRequestStatus::UnderReview, $operator, function (GloDealerChangeRequest $fresh) use ($operator): void {
            $fresh->reviewed_by = $operator->getKey();
            $fresh->reviewed_at = now();
        }, 'glo_dealer_request_under_review');
    }

    public function approve(GloDealerChangeRequest $request, User $operator, ?string $reviewNote = null): GloDealerChangeRequest
    {
        $this->assertNotSelfActor($request, $operator, 'approve');
        $this->assertOperator($operator);

        return $this->db->connection()->transaction(function () use ($request, $operator, $reviewNote): GloDealerChangeRequest {
            $fresh = GloDealerChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== GloDealerRequestStatus::UnderReview) {
                throw GloDealerException::illegalTransition($fresh->status->value, GloDealerRequestStatus::Approved->value);
            }

            $dealer = GloDealer::query()->whereKey($fresh->dealer_id)->lockForUpdate()->firstOrFail();
            $type = $fresh->request_type;
            $field = $type->profileField();
            $newValue = $fresh->requested_value;

            // sales_location approval also refreshes province/district only if
            // they were encoded in requested_value as "location|province|district".
            $parts = explode('|', $newValue);
            $location = $parts[0];
            $dealer->{$field} = $location;

            if ($type === GloDealerRequestType::SalesLocation) {
                if (isset($parts[1]) && $parts[1] !== '') {
                    $dealer->province = $parts[1];
                }
                if (isset($parts[2]) && $parts[2] !== '') {
                    $dealer->district = $parts[2];
                }
            }

            if ($type === GloDealerRequestType::Name) {
                $dealer->display_name = $location;
            }

            $fresh->status = GloDealerRequestStatus::Approved;
            $fresh->reviewed_by = $operator->getKey();
            $fresh->reviewed_at = $fresh->reviewed_at ?? now();
            $fresh->decided_at = now();
            $fresh->review_note = $reviewNote !== null ? mb_substr(trim($reviewNote), 0, 500) : null;

            $dealer->save();
            $fresh->save();

            $this->audit($operator, $fresh, 'glo_dealer_request_approved', RiskLevel::High, [
                'applied_field' => $field,
                'old_value' => $fresh->old_value,
                'new_value' => $location,
            ]);

            $this->notifyRequester($fresh, 'approved');

            return $fresh;
        });
    }

    public function reject(GloDealerChangeRequest $request, User $operator, ?string $reviewNote = null): GloDealerChangeRequest
    {
        $this->assertNotSelfActor($request, $operator, 'reject');
        $this->assertOperator($operator);

        return $this->transition($request, GloDealerRequestStatus::Rejected, $operator, function (GloDealerChangeRequest $fresh) use ($operator, $reviewNote): void {
            $fresh->reviewed_by = $operator->getKey();
            $fresh->reviewed_at = $fresh->reviewed_at ?? now();
            $fresh->decided_at = now();
            $fresh->review_note = $reviewNote !== null ? mb_substr(trim($reviewNote), 0, 500) : null;
        }, 'glo_dealer_request_rejected', notify: 'rejected');
    }

    public function cancelApproved(GloDealerChangeRequest $request, User $operator): GloDealerChangeRequest
    {
        $this->assertOperator($operator);

        return $this->transition($request, GloDealerRequestStatus::Cancelled, $operator, function (GloDealerChangeRequest $fresh) use ($operator): void {
            $fresh->reviewed_by = $operator->getKey();
            $fresh->decided_at = now();
        }, 'glo_dealer_request_cancelled', notify: 'cancelled');
    }

    /**
     * Dealer's own request list (public-safe fields only).
     *
     * @return list<array<string, mixed>>
     */
    public function ownRequests(GloDealer $dealer, int $limit = 50): array
    {
        return GloDealerChangeRequest::query()
            ->where('dealer_id', $dealer->getKey())
            ->orderByDesc('submitted_at')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->map(static fn (GloDealerChangeRequest $r): array => [
                'request_reference' => $r->request_reference,
                'request_type' => $r->request_type->value,
                'status' => $r->status->value,
                'requested_value' => $r->requested_value,
                'reason' => $r->reason,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'decided_at' => $r->decided_at?->toIso8601String(),
                'review_note' => $r->status === GloDealerRequestStatus::Rejected ? $r->review_note : null,
            ])
            ->all();
    }

    private function transition(
        GloDealerChangeRequest $request,
        GloDealerRequestStatus $to,
        User $actor,
        callable $mutate,
        string $auditType,
        ?string $notify = null,
    ): GloDealerChangeRequest {
        return $this->db->connection()->transaction(function () use ($request, $to, $actor, $mutate, $auditType, $notify): GloDealerChangeRequest {
            $fresh = GloDealerChangeRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            $allowed = match ($to) {
                GloDealerRequestStatus::UnderReview => $fresh->status === GloDealerRequestStatus::Submitted,
                GloDealerRequestStatus::Rejected => $fresh->status === GloDealerRequestStatus::UnderReview,
                GloDealerRequestStatus::Cancelled => $fresh->status === GloDealerRequestStatus::Approved,
                default => false,
            };

            if (! $allowed) {
                throw GloDealerException::illegalTransition($fresh->status->value, $to->value);
            }

            $fresh->status = $to;
            $mutate($fresh);
            $fresh->save();

            $this->audit($actor, $fresh, $auditType, RiskLevel::High);

            if ($notify !== null) {
                $this->notifyRequester($fresh, $notify);
            }

            return $fresh;
        });
    }

    private function assertRequestedValue(GloDealerRequestType $type, string $value): void
    {
        if ($value === '') {
            throw GloDealerException::invalidRequestedValue('value required');
        }

        match ($type) {
            GloDealerRequestType::Name => mb_strlen($value) <= 255
                ? null
                : throw GloDealerException::invalidRequestedValue('name too long'),
            GloDealerRequestType::Phone => preg_match('/^\+?[0-9\s\-]{6,32}$/', $value) === 1
                ? null
                : throw GloDealerException::invalidRequestedValue('phone format'),
            GloDealerRequestType::Address, GloDealerRequestType::SalesLocation => mb_strlen($value) <= 500
                ? null
                : throw GloDealerException::invalidRequestedValue('text too long'),
        };
    }

    private function assertOperator(User $operator): void
    {
        if (! $operator->isActive()) {
            throw GloDealerException::forbidden('review dealer requests');
        }

        // Server-side: operator must hold the dedicated permission (or super-admin).
        $allowed = $operator->can('review glo dealer requests')
            || $operator->hasRole('super-admin');

        if (! $allowed) {
            throw GloDealerException::forbidden('review dealer requests');
        }
    }

    private function assertNotSelfActor(GloDealerChangeRequest $request, User $operator, string $action): void
    {
        $dealer = GloDealer::query()->find($request->dealer_id);

        if ($dealer !== null && (int) $dealer->user_id === (int) $operator->getKey()) {
            throw GloDealerException::cannotSelfApprove();
        }

        if ((int) $request->requested_by === (int) $operator->getKey()
            && in_array($action, ['approve', 'review', 'reject'], true)) {
            throw GloDealerException::cannotSelfApprove();
        }
    }

    private function audit(User $actor, GloDealerChangeRequest $row, string $type, RiskLevel $risk, array $extra = []): void
    {
        AuditLog::create([
            'user_id' => $actor->getKey(),
            'action' => str_contains($type, 'submitted') ? AuditAction::Create : AuditAction::Update,
            'risk_level' => $risk,
            'auditable_type' => GloDealerChangeRequest::class,
            'auditable_id' => $row->getKey(),
            'description' => $type,
            'metadata' => array_merge([
                'action_type' => $type,
                'request_reference' => $row->request_reference,
                'request_type' => $row->request_type->value,
                'status' => $row->status->value,
                'old_value' => $row->old_value,
                'requested_value' => $row->requested_value,
                'reason' => $row->reason,
                'audit_fingerprint' => $row->audit_fingerprint,
                'reviewed_by' => $row->reviewed_by,
            ], $extra),
        ]);
    }

    private function notifyRequester(GloDealerChangeRequest $row, string $outcome): void
    {
        try {
            $this->notifications->pronounce(
                (int) $row->requested_by,
                NotificationMessageData::fromInput([
                    'user_id' => (int) $row->requested_by,
                    'event_type' => NotificationEventType::DealerRequestUpdate,
                    'channel' => NotificationChannel::InApp,
                    'priority' => NotificationPriority::Normal,
                    'subject' => 'Dealer change request '.$outcome,
                    'body' => 'Your '.$row->request_type->value.' request '.$row->request_reference.' is now '.$outcome.'.',
                    'expires_at' => now()->addDays(30),
                ]),
            );
        } catch (\Throwable) {
            // Notification failure must not roll back a seated decision.
        }
    }
}
