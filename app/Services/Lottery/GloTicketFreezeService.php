<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\GloFreezeStatus;
use App\Enums\RiskLevel;
use App\Exceptions\GloFreezeException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\GloTicket;
use App\Models\GloTicketFreeze;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * GLO-11 dedicated ticket freeze / seizure state machine.
 *
 * Deliberately separate from wallet freeze, FinancialHold and withdrawal holds:
 * those are money lanes; this is the legal/administrative GLO ticket seizure
 * workflow (evidence → review → frozen → release/expiry) with append-only
 * history.
 *
 * Guarantees:
 *  - Exact ticket match only (digit-string number, one ticket per request;
 *    never "all tickets for a user/draw").
 *  - Final freeze requires legal evidence (reference, authority, case ref).
 *  - Canonical fingerprint + unique index → idempotent retries.
 *  - hasActiveEffectiveFreeze() is the single payment gate: ANY active frozen
 *    case blocks payment until ALL are released or expired.
 *  - Expiry is an audited status transition (Frozen → Expired), never a row
 *    delete; history remains queryable.
 *
 * Authorization (who may ask) is enforced by callers via AdminAccess
 * permissions; this class enforces what is *legal* for a case to do.
 */
class GloTicketFreezeService
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Create a freeze REQUEST (status = requested). Final freeze only after
     * review + evidence completeness via approveToFrozen().
     *
     * @param array{
     *     draw_id: int,
     *     product: string,
     *     ticket_number: string,
     *     set_series?: string|null,
     *     requesting_authority: string,
     *     jurisdiction: string,
     *     case_reference: string,
     *     legal_reference_number?: string|null,
     *     evidence_reference: string,
     *     evidence_type?: string,
     *     evidence_document_id?: string|null,
     *     evidence_content_hash?: string|null,
     *     evidence_mime_type?: string|null,
     *     evidence_submission_metadata?: array<string, mixed>|null,
     *     evidence_received_at?: string|null,
     *     expiry_at?: string|null,
     * } $input
     */
    public function requestFreeze(array $input, User $operator): GloTicketFreeze
    {
        $drawId = (int) ($input['draw_id'] ?? 0);
        $product = strtolower(trim((string) ($input['product'] ?? 'l6')));
        $ticketNumber = $this->normalizeTicketNumber((string) ($input['ticket_number'] ?? ''));
        $series = $this->normalizeSeries($input['set_series'] ?? null);

        if (! in_array($product, (array) config('glo.freeze.products', ['l6', 'n3']), true)) {
            throw GloFreezeException::invalidTicket('unknown product "'.$product.'"');
        }

        $draw = Draw::query()->find($drawId);

        if ($draw === null) {
            throw GloFreezeException::invalidTicket('draw '.$drawId.' does not exist');
        }

        $authority = trim((string) ($input['requesting_authority'] ?? ''));
        $jurisdiction = trim((string) ($input['jurisdiction'] ?? ''));
        $caseReference = trim((string) ($input['case_reference'] ?? ''));
        $evidenceReference = trim((string) ($input['evidence_reference'] ?? ''));

        if ($authority === '' || $caseReference === '' || $evidenceReference === '') {
            throw GloFreezeException::missingEvidence();
        }

        $expiryAt = $this->parseExpiry($input['expiry_at'] ?? null, $draw);

        $ticket = $this->resolveExactTicket($draw, $product, $ticketNumber, $series);

        $fingerprint = $this->requestFingerprint(
            $draw->getKey(),
            $product,
            $ticketNumber,
            $series,
            $caseReference,
            $evidenceReference,
        );

        return $this->db->connection()->transaction(function () use (
            $input,
            $operator,
            $ticket,
            $draw,
            $product,
            $ticketNumber,
            $series,
            $authority,
            $jurisdiction,
            $caseReference,
            $evidenceReference,
            $expiryAt,
            $fingerprint,
        ): GloTicketFreeze {
            $existing = GloTicketFreeze::query()
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $evidenceType = trim((string) ($input['evidence_type'] ?? 'official_notice'));
            $documentId = $input['evidence_document_id'] ?? null;
            $contentHash = $input['evidence_content_hash'] ?? null;
            $mimeType = $input['evidence_mime_type'] ?? null;

            $freeze = GloTicketFreeze::create([
                'freeze_case_id' => 'GLOFZ-'.Str::upper(Str::random(16)),
                'ticket_id' => $ticket->getKey(),
                'draw_id' => (int) $draw->getKey(),
                'product' => $product,
                'ticket_number' => $ticketNumber,
                'set_series' => $series,
                'requesting_authority' => $authority,
                'jurisdiction' => $jurisdiction,
                'case_reference' => $caseReference,
                'legal_reference_number' => $input['legal_reference_number'] ?? null,
                'evidence_reference' => $evidenceReference,
                'evidence_type' => $evidenceType !== '' ? $evidenceType : 'official_notice',
                'evidence_document_id' => $documentId,
                'evidence_content_hash' => $contentHash,
                'evidence_mime_type' => $mimeType,
                'evidence_submission_metadata' => $input['evidence_submission_metadata'] ?? null,
                'evidence_received_at' => $input['evidence_received_at'] ?? null,
                'status' => GloFreezeStatus::Requested,
                'review_status' => 'pending',
                'requested_at' => now(),
                'expiry_at' => $expiryAt,
                'fingerprint' => $fingerprint,
                'requested_by' => $operator->getKey(),
            ]);

            $this->audit(
                $operator,
                $freeze,
                'glo_freeze_requested',
                RiskLevel::High,
                ['product' => $product, 'draw_id' => (int) $draw->getKey()],
            );

            return $freeze;
        });
    }

    public function startReview(GloTicketFreeze $freeze, User $operator): GloTicketFreeze
    {
        return $this->transition($freeze, GloFreezeStatus::UnderReview, $operator, 'glo_freeze_under_review', null, [
            'review_status' => 'in_progress',
        ]);
    }

    /**
     * Under review → frozen. Evidence must be complete: this is the final
     * freeze that blocks payment.
     */
    public function approveToFrozen(GloTicketFreeze $freeze, User $operator, ?string $reason = null): GloTicketFreeze
    {
        if ($freeze->status !== GloFreezeStatus::UnderReview) {
            throw GloFreezeException::illegalTransition($freeze->status->value, GloFreezeStatus::Frozen->value);
        }

        if (! $this->hasCompleteEvidence($freeze)) {
            throw GloFreezeException::missingEvidence();
        }

        return $this->transition($freeze, GloFreezeStatus::Frozen, $operator, 'glo_freeze_frozen', $reason, [
            'review_status' => 'frozen',
            'effective_at' => now(),
        ]);
    }

    /**
     * requested/under_review → rejected. rejected → requested is the only
     * authorized reopen path; rejected → frozen without reopen is forbidden.
     */
    public function reject(GloTicketFreeze $freeze, User $operator, string $reason): GloTicketFreeze
    {
        if (! in_array($freeze->status, [GloFreezeStatus::Requested, GloFreezeStatus::UnderReview], true)) {
            throw GloFreezeException::illegalTransition($freeze->status->value, GloFreezeStatus::Rejected->value);
        }

        return $this->transition($freeze, GloFreezeStatus::Rejected, $operator, 'glo_freeze_rejected', $reason, [
            'review_status' => 'rejected',
        ]);
    }

    /**
     * Authorized reopen: rejected → requested (never rejected → frozen).
     */
    public function reopen(GloTicketFreeze $freeze, User $operator, string $reason): GloTicketFreeze
    {
        if ($freeze->status !== GloFreezeStatus::Rejected) {
            throw GloFreezeException::illegalTransition($freeze->status->value, GloFreezeStatus::Requested->value);
        }

        return $this->transition($freeze, GloFreezeStatus::Requested, $operator, 'glo_freeze_reopened', $reason, [
            'review_status' => 'pending',
        ]);
    }

    /**
     * frozen → released. Does NOT imply the ticket becomes payable if another
     * active freeze case still exists (hasActiveEffectiveFreeze checks ALL).
     */
    public function release(GloTicketFreeze $freeze, User $operator, string $reason): GloTicketFreeze
    {
        if ($freeze->status !== GloFreezeStatus::Frozen) {
            throw GloFreezeException::illegalTransition($freeze->status->value, GloFreezeStatus::Released->value);
        }

        return $this->transition($freeze, GloFreezeStatus::Released, $operator, 'glo_freeze_released', $reason, [
            'review_status' => 'released',
            'released_at' => now(),
        ]);
    }

    /**
     * frozen → expired when expiry_at has passed. Audited transition; the row
     * and all prior history are retained.
     */
    public function expire(GloTicketFreeze $freeze, User $operator, ?string $reason = null): GloTicketFreeze
    {
        if ($freeze->status !== GloFreezeStatus::Frozen) {
            throw GloFreezeException::illegalTransition($freeze->status->value, GloFreezeStatus::Expired->value);
        }

        if ($freeze->expiry_at === null || $freeze->expiry_at->isFuture()) {
            throw GloFreezeException::illegalTransition('frozen(unexpired)', 'expired');
        }

        return $this->transition($freeze, GloFreezeStatus::Expired, $operator, 'glo_freeze_expired', $reason, [
            'review_status' => 'expired',
            'expired_at' => now(),
        ]);
    }

    /**
     * Sweep due freezes. Returns counts; each expiry is an individual audited
     * transition. Callable from console without a human operator (system).
     *
     * @return array{expired: int, scanned: int}
     */
    public function expireDueFreezes(?User $systemActor = null): array
    {
        $due = GloTicketFreeze::query()
            ->where('status', GloFreezeStatus::Frozen)
            ->whereNotNull('expiry_at')
            ->where('expiry_at', '<=', now())
            ->get();

        $actor = $systemActor ?? User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'super-admin'))
            ->first()
            ?? $this->systemPlaceholderActor();

        $expired = 0;

        foreach ($due as $freeze) {
            $this->expire($freeze, $actor, 'Scheduled expiry reached');
            $expired++;
        }

        return ['expired' => $expired, 'scanned' => $due->count()];
    }

    /**
     * Payment gate: ANY legally effective frozen case on this ticket blocks
     * payment. Released and expired cases do not. Must be consulted on every
     * claim approval and every payment execution.
     */
    public function hasActiveEffectiveFreeze(int $ticketId): bool
    {
        return GloTicketFreeze::query()
            ->where('ticket_id', $ticketId)
            ->where('status', GloFreezeStatus::Frozen)
            ->where(function ($query): void {
                $query->whereNull('expiry_at')
                    ->orWhere('expiry_at', '>', now());
            })
            ->exists()
            || GloTicketFreeze::query()
                ->where('ticket_id', $ticketId)
                ->where('status', GloFreezeStatus::Frozen)
                ->whereNull('expired_at')
                ->whereNotNull('expiry_at')
                ->where('expiry_at', '<=', now())
                ->exists();
    }

    /**
     * All active freezes on a ticket (for audit payloads and hold creation).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, GloTicketFreeze>
     */
    public function activeFreezes(int $ticketId)
    {
        return GloTicketFreeze::query()
            ->where('ticket_id', $ticketId)
            ->where('status', GloFreezeStatus::Frozen)
            ->get();
    }

    /**
     * Exact six-digit (L6) / product-native number match. Leading zeros kept
     * as strings; values that look numeric are NEVER cast to int. Returns an
     * existing GloTicket or creates one for this single ticket only.
     */
    public function resolveExactTicket(Draw $draw, string $product, string $ticketNumber, ?string $series): GloTicket
    {
        if ($product === 'l6' && ! preg_match('/^\d{6}$/', $ticketNumber)) {
            throw GloFreezeException::invalidTicket('L6 ticket_number must be exactly 6 digits');
        }

        if ($product === 'n3' && ! preg_match('/^\d{3,6}$/', $ticketNumber)) {
            throw GloFreezeException::invalidTicket('N3 ticket_number must be 3–6 digits');
        }

        $query = GloTicket::query()
            ->where('draw_id', $draw->getKey())
            ->where('product', $product)
            ->where('ticket_number', $ticketNumber);

        if ($series === null) {
            $query->whereNull('set_series');
        } else {
            $query->where('set_series', $series);
        }

        $existing = $query->lockForUpdate()->first();

        if ($existing !== null) {
            return $existing;
        }

        return GloTicket::create([
            'draw_id' => (int) $draw->getKey(),
            'product' => $product,
            'ticket_number' => $ticketNumber,
            'set_series' => $series,
            'owner_user_id' => null,
            'ticket_reference' => GloTicket::buildReference((int) $draw->getKey(), $product, $ticketNumber, $series),
            'metadata' => ['source' => 'freeze_request'],
        ]);
    }

    public function hasCompleteEvidence(GloTicketFreeze $freeze): bool
    {
        return $freeze->evidence_reference !== ''
            && $freeze->requesting_authority !== ''
            && $freeze->case_reference !== ''
            && $freeze->evidence_type !== '';
    }

    public function requestFingerprint(
        int $drawId,
        string $product,
        string $ticketNumber,
        ?string $series,
        string $caseReference,
        string $evidenceReference,
    ): string {
        $canonical = implode('|', [
            (string) $drawId,
            $product,
            $ticketNumber,
            $series ?? '',
            $caseReference,
            $evidenceReference,
        ]);

        return hash('sha256', $canonical);
    }

    private function transition(
        GloTicketFreeze $freeze,
        GloFreezeStatus $target,
        User $actor,
        string $auditType,
        ?string $reason,
        array $extra = [],
    ): GloTicketFreeze {
        return $this->db->connection()->transaction(function () use ($freeze, $target, $actor, $auditType, $reason, $extra): GloTicketFreeze {
            $fresh = GloTicketFreeze::query()->whereKey($freeze->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                throw GloFreezeException::notFound((string) $freeze->freeze_case_id);
            }

            if (! $fresh->status->canTransitionTo($target)) {
                throw GloFreezeException::illegalTransition($fresh->status->value, $target->value);
            }

            $previous = $fresh->status->value;

            $fresh->status = $target;
            $fresh->reviewer_id = $actor->getKey();

            if ($reason !== null && $reason !== '') {
                $fresh->resolution_reason = Str::limit($reason, 500, '');
            }

            foreach ($extra as $field => $value) {
                $fresh->{$field} = $value;
            }

            $fresh->save();

            $this->audit($actor, $fresh, $auditType, RiskLevel::High, [
                'from' => $previous,
                'to' => $target->value,
                'reason' => $reason,
            ]);

            $freeze->refresh();

            return $fresh;
        });
    }

    private function normalizeTicketNumber(string $value): string
    {
        $trimmed = trim($value);

        // Reject scientific notation / int-like coercion paths early.
        if ($trimmed === '' || ! preg_match('/^\d{3,6}$/', $trimmed)) {
            throw GloFreezeException::invalidTicket('ticket_number must be a digit string (3–6 digits)');
        }

        return $trimmed;
    }

    private function normalizeSeries(?string $series): ?string
    {
        if ($series === null) {
            return null;
        }

        $trimmed = trim($series);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/\d{10,}/', $trimmed)) {
            throw GloFreezeException::invalidTicket('set/series must not look like a raw identifier dump');
        }

        return Str::upper(Str::limit($trimmed, 32, ''));
    }

    private function parseExpiry(?string $expiry, Draw $draw): ?Carbon
    {
        if ($expiry !== null && $expiry !== '') {
            try {
                return Carbon::parse($expiry);
            } catch (\Throwable) {
                throw GloFreezeException::invalidTicket('expiry_at is not a valid timestamp');
            }
        }

        // Default limitation window: 2 years from the scheduled draw date
        // (official freeze-to-delay context mentions a two-year limitation).
        $years = (int) config('glo.freeze.request_limitation_years', 2);

        return $draw->scheduled_at->copy()->addYears($years);
    }

    private function audit(User $actor, GloTicketFreeze $freeze, string $type, RiskLevel $risk, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $actor->getKey(),
            'action' => AuditAction::Update,
            'risk_level' => $risk,
            'auditable_type' => GloTicketFreeze::class,
            'auditable_id' => $freeze->getKey(),
            'description' => $type,
            'metadata' => array_merge([
                'action_type' => $type,
                'freeze_case_id' => $freeze->freeze_case_id,
                'ticket_reference' => $freeze->ticket?->ticket_reference,
                'status' => $freeze->status->value,
            ], $metadata),
        ]);
    }

    /**
     * When no super-admin exists (bare test/dev DB), use a detached system
     * actor record only as last resort for scheduled expiry sweeps.
     */
    private function systemPlaceholderActor(): User
    {
        $suffix = Str::lower(Str::random(12));
        $user = new User([
            'name' => 'GLO System',
            'email' => 'glo-system-'.$suffix.'@localhost.invalid',
            'username' => 'glo_system_'.$suffix,
            'password' => '!',
        ]);
        $user->status = \App\Enums\UserStatus::Active;
        $user->save();

        return $user;
    }
}
