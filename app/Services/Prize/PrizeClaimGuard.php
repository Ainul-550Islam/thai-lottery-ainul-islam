<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\Enums\BetStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizeClaimStatus;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Exceptions\PrizeClaimException;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Payment\PayoutTransferAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Exact player-claim identity and financial-lane isolation. Never repairs links or infers paid money. */
final class PrizeClaimGuard
{
    public function resolve(Bet $bet, bool $lock = false): ?Payout
    {
        $query = Payout::withTrashed()->where('bet_id', $bet->id)->orderBy('id')->limit(2);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->count() !== 1) {
            return null;
        }
        $row = $rows->first();
        if ($bet->payout_id !== null && (int) $bet->payout_id !== (int) $row->id) {
            return null; // A dangling/foreign direct pointer is not permission to fall back to another row.
        }
        try {
            $this->assertIdentity($bet, $row);
        } catch (PrizeClaimException) {
            return null;
        }

        return $row;
    }

    public function assertIdentity(Bet $bet, Payout $row): void
    {
        if ($bet->trashed() || $row->trashed() || $bet->status !== BetStatus::Won || (int) $row->bet_id !== (int) $bet->id
            || (int) $bet->user_id !== (int) $row->user_id || (int) $bet->draw_id !== (int) $row->draw_id
            || ($bet->payout_id !== null && (int) $bet->payout_id !== (int) $row->id)
            || $bet->currency !== $row->currency || $bet->ticket_id !== $row->ticket_id
            || bccomp((string) $bet->actual_payout, '0', 2) <= 0 || bccomp((string) $bet->actual_payout, (string) $row->amount, 2) !== 0) {
            $this->refuse($row);
        }
        if ($bet->ticket_id !== null) {
            $ticket = Ticket::withTrashed()->find($bet->ticket_id);
            if (! $ticket || $ticket->trashed() || $ticket->status !== TicketStatus::Confirmed
                || (int) $ticket->user_id !== (int) $bet->user_id || (int) $ticket->draw_id !== (int) $bet->draw_id
                || $ticket->currency !== $bet->currency) {
                $this->refuse($row);
            }
        }
    }

    public function assertClaimant(Payout $row, int $userId): void
    {
        $user = User::query()->find($userId);
        if (! $user || $user->status !== UserStatus::Active || (int) $row->user_id !== $userId) {
            $this->refuse($row);
        }
    }

    public function reviewActor(Payout $row, int $id): User
    {
        $actor = app(PayoutTransferAccess::class)->authorize(User::query()->find($id), PayoutTransferAccess::CLAIM_REVIEW);
        if ((int) $row->user_id === (int) $actor->id) {
            $this->refuse($row);
        }

        return $actor;
    }

    public function protected(Payout $row): bool
    {
        $m = $row->metadata ?? [];
        if ($row->trashed() || $row->status !== PayoutStatus::Pending || $row->financial_transaction_id !== null
            || array_key_exists('approval', $m) || array_key_exists('batch', $m) || array_key_exists('transfer', $m)
            || data_get($m, 'claim.status') === PrizeClaimStatus::Paid->value) {
            return true;
        }
        foreach (['financial_provider_reference_owners', 'payout_batch_members', 'payout_transfer_evidence', 'payout_transfer_references', 'outbound_provider_references', 'prize_disbursements'] as $table) {
            if (DB::table($table)->where('payout_id', $row->id)->exists()) {
                return true;
            }
        }

        if (FinancialTransaction::withTrashed()->where('reference_type', Payout::class)->where('reference_id', $row->id)->exists()
            || LedgerEntry::withTrashed()->where('reference_type', Payout::class)->where('reference_id', $row->id)->exists()) {
            return true;
        }

        return Payment::withTrashed()->where('payable_type', Payout::class)->where('payable_id', $row->id)->exists();
    }

    public function assertMutable(Payout $row): void
    {
        if ($this->protected($row)) {
            $this->refuse($row);
        }
    }

    /** If a real player claim exists, money approval cannot bypass its review or original snapshot. */
    public function assertPayableClaim(Payout $row): void
    {
        $claim = $row->metadata['claim'] ?? [];
        if (! is_array($claim)) {
            $this->refuse($row);
        }
        if (! array_key_exists('claimant_user_id', $claim) && ! array_key_exists('claimed_at', $claim) && ! array_key_exists('status', $claim)) {
            return; // A maker's destination record is not a fabricated player claim.
        }
        $this->assertSnapshot($row, $claim);
        if (array_key_exists('approval', $row->metadata ?? []) && ! is_array($row->metadata['approval'])) {
            $this->refuse($row);
        }
        if (! array_key_exists('approval', $row->metadata ?? []) && $this->protected($row)) {
            $this->refuse($row);
        }
        if (! array_key_exists('approval', $row->metadata ?? []) && ! app(ClaimWindowService::class)->allowsClaim($row)) {
            $this->refuse($row);
        }
        $allowed = $row->status === PayoutStatus::Completed ? ['approved', 'paid'] : ['approved'];
        if (! in_array($claim['status'] ?? null, $allowed, true)) {
            $this->refuse($row);
        }
        if (($claim['submitted_status'] ?? null) === 'under_review') {
            $this->reviewActor($row, (int) ($claim['approved_by'] ?? 0));
        }
    }

    public function assertSnapshot(Payout $row, array $claim): void
    {
        $bet = Bet::withTrashed()->find($row->bet_id);
        if (! $bet || (int) ($claim['claimant_user_id'] ?? 0) !== (int) $row->user_id
            || ! is_string($claim['facts_hash'] ?? null) || ! hash_equals($this->fingerprint($row), $claim['facts_hash'])) {
            $this->refuse($row);
        }
        $this->assertIdentity($bet, $row);
        if ($this->resolve($bet)?->id !== $row->id
            || ! $this->deadline($claim['claimed_at'] ?? null) || ! $this->deadline($claim['window_closes_at'] ?? null)
            || data_get($row->metadata, 'claim_window.window_closes_at') !== $claim['window_closes_at']
            || ! $this->hasWindowSnapshot($row)
            || ($claim['window_facts_hash'] ?? null) !== data_get($row->metadata, 'claim_window.facts_hash')) {
            $this->refuse($row);
        }
        if (($claim['status'] ?? null) === 'paid') {
            app(PrizeClaimCompletionService::class)->assertEvidence($row);
            if ((int) ($claim['financial_transaction_id'] ?? 0) !== (int) $row->financial_transaction_id) {
                $this->refuse($row);
            }
        }
        $this->assertClaimant($row, (int) $claim['claimant_user_id']);
    }

    public function fingerprint(Payout $row): string
    {
        return hash('sha256', json_encode([(int) $row->id, $row->reference_number, (int) $row->bet_id,
            (int) $row->draw_id, (int) $row->user_id, $row->ticket_id, $row->wallet_id, (string) $row->amount, $row->currency->value], JSON_THROW_ON_ERROR));
    }

    public function submissionStatus(string $amount): PrizeClaimStatus
    {
        $raw = config('lottery.claims.review_threshold');
        if ((! is_string($raw) && ! is_int($raw)) || strlen((string) $raw) > 21 || ! preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $raw)) {
            return PrizeClaimStatus::UnderReview;
        }

        return bccomp($amount, (string) $raw, 2) >= 0 ? PrizeClaimStatus::UnderReview : PrizeClaimStatus::Approved;
    }

    public function windowFingerprint(Payout $row, string $deadline): string
    {
        return hash('sha256', json_encode([$this->fingerprint($row), $deadline], JSON_THROW_ON_ERROR));
    }

    public function hasWindowSnapshot(Payout $row): bool
    {
        $w = $row->metadata['claim_window'] ?? null;

        return is_array($w) && $this->deadline($w['window_closes_at'] ?? null) !== null
            && ($w['facts_hash'] ?? null) === $this->windowFingerprint($row, $w['window_closes_at']);
    }

    /** Only absolute ISO instants are usable; malformed/missing dates do not become a new future deadline. */
    public function deadline(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                return null;
            }

            return Carbon::instance($date);
        } catch (\Throwable) {
            return null;
        }
    }

    public function refuse(Payout $row): never
    {
        throw PrizeClaimException::stateForbids((int) $row->id, 'incompatible_or_financially_managed', 'legacy_claim_mutation');
    }
}
