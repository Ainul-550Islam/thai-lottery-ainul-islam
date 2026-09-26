<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\DrawStatus;
use App\Enums\GloClaimChannel;
use App\Enums\GloClaimStatus;
use App\Enums\GloPaymentHoldStatus;
use App\Enums\RiskLevel;
use App\Exceptions\GloClaimException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\GloPrizeClaim;
use App\Models\GloPrizePaymentHold;
use App\Models\GloTicket;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * GLO-12 prize claim lifecycle + GLO-14 payment gate.
 *
 * Fail-closed payment conditions (all must pass; any failure refuses money):
 *  1. ticket is a verified winner for the draw (official checker + published result)
 *  2. official result is published / verified
 *  3. settlement/draw finality (Completed) OR config-equivalent finality
 *  4. claim status = Approved (not hold/pending/rejected/paid)
 *  5. no active effective freeze (GloTicketFreezeService::hasActiveEffectiveFreeze)
 *  6. no active GloPrizePaymentHold
 *  7. age ≥ 20 computed server-side from users.date_of_birth vs payment date
 *  8. claimant identity verified (KYC verified evidence)
 *  9. claim window (2 years from draw scheduled date) not expired
 * 10. stamp duty present & matches calculator (ceil(gross/200)×1)
 * 11. no unresolved legal conflict / ALREADY_PAID uniqueness
 *
 * Client payload may NEVER set status, gross, duty, net, age, is_frozen or
 * payment_hold — those are computed here under row locks.
 */
class GloPrizeClaimService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloStampDutyCalculator $duty,
        private readonly GloTicketChecker $checker,
        private readonly GloResultService $results,
        private readonly GloTicketFreezeService $freezes,
        private readonly GloFrozenWinnerService $frozenWinners,
    ) {}

    /**
     * Submit a claim (claimant-initiated). Computes money and eligibility
     * server-side; stores claim as Pending or Hold when the ticket is already
     * frozen.
     *
     * @param array{
     *     ticket_id: int,
     *     prize_category: string,
     *     claim_channel: string,
     *     original_ticket_evidenced?: bool,
     *     identity_document_evidenced?: bool,
     * } $input
     */
    public function submit(array $input, User $claimant): GloPrizeClaim
    {
        $ticketId = (int) ($input['ticket_id'] ?? 0);
        $category = strtolower(trim((string) ($input['prize_category'] ?? '')));
        $channelValue = (string) ($input['claim_channel'] ?? '');

        $channel = GloClaimChannel::tryFrom($channelValue);

        if ($channel === null) {
            throw GloClaimException::unauthorized('submit claims on an unknown channel');
        }

        $ticket = GloTicket::query()->find($ticketId);

        if ($ticket === null) {
            throw GloClaimException::ticketNotFound();
        }

        $draw = Draw::query()->findOrFail((int) $ticket->draw_id);

        // Identity must already be verified evidence, not a self-assertion.
        if (! $claimant->kycStatus()->isVerified()) {
            throw GloClaimException::identityNotVerified();
        }

        $this->assertWinner($draw, $ticket, $category);
        $this->assertClaimWindow($draw);

        $win = $this->configuredWin($draw, $ticket, $category);

        $gross = $win['gross'];
        $stamp = $this->duty->dutyFor($gross);
        $net = $this->duty->netAfterDuty($gross);

        // Age from authoritative DOB — never from request body.
        $ageYears = $claimant->ageAt(now());
        $minAge = (int) config('glo.claims.min_claimant_age', 20);

        if ($ageYears === null) {
            $ageResult = 'unverified';
            $ageVerifiedAt = null;
        } elseif ($ageYears < $minAge) {
            // Persisting an under-age claim is allowed only as REJECTED —
            // payment path will also refuse; we refuse at submit to fail closed.
            throw GloClaimException::underAge($ageYears, $minAge);
        } else {
            $ageResult = 'verified';
            $ageVerifiedAt = now();
        }

        $originalEvidenced = (bool) ($input['original_ticket_evidenced'] ?? false);
        $physicalRequiresOriginal = (bool) config('glo.claims.physical_requires_original_ticket', true);

        if ($physicalRequiresOriginal && $channel->isPhysical() && ! $originalEvidenced) {
            throw GloClaimException::originalTicketRequired();
        }

        $identityEvidenced = (bool) ($input['identity_document_evidenced'] ?? $claimant->kycStatus()->isVerified());

        $fingerprint = hash('sha256', implode('|', [
            'claim',
            (string) $ticket->getKey(),
            (string) $claimant->getKey(),
            $category,
        ]));

        return $this->db->connection()->transaction(function () use (
            $input,
            $claimant,
            $ticket,
            $draw,
            $category,
            $channel,
            $gross,
            $stamp,
            $net,
            $ageYears,
            $ageResult,
            $ageVerifiedAt,
            $originalEvidenced,
            $identityEvidenced,
            $fingerprint,
        ): GloPrizeClaim {
            $existing = GloPrizeClaim::query()
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw GloClaimException::duplicateClaim($existing->claim_reference);
            }

            $frozen = $this->freezes->hasActiveEffectiveFreeze((int) $ticket->getKey());
            $status = $frozen ? GloClaimStatus::Hold : GloClaimStatus::Pending;
            $holdStatus = $frozen ? 'active' : 'none';
            $paymentStatus = $frozen
                ? (string) config('glo.claims.payment_status_blocked', 'blocked')
                : 'pending';

            if ($frozen) {
                // Ensure a hold row exists for this frozen ticket even before
                // the frozen-winner sweep runs (claim may reference a prize
                // while freeze is already active).
                $this->ensureHoldForClaimTicket($ticket, $draw, $category, $frozen);
            }

            $claim = GloPrizeClaim::create([
                'claim_reference' => 'GLOCLM-'.Str::upper(Str::random(16)),
                'ticket_id' => $ticket->getKey(),
                'draw_id' => (int) $draw->getKey(),
                'product' => (string) $ticket->product,
                'prize_category' => $category,
                'ticket_number' => (string) $ticket->ticket_number,
                'gross_prize' => $gross,
                'stamp_duty' => $stamp,
                'net_prize' => $net,
                'claimant_user_id' => $claimant->getKey(),
                'identity_reference' => 'kyc:'.$claimant->getKey(),
                'age_verification_result' => $ageResult,
                'age_verified_at' => $ageVerifiedAt,
                'verified_age_years' => $ageYears,
                'original_ticket_evidenced' => $originalEvidenced,
                'identity_document_evidenced' => $identityEvidenced,
                'claim_channel' => $channel,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'hold_status' => $holdStatus,
                'hold_reason' => $frozen ? (string) config('glo.claims.hold_reason_frozen', 'FROZEN_TICKET') : null,
                'submitted_at' => now(),
                'fingerprint' => $fingerprint,
            ]);

            $this->audit($claimant, $claim, 'glo_claim_submitted', RiskLevel::High, [
                'channel' => $channel->value,
                'status' => $status->value,
                'product' => $ticket->product,
            ]);

            return $claim;
        });
    }

    /**
     * Operator review: Pending → Eligible (or reject). Hold cannot be
     * reviewer-cleared without freeze release.
     */
    public function reviewEligible(GloPrizeClaim $claim, User $reviewer): GloPrizeClaim
    {
        return $this->transitionClaim($claim, GloClaimStatus::Eligible, $reviewer, 'glo_claim_reviewed_eligible', null, [
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->getKey(),
        ]);
    }

    public function reject(GloPrizeClaim $claim, User $reviewer, string $reason): GloPrizeClaim
    {
        if ($claim->status === GloClaimStatus::Paid) {
            throw GloClaimException::alreadyPaid($claim->claim_reference);
        }

        return $this->transitionClaim($claim, GloClaimStatus::Rejected, $reviewer, 'glo_claim_rejected', $reason, [
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->getKey(),
            'payment_status' => 'cancelled',
        ]);
    }

    public function cancel(GloPrizeClaim $claim, User $actor, string $reason): GloPrizeClaim
    {
        if ($claim->status === GloClaimStatus::Paid) {
            throw GloClaimException::alreadyPaid($claim->claim_reference);
        }

        return $this->transitionClaim($claim, GloClaimStatus::Cancelled, $actor, 'glo_claim_cancelled', $reason, [
            'payment_status' => 'cancelled',
        ]);
    }

    /**
     * Eligible → Approved after re-checking every freeze/hold/age/settlement
     * condition under lock (GLO-14 atomic checks subset that applies pre-pay).
     */
    public function approve(GloPrizeClaim $claim, User $approver): GloPrizeClaim
    {
        return $this->db->connection()->transaction(function () use ($claim, $approver): GloPrizeClaim {
            $fresh = GloPrizeClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === GloClaimStatus::Paid) {
                throw GloClaimException::alreadyPaid($fresh->claim_reference);
            }

            // Hold is fail-closed: surface FROZEN_TICKET when a freeze is still
            // legally effective; otherwise the transition itself is illegal.
            if ($fresh->status === GloClaimStatus::Hold) {
                $holdTicketId = (int) $fresh->ticket_id;

                if ($this->freezes->hasActiveEffectiveFreeze($holdTicketId)) {
                    throw GloClaimException::frozenTicket();
                }

                $activeHold = GloPrizePaymentHold::query()
                    ->where('ticket_id', $holdTicketId)
                    ->where('status', GloPaymentHoldStatus::Active)
                    ->exists();

                if ($activeHold) {
                    throw GloClaimException::paymentHoldActive();
                }

                throw GloClaimException::illegalTransition($fresh->status->value, GloClaimStatus::Approved->value);
            }

            if (! in_array($fresh->status, [GloClaimStatus::Pending, GloClaimStatus::Eligible], true)) {
                throw GloClaimException::illegalTransition($fresh->status->value, GloClaimStatus::Approved->value);
            }

            // Full fail-closed revalidation before APPROVED.
            $this->assertPaymentConditions($fresh, $approver, forFinalPayment: false);

            $previous = $fresh->status->value;
            $fresh->status = GloClaimStatus::Approved;
            $fresh->approved_at = now();
            $fresh->reviewed_at = $fresh->reviewed_at ?? now();
            $fresh->reviewed_by = $fresh->reviewed_by ?? $approver->getKey();
            $fresh->save();

            $this->audit($approver, $fresh, 'glo_claim_approved', RiskLevel::High, [
                'from' => $previous,
                'to' => GloClaimStatus::Approved->value,
            ]);

            return $fresh;
        });
    }

    /**
     * Execute payment for an APPROVED claim. Single writer of Paid status.
     * Concurrency: lockForUpdate + unique payment_transaction_reference; the
     * second worker sees ALREADY_PAID.
     */
    public function pay(GloPrizeClaim $claim, User $paymentOperator, ?string $transactionReference = null): GloPrizeClaim
    {
        return $this->db->connection()->transaction(function () use ($claim, $paymentOperator, $transactionReference): GloPrizeClaim {
            $fresh = GloPrizeClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === GloClaimStatus::Paid || $fresh->payment_transaction_reference !== null) {
                throw GloClaimException::alreadyPaid($fresh->claim_reference);
            }

            if ($fresh->status !== GloClaimStatus::Approved) {
                throw GloClaimException::notApproved();
            }

            $this->assertPaymentConditions($fresh, $paymentOperator, forFinalPayment: true);

            $reference = $transactionReference
                ?? ('TXGLO-'.Str::upper(Str::random(20)));

            $fresh->status = GloClaimStatus::Paid;
            $fresh->payment_status = (string) config('glo.claims.payment_status_executed', 'executed');
            $fresh->hold_status = 'none';
            $fresh->paid_at = now();
            $fresh->paid_by = $paymentOperator->getKey();
            $fresh->payment_transaction_reference = $reference;
            $fresh->save();

            $this->audit($paymentOperator, $fresh, 'glo_claim_paid', RiskLevel::Critical, [
                'to' => GloClaimStatus::Paid->value,
                'payment_transaction_reference' => $reference,
                'net_prize' => $fresh->net_prize,
            ]);

            return $fresh;
        });
    }

    /**
     * Clear an active hold when freezes are no longer effective (released or
     * expired). Does NOT pay: claim returns to Eligible for normal approval.
     * Expired freeze never auto-pays — this method only removes the hold.
     */
    public function clearHoldsIfFreezesInactive(int $ticketId, User $actor): int
    {
        if ($this->freezes->hasActiveEffectiveFreeze($ticketId)) {
            return 0;
        }

        return $this->db->connection()->transaction(function () use ($ticketId, $actor): int {
            $cleared = 0;

            $holds = GloPrizePaymentHold::query()
                ->where('ticket_id', $ticketId)
                ->where('status', GloPaymentHoldStatus::Active)
                ->lockForUpdate()
                ->get();

            foreach ($holds as $hold) {
                $hold->status = GloPaymentHoldStatus::Cleared;
                $hold->released_at = now();
                $hold->release_reason = 'No active effective freeze remains';
                $hold->save();
                $cleared++;

                AuditLog::create([
                    'user_id' => $actor->getKey(),
                    'action' => AuditAction::Update,
                    'risk_level' => RiskLevel::High,
                    'auditable_type' => GloPrizePaymentHold::class,
                    'auditable_id' => $hold->getKey(),
                    'description' => 'glo_payment_hold_cleared',
                    'metadata' => [
                        'action_type' => 'glo_payment_hold_cleared',
                        'hold_reference' => $hold->hold_reference,
                        'auto_paid' => false,
                    ],
                ]);
            }

            $claims = GloPrizeClaim::query()
                ->where('ticket_id', $ticketId)
                ->where('status', GloClaimStatus::Hold)
                ->lockForUpdate()
                ->get();

            foreach ($claims as $claim) {
                // Re-validate before becoming eligible again.
                try {
                    $this->assertPaymentConditions($claim, $actor, forFinalPayment: false);
                    $claim->status = GloClaimStatus::Eligible;
                    $claim->hold_status = 'cleared';
                    $claim->payment_status = 'pending';
                    $claim->hold_reason = null;
                    $claim->save();
                } catch (GloClaimException) {
                    // Keep on hold / reject path decided by later review —
                    // never silently promote.
                    $claim->hold_status = 'cleared';
                    $claim->hold_reason = null;
                    $claim->save();
                }
            }

            return $cleared;
        });
    }

    /**
     * The complete fail-closed checklist. Every method invocation is a gate;
     * the first failure throws and no money moves.
     *
     * @throws GloClaimException
     */
    public function assertPaymentConditions(GloPrizeClaim $claim, User $actor, bool $forFinalPayment): void
    {
        $ticket = GloTicket::query()->find((int) $claim->ticket_id);

        if ($ticket === null) {
            throw GloClaimException::ticketNotFound();
        }

        $draw = Draw::query()->find((int) $claim->draw_id);

        if ($draw === null) {
            throw GloClaimException::settlementNotFinal();
        }

        // 1 + 2: official winner + published/verified result
        $recorded = $this->results->recordedForDraw((int) $draw->getKey());

        if (! ($recorded['has_result'] ?? false)) {
            throw GloClaimException::resultNotVerified();
        }

        // Re-verify winner against official numbers.
        $this->assertWinner($draw, $ticket, (string) $claim->prize_category);

        // 3: settlement finality
        if ($draw->status !== DrawStatus::Completed && $draw->status !== DrawStatus::ResultPublished) {
            throw GloClaimException::settlementNotFinal();
        }

        if ($forFinalPayment && $draw->status !== DrawStatus::Completed) {
            // Final money requires Completed settlement (ResultPublished alone
            // is insufficient unless config marks it final — default fail-closed).
            $publishedIsFinal = (bool) config('glo.claims.result_published_is_final', false);

            if (! $publishedIsFinal) {
                throw GloClaimException::settlementNotFinal();
            }
        }

        // 4: claim status shape (caller also checks, re-check under lock)
        if ($claim->status === GloClaimStatus::Paid) {
            throw GloClaimException::alreadyPaid($claim->claim_reference);
        }

        if ($forFinalPayment && $claim->status !== GloClaimStatus::Approved) {
            throw GloClaimException::notApproved();
        }

        // 5: active freezes block
        if ($this->freezes->hasActiveEffectiveFreeze((int) $ticket->getKey())) {
            throw GloClaimException::frozenTicket();
        }

        // 6: active payment holds block
        $activeHold = GloPrizePaymentHold::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('status', GloPaymentHoldStatus::Active)
            ->exists();

        if ($activeHold) {
            throw GloClaimException::paymentHoldActive();
        }

        // 7: age ≥ 20 from authoritative DOB vs payment/claim date
        $claimant = User::query()->find((int) $claim->claimant_user_id);

        if ($claimant === null) {
            throw GloClaimException::identityNotVerified();
        }

        $ageAt = $claimant->ageAt(now());
        $minAge = (int) config('glo.claims.min_claimant_age', 20);

        if ($ageAt === null) {
            throw GloClaimException::ageUnverifiable();
        }

        if ($ageAt < $minAge) {
            throw GloClaimException::underAge($ageAt, $minAge);
        }

        // 8: identity
        if (! $claimant->kycStatus()->isVerified()) {
            throw GloClaimException::identityNotVerified();
        }

        // 9: claim window
        $this->assertClaimWindow($draw);

        // 10: stamp duty integrity
        $expectedDuty = $this->duty->dutyFor((string) $claim->gross_prize);

        if (bccomp($expectedDuty, (string) $claim->stamp_duty, 2) !== 0) {
            throw GloClaimException::stampDutyMissing();
        }

        $expectedNet = $this->duty->netAfterDuty((string) $claim->gross_prize);

        if (bccomp($expectedNet, (string) $claim->net_prize, 2) !== 0) {
            throw GloClaimException::stampDutyMissing();
        }

        // 11: legal conflict gates (extendable list; empty by default)
        $conflictGate = (string) config('glo.claims.blocking_conflict_gate', '');

        if ($conflictGate !== '') {
            throw GloClaimException::legalConflictUnresolved($conflictGate);
        }
    }

    public function assertClaimWindow(Draw $draw): void
    {
        $years = (int) config('glo.claims.window_years', (int) config('glo.claim.window_years', 2));
        $deadline = $draw->scheduled_at->copy()->addYears($years)->endOfDay();

        if (now()->greaterThan($deadline)) {
            throw GloClaimException::claimWindowExpired($deadline->toIso8601String());
        }
    }

    private function assertWinner(Draw $draw, GloTicket $ticket, string $category): void
    {
        $recorded = $this->results->recordedForDraw((int) $draw->getKey());

        if (! ($recorded['has_result'] ?? false)) {
            throw GloClaimException::resultNotVerified();
        }

        if ($ticket->product !== 'l6') {
            // N3: require a recorded win flag in metadata when present.
            // Fail closed when no N3 win evidence exists.
            $meta = $recorded;
            $n3Wins = $meta['n3_wins'] ?? [];

            $wonN3 = false;

            foreach (is_array($n3Wins) ? $n3Wins : [] as $win) {
                if (is_array($win)
                    && (string) ($win['ticket_number'] ?? '') === (string) $ticket->ticket_number
                    && strtolower((string) ($win['category'] ?? '')) === $category) {
                    $wonN3 = true;
                    break;
                }
            }

            if (! $wonN3) {
                throw GloClaimException::notAWinner();
            }

            return;
        }

        try {
            $check = $this->checker->check((int) $draw->getKey(), (string) $ticket->ticket_number);
        } catch (\InvalidArgumentException) {
            throw GloClaimException::notAWinner();
        }

        if (! ($check['won'] ?? false)) {
            throw GloClaimException::notAWinner();
        }

        $tiers = [];

        foreach (($check['matches'] ?? []) as $match) {
            $tiers[] = (string) ($match['tier'] ?? '');
        }

        if ($category !== '' && ! in_array($category, $tiers, true) && $category !== 'any') {
            // Allow claim on dominant category only when it matches a real tier.
            throw GloClaimException::notAWinner();
        }
    }

    /**
     * @return array{gross: string, tier: string}
     */
    private function configuredWin(Draw $draw, GloTicket $ticket, string $category): array
    {
        $prizes = (array) config('glo.prizes', []);
        $entry = $prizes[$category] ?? null;

        if (! is_array($entry) || ! isset($entry['amount'])) {
            throw GloClaimException::notAWinner();
        }

        // Multiple tiers: amount is per-ticket for that tier (official ladder).
        $gross = bcadd((string) $entry['amount'], '0.00', 2);

        return ['gross' => $gross, 'tier' => $category];
    }

    private function ensureHoldForClaimTicket(GloTicket $ticket, Draw $draw, string $category, bool $frozen): void
    {
        if (! $frozen) {
            return;
        }

        $active = GloPrizePaymentHold::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('status', GloPaymentHoldStatus::Active)
            ->exists();

        if ($active) {
            return;
        }

        $freezes = $this->freezes->activeFreezes((int) $ticket->getKey());

        if ($freezes->isEmpty()) {
            return;
        }

        $gross = null;
        $stamp = null;
        $prizes = (array) config('glo.prizes', []);
        $entry = $prizes[$category] ?? null;

        if (is_array($entry) && isset($entry['amount'])) {
            $gross = bcadd((string) $entry['amount'], '0.00', 2);
            $stamp = $this->duty->dutyFor($gross);
        }

        GloPrizePaymentHold::create([
            'hold_reference' => 'GLOHOLD-'.Str::upper(Str::random(16)),
            'claim_id' => null,
            'freeze_id' => $freezes->first()->getKey(),
            'ticket_id' => $ticket->getKey(),
            'draw_id' => (int) $draw->getKey(),
            'winning_category' => $category,
            'gross_prize' => $gross,
            'stamp_duty' => $stamp,
            'hold_reason' => (string) config('glo.claims.hold_reason_frozen', 'FROZEN_TICKET'),
            'status' => GloPaymentHoldStatus::Active,
            'created_at' => now(),
            'fingerprint' => hash('sha256', 'claim-hold|'.$ticket->getKey().'|'.$category),
        ]);
    }

    private function transitionClaim(
        GloPrizeClaim $claim,
        GloClaimStatus $target,
        User $actor,
        string $auditType,
        ?string $reason,
        array $extra = [],
    ): GloPrizeClaim {
        return $this->db->connection()->transaction(function () use ($claim, $target, $actor, $auditType, $reason, $extra): GloPrizeClaim {
            $fresh = GloPrizeClaim::query()->whereKey($claim->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->status->canTransitionTo($target)) {
                throw GloClaimException::illegalTransition($fresh->status->value, $target->value);
            }

            $previous = $fresh->status->value;
            $fresh->status = $target;

            if ($reason !== null && $reason !== '') {
                $fresh->rejection_reason = Str::limit($reason, 500, '');
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

            $claim->refresh();

            return $fresh;
        });
    }

    private function audit(User $actor, GloPrizeClaim $claim, string $type, RiskLevel $risk, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $actor->getKey(),
            'action' => AuditAction::Update,
            'risk_level' => $risk,
            'auditable_type' => GloPrizeClaim::class,
            'auditable_id' => $claim->getKey(),
            'description' => $type,
            'metadata' => array_merge([
                'action_type' => $type,
                'claim_reference' => $claim->claim_reference,
                'status' => $claim->status->value,
                'product' => $claim->product,
            ], $metadata),
        ]);
    }
}
