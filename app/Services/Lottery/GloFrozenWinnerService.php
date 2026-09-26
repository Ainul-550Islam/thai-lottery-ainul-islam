<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\GloPaymentHoldStatus;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\GloPrizeClaim;
use App\Models\GloPrizePaymentHold;
use App\Models\GloPublicTicketStatus;
use App\Models\GloTicket;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * GLO-12/13: detects winning tickets that are under an active freeze and
 * creates the payment hold + public announcement.
 *
 * Official context (glo.or.th freeze-to-delay): a frozen ticket that later
 * wins may be formally announced with prize payment delayed in the computer
 * system / website. This service implements that interaction:
 *
 *  - NEVER pays. Only holds.
 *  - Idempotent per (freeze, ticket, draw) via unique fingerprints.
 *  - On detection: GloPrizePaymentHold Active + claim.status = Hold
 *    (payment_status = blocked, hold_reason = FROZEN_TICKET) + public
 *    GloPublicTicketStatus announcement WITHOUT claimant PII.
 *  - Expired/released freezes do not auto-pay: hold stays until an explicit
 *    hold-clear + normal approval path runs; expiry alone never executes
 *    money.
 */
class GloFrozenWinnerService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloTicketChecker $checker,
        private readonly GloResultService $results,
        private readonly GloTicketFreezeService $freezes,
        private readonly GloStampDutyCalculator $duty,
    ) {}

    /**
     * Scan a draw for frozen winning tickets and open payment holds.
     *
     * @return array{
     *     draw_id: int,
     *     scanned: int,
     *     holds_created: int,
     *     holds_existing: int,
     *     claims_put_on_hold: int,
     *     dry_run: bool,
     *     details: list<array<string, mixed>>,
     * }
     */
    public function processDraw(int $drawId, bool $dryRun = false, ?User $actor = null): array
    {
        $draw = Draw::query()->find($drawId);

        if ($draw === null) {
            return [
                'draw_id' => $drawId,
                'scanned' => 0,
                'holds_created' => 0,
                'holds_existing' => 0,
                'claims_put_on_hold' => 0,
                'dry_run' => $dryRun,
                'details' => [],
                'error' => 'draw_not_found',
            ];
        }

        $recorded = $this->results->recordedForDraw($drawId);

        $details = [];
        $scanned = 0;
        $created = 0;
        $existing = 0;
        $claimsHeld = 0;

        $frozenTickets = GloTicket::query()
            ->where('draw_id', $drawId)
            ->whereHas('freezes', function ($query): void {
                $query->where('status', \App\Enums\GloFreezeStatus::Frozen);
            })
            ->get();

        foreach ($frozenTickets as $ticket) {
            $scanned++;

            if (! $this->freezes->hasActiveEffectiveFreeze((int) $ticket->getKey())) {
                $details[] = [
                    'ticket_reference' => $ticket->ticket_reference,
                    'outcome' => 'skipped_no_active_freeze',
                ];

                continue;
            }

            // L6 uses the official checker; N3 checks only when a recorded
            // result for the product exists (never invent N3 numbers).
            if ($ticket->product !== 'l6') {
                $details[] = [
                    'ticket_reference' => $ticket->ticket_reference,
                    'outcome' => 'skipped_product_not_checked',
                    'product' => $ticket->product,
                ];

                continue;
            }

            try {
                $check = $this->checker->check($drawId, (string) $ticket->ticket_number);
            } catch (\InvalidArgumentException) {
                $details[] = [
                    'ticket_reference' => $ticket->ticket_reference,
                    'outcome' => 'skipped_invalid_number',
                ];

                continue;
            }

            if (! ($check['won'] ?? false)) {
                $details[] = [
                    'ticket_reference' => $ticket->ticket_reference,
                    'outcome' => 'not_winning',
                ];

                continue;
            }

            $category = $this->dominantCategory($check['matches'] ?? []);

            if ($dryRun) {
                $details[] = [
                    'ticket_reference' => $ticket->ticket_reference,
                    'outcome' => 'would_hold',
                    'winning_category' => $category,
                    'total_prize' => (string) ($check['total_prize'] ?? '0.00'),
                ];

                continue;
            }

            $result = $this->holdWinningFrozenTicket($ticket, $draw, $category, $actor, $recorded);
            $details[] = $result['detail'];

            if ($result['detail']['outcome'] === 'hold_created') {
                $created++;
            } elseif ($result['detail']['outcome'] === 'hold_exists') {
                $existing++;
            }

            if ($result['claims_put_on_hold']) {
                $claimsHeld++;
            }
        }

        return [
            'draw_id' => $drawId,
            'scanned' => $scanned,
            'holds_created' => $created,
            'holds_existing' => $existing,
            'claims_put_on_hold' => $claimsHeld,
            'dry_run' => $dryRun,
            'details' => $details,
        ];
    }

    /**
     * Create (idempotent) the payment hold + public announcement and move any
     * linked claim into Hold. Never pays.
     *
     * @return array{detail: array<string, mixed>, claims_put_on_hold: bool}
     */
    public function holdWinningFrozenTicket(
        GloTicket $ticket,
        Draw $draw,
        string $winningCategory,
        ?User $actor,
        array $recorded = [],
    ): array {
        return $this->db->connection()->transaction(function () use ($ticket, $draw, $winningCategory, $actor, $recorded): array {
            $activeFreezes = $this->freezes->activeFreezes((int) $ticket->getKey());

            if ($activeFreezes->isEmpty()) {
                return [
                    'detail' => [
                        'ticket_reference' => $ticket->ticket_reference,
                        'outcome' => 'skipped_no_active_freeze',
                    ],
                    'claims_put_on_hold' => false,
                ];
            }

            $primaryFreeze = $activeFreezes->first();

            $gross = $this->configuredGross($winningCategory);
            $stamp = $gross !== null ? $this->duty->dutyFor($gross) : null;

            $fingerprint = hash('sha256', implode('|', [
                'hold',
                (string) $primaryFreeze->getKey(),
                (string) $ticket->getKey(),
                (string) $draw->getKey(),
                $winningCategory,
            ]));

            $hold = GloPrizePaymentHold::query()
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($hold === null) {
                // Unique-per-ticket active hold safety: one active hold per ticket.
                $hold = GloPrizePaymentHold::query()
                    ->where('ticket_id', $ticket->getKey())
                    ->where('status', GloPaymentHoldStatus::Active)
                    ->lockForUpdate()
                    ->first();
            }

            if ($hold !== null) {
                $this->synchronizeClaimHold($ticket, $hold, false);

                return [
                    'detail' => [
                        'ticket_reference' => $ticket->ticket_reference,
                        'outcome' => 'hold_exists',
                        'hold_reference' => $hold->hold_reference,
                        'winning_category' => $hold->winning_category,
                    ],
                    'claims_put_on_hold' => $this->claimWasTransitioned($ticket),
                ];
            }

            $hold = GloPrizePaymentHold::create([
                'hold_reference' => 'GLOHOLD-'.Str::upper(Str::random(16)),
                'claim_id' => null,
                'freeze_id' => $primaryFreeze->getKey(),
                'ticket_id' => $ticket->getKey(),
                'draw_id' => (int) $draw->getKey(),
                'winning_category' => $winningCategory,
                'gross_prize' => $gross,
                'stamp_duty' => $stamp,
                'hold_reason' => (string) config('glo.claims.hold_reason_frozen', 'FROZEN_TICKET'),
                'status' => GloPaymentHoldStatus::Active,
                'created_at' => now(),
                'fingerprint' => $fingerprint,
            ]);

            $claimsPutOnHold = $this->synchronizeClaimHold($ticket, $hold, true);
            $this->publishPublicAnnouncement($ticket, $draw, $primaryFreeze, $winningCategory);

            if ($actor !== null) {
                AuditLog::create([
                    'user_id' => $actor->getKey(),
                    'action' => AuditAction::Update,
                    'risk_level' => RiskLevel::High,
                    'auditable_type' => GloPrizePaymentHold::class,
                    'auditable_id' => $hold->getKey(),
                    'description' => 'glo_frozen_winner_hold_created',
                    'metadata' => [
                        'action_type' => 'glo_frozen_winner_hold_created',
                        'hold_reference' => $hold->hold_reference,
                        'ticket_reference' => $ticket->ticket_reference,
                        'winning_category' => $winningCategory,
                        'hold_reason' => $hold->hold_reason,
                    ],
                ]);
            }

            return [
                'detail' => [
                    'ticket_reference' => $ticket->ticket_reference,
                    'outcome' => 'hold_created',
                    'hold_reference' => $hold->hold_reference,
                    'winning_category' => $winningCategory,
                    'gross_prize' => $gross,
                ],
                'claims_put_on_hold' => $claimsPutOnHold,
            ];
        });
    }

    /**
     * Public-safe announcement only — no PII, no evidence, no staff ids.
     */
    public function publishPublicAnnouncement(
        GloTicket $ticket,
        Draw $draw,
        \App\Models\GloTicketFreeze $freeze,
        string $winningCategory,
    ): GloPublicTicketStatus {
        $fingerprint = hash('sha256', 'announce|'.$ticket->getKey().'|'.$draw->getKey());

        $existing = GloPublicTicketStatus::query()
            ->where('draw_id', $draw->getKey())
            ->where('ticket_id', $ticket->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return GloPublicTicketStatus::create([
            'announcement_id' => 'GLOANN-'.Str::upper(Str::random(16)),
            'draw_id' => (int) $draw->getKey(),
            'ticket_id' => $ticket->getKey(),
            'freeze_id' => $freeze->getKey(),
            'ticket_reference' => (string) $ticket->ticket_reference,
            'product' => (string) $ticket->product,
            'prize_category' => $winningCategory,
            'status' => 'FROZEN_AND_WINNING_PAYMENT_HELD',
            'published_at' => now(),
            'effective_hold_at' => now(),
            'source_authority' => (string) $freeze->requesting_authority,
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * Move every non-terminal claim on this ticket into Hold and attach the
     * hold record. Returns true when at least one claim transitioned.
     */
    private function synchronizeClaimHold(GloTicket $ticket, GloPrizePaymentHold $hold, bool $persist): bool
    {
        $changed = false;

        $claims = GloPrizeClaim::query()
            ->where('ticket_id', $ticket->getKey())
            ->whereIn('status', [\App\Enums\GloClaimStatus::Pending, \App\Enums\GloClaimStatus::Eligible, \App\Enums\GloClaimStatus::Approved])
            ->lockForUpdate()
            ->get();

        foreach ($claims as $claim) {
            $claim->status = \App\Enums\GloClaimStatus::Hold;
            $claim->payment_status = (string) config('glo.claims.payment_status_blocked', 'blocked');
            $claim->hold_status = 'active';
            $claim->hold_reason = (string) config('glo.claims.hold_reason_frozen', 'FROZEN_TICKET');
            // Approved money must not remain executable while held.
            $claim->save();
            $changed = true;

            $hold->claim_id = $claim->getKey();
            $hold->save();
        }

        return $changed;
    }

    private function claimWasTransitioned(GloTicket $ticket): bool
    {
        return GloPrizeClaim::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('status', \App\Enums\GloClaimStatus::Hold)
            ->exists();
    }

    private function dominantCategory(array $matches): string
    {
        $order = ['first', 'adjacent_first', 'second', 'third', 'fourth', 'fifth', 'front_three', 'last_three', 'last_two'];

        $seen = [];

        foreach ($matches as $match) {
            $tier = (string) ($match['tier'] ?? '');
            if ($tier !== '') {
                $seen[$tier] = true;
            }
        }

        foreach ($order as $tier) {
            if (isset($seen[$tier])) {
                return $tier;
            }
        }

        return 'unknown';
    }

    private function configuredGross(string $category): ?string
    {
        $prizes = (array) config('glo.prizes', []);
        $entry = $prizes[$category] ?? null;

        if (! is_array($entry) || ! isset($entry['amount'])) {
            return null;
        }

        return bcadd((string) $entry['amount'], '0.00', 2);
    }
}
