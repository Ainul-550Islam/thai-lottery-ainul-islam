<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\Enums\AuditAction;
use App\Enums\ClaimWindowStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizeClaimStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PrizeClaimException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ClaimWindowService
{
    public const METADATA_KEY = 'claim_window';

    public const DEFAULT_WINDOW_DAYS = 730;

    public function windowDays(): int
    {
        $raw = config('lottery.claims.window_days', self::DEFAULT_WINDOW_DAYS);
        if ((! is_int($raw) && ! (is_string($raw) && ctype_digit($raw))) || (int) $raw < 1 || (int) $raw > 3650) {
            throw PrizeClaimException::stateForbids(0, 'invalid_window_days', 'configure_window');
        }

        return (int) $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function register(Payout $payout, Bet $bet, ?Carbon $openedAt = null): array
    {
        return DB::transaction(function () use ($payout, $bet, $openedAt): array {
            /** @var Payout $locked */
            $locked = Payout::withTrashed()->lockForUpdate()->findOrFail($payout->getKey());
            /** @var Bet $freshBet */
            $freshBet = Bet::withTrashed()->lockForUpdate()->findOrFail($bet->getKey());

            $guard = app(PrizeClaimGuard::class);
            $guard->assertIdentity($freshBet, $locked);
            if ($guard->resolve($freshBet, true)?->id !== $locked->id) {
                $guard->refuse($locked);
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            if (array_key_exists(self::METADATA_KEY, $metadata)) {
                if (! is_array($metadata[self::METADATA_KEY]) || ! $guard->hasWindowSnapshot($locked)) {
                    $guard->refuse($locked);
                }

                return $metadata[self::METADATA_KEY];
            }

            $guard->assertMutable($locked);

            $anchor = $this->anchorForBet($freshBet, $openedAt);
            $days = $this->windowDays();
            $closesAt = $anchor->copy()->addDays($days);
            $iso = $closesAt->toIso8601String();

            $window = [
                'status' => ClaimWindowStatus::Open->value,
                'opened_at' => $anchor->toIso8601String(),
                'closes_at' => $iso,
                'window_closes_at' => $iso,
                'window_days' => $days,
                'closed_at' => null,
                'facts_hash' => $guard->windowFingerprint($locked, $iso),
            ];

            $metadata[self::METADATA_KEY] = $window;
            $locked->metadata = $metadata;
            $locked->save();

            AuditLog::query()->create([
                'user_id' => $locked->user_id,
                'action' => AuditAction::Create,
                'risk_level' => RiskLevel::Low,
                'auditable_type' => Payout::class,
                'auditable_id' => (int) $locked->getKey(),
                'description' => sprintf('Prize claim window opened for payout #%d; closes %s.', (int) $locked->getKey(), $iso),
                'new_values' => ['claim_window' => $window],
                'metadata' => ['payout_id' => (int) $locked->getKey()],
            ]);

            return $window;
        }, 3);
    }

    public function closesAtFor(Payout $payout, ?Bet $bet = null): ?Carbon
    {
        $guard = app(PrizeClaimGuard::class);
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];

        if (array_key_exists(self::METADATA_KEY, $metadata)) {
            $window = $metadata[self::METADATA_KEY];
            if (! is_array($window) || ! $guard->hasWindowSnapshot($payout)) {
                return null;
            }

            return $guard->deadline($window['window_closes_at'] ?? null);
        }

        $resolvedBet = $bet ?? ($payout->bet_id !== null ? Bet::withTrashed()->find((int) $payout->bet_id) : null);
        if (! $resolvedBet instanceof Bet || $guard->resolve($resolvedBet)?->id !== $payout->id) {
            return null;
        }

        $anchor = $resolvedBet->draw?->completed_at ?? $resolvedBet->won_at;
        if (! $anchor instanceof Carbon) {
            return null;
        }

        return $anchor->copy()->addDays($this->windowDays());
    }

    public function statusFor(Payout $payout, ?Carbon $now = null): ClaimWindowStatus
    {
        if ($payout->trashed()) {
            return ClaimWindowStatus::Closed;
        }

        $guard = app(PrizeClaimGuard::class);
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];

        if (! array_key_exists(self::METADATA_KEY, $metadata)) {
            $closesAt = $this->closesAtFor($payout);
            if (! $closesAt instanceof Carbon) {
                return ClaimWindowStatus::Expired;
            }

            return ($now ?? Carbon::now())->lt($closesAt)
                ? ClaimWindowStatus::Open
                : ClaimWindowStatus::Expired;
        }

        $window = $metadata[self::METADATA_KEY];
        if (! is_array($window)) {
            return ClaimWindowStatus::Closed;
        }

        $stored = is_string($window['status'] ?? null) ? $window['status'] : '';
        $status = ClaimWindowStatus::tryFrom($stored);
        if ($status === null) {
            return ClaimWindowStatus::Closed;
        }

        if ($status === ClaimWindowStatus::Closed || $status === ClaimWindowStatus::Expired) {
            return $status;
        }

        if (! $guard->hasWindowSnapshot($payout)) {
            return ClaimWindowStatus::Closed;
        }

        $closesAt = $guard->deadline($window['window_closes_at'] ?? null);
        if (! $closesAt instanceof Carbon) {
            return ClaimWindowStatus::Closed;
        }

        return ($now ?? Carbon::now())->lt($closesAt)
            ? ClaimWindowStatus::Open
            : ClaimWindowStatus::Expired;
    }

    public function isOpen(Payout $payout, ?Carbon $now = null): bool
    {
        if ($payout->trashed() || $payout->status !== PayoutStatus::Pending) {
            return false;
        }

        return $this->statusFor($payout, $now) === ClaimWindowStatus::Open;
    }

    public function allowsClaim(Payout $payout, ?Carbon $now = null): bool
    {
        if ($payout->trashed() || $payout->status !== PayoutStatus::Pending) {
            return false;
        }

        $claimStatus = data_get($payout->metadata, 'claim.status');
        if (in_array($claimStatus, [
            PrizeClaimStatus::Paid->value,
            PrizeClaimStatus::Rejected->value,
            PrizeClaimStatus::Expired->value,
        ], true)) {
            return false;
        }

        return $this->isOpen($payout, $now);
    }

    /**
     * @return array<string, mixed>
     */
    public function closeAsPaid(Payout $payout, ?Carbon $paidAt = null): array
    {
        $completion = app(PrizeClaimCompletionService::class);
        $completion->assertEvidence($payout);
        $completion->complete($payout);

        $fresh = $payout->fresh();
        $window = is_array($fresh?->metadata[self::METADATA_KEY] ?? null) ? $fresh->metadata[self::METADATA_KEY] : null;
        if (! is_array($window) || ($window['status'] ?? null) !== ClaimWindowStatus::Closed->value) {
            app(PrizeClaimGuard::class)->refuse($payout);
        }

        return $window;
    }

    /**
     * @return array<string, mixed>
     */
    public function closeExpired(?int $chunkSize = null): array
    {
        $out = app(PrizeExpiryService::class)->run('window', $chunkSize);

        return [
            'closed_count' => $out['count'],
            'total_lapsed_amount' => $out['single_currency_total'],
            'totals_by_currency' => $out['totals_by_currency'],
            'skipped_candidates' => $out['skipped_candidates'],
            'annotation_only' => $out['annotation_only'],
            'executed_at' => $out['executed_at'],
        ];
    }

    private function anchorForBet(Bet $bet, ?Carbon $fallback): Carbon
    {
        $anchor = $bet->draw?->completed_at ?? $bet->won_at ?? $fallback;
        if (! $anchor instanceof Carbon) {
            throw PrizeClaimException::stateForbids((int) $bet->getKey(), 'missing_draw_timestamp', 'open_claim_window');
        }

        return $anchor;
    }
}
