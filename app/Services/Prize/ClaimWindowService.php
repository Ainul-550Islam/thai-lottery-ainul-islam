<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\Enums\AuditAction;
use App\Enums\ClaimWindowStatus;
use App\Enums\RiskLevel;
use App\Exceptions\ClaimWindowExpiredException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClaimWindowService
{
    public const METADATA_KEY = 'claim_window';

    public function windowDays(): int
    {
        $configured = config('lottery.claims.window_days');

        return is_numeric($configured) && (int) $configured >= 0
            ? (int) $configured
            : 730;
    }

    /**
     * @return array{status: string, window_closes_at: string, opened_at: string, closed_at: string|null}
     */
    public function register(Payout $payout, Bet $bet): array
    {
        $guard = app(PrizeClaimGuard::class);
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];

        if (array_key_exists(self::METADATA_KEY, $metadata)) {
            if (! $guard->hasWindowSnapshot($payout)) {
                $guard->refuse($payout);
            }
            $existing = $this->windowRecord($payout) ?? [];

            return [
                'status' => (string) ($existing['status'] ?? ClaimWindowStatus::Open->value),
                'window_closes_at' => (string) ($existing['window_closes_at'] ?? ''),
                'opened_at' => (string) ($existing['opened_at'] ?? ''),
                'closed_at' => isset($existing['closed_at']) && is_scalar($existing['closed_at']) ? (string) $existing['closed_at'] : null,
            ];
        }

        $anchor = $bet->draw?->completed_at ?? $bet->won_at;
        if (! $anchor instanceof Carbon) {
            $guard->refuse($payout);
        }

        $closesAt = $anchor->copy()->addDays($this->windowDays());
        $closesAtIso = $closesAt->toIso8601String();

        $record = [
            'status' => ClaimWindowStatus::Open->value,
            'opened_at' => now()->toIso8601String(),
            'window_closes_at' => $closesAtIso,
            'closed_at' => null,
            'facts_hash' => $guard->windowFingerprint($payout, $closesAtIso),
        ];

        $this->writeRecord($payout, $record);

        return $record;
    }

    public function statusFor(Payout $payout): ClaimWindowStatus
    {
        $record = $this->windowRecord($payout);

        if (! is_array($record)) {
            return ClaimWindowStatus::Open;
        }

        $stored = is_string($record['status'] ?? null) ? $record['status'] : '';
        $status = ClaimWindowStatus::tryFrom($stored);
        if ($status === null) {
            return ClaimWindowStatus::Expired;
        }

        if ($status === ClaimWindowStatus::Open && $this->closesAtOf($record)->isPast()) {
            return ClaimWindowStatus::Expired;
        }

        return $status;
    }

    public function allowsClaim(Payout $payout): bool
    {
        return $this->statusFor($payout)->allowsClaim();
    }

    public function closesAtFor(Payout $payout): ?Carbon
    {
        $record = $this->windowRecord($payout);

        return is_array($record) ? $this->closesAtOf($record) : null;
    }

    /**
     * @throws ClaimWindowExpiredException
     */
    public function assertOpen(Payout $payout, int $betId): void
    {
        $status = $this->statusFor($payout);

        if ($status === ClaimWindowStatus::Open) {
            return;
        }

        $record = $this->windowRecord($payout) ?? [];

        if ($status === ClaimWindowStatus::Closed && isset($record['closed_at']) && is_scalar($record['closed_at'])) {
            throw ClaimWindowExpiredException::alreadyClosed(
                $betId,
                (string) $record['closed_at'],
                ['payout_id' => (int) $payout->getKey()],
            );
        }

        throw ClaimWindowExpiredException::expired(
            $betId,
            (string) ($record['window_closes_at'] ?? ''),
            ['payout_id' => (int) $payout->getKey()],
        );
    }

    /**
     * @throws ClaimWindowExpiredException
     */
    public function closeAsPaid(Payout $payout): void
    {
        $status = $this->statusFor($payout);

        if ($status !== ClaimWindowStatus::Open) {
            $record = $this->windowRecord($payout) ?? [];

            if ($status === ClaimWindowStatus::Closed && isset($record['closed_at']) && is_scalar($record['closed_at'])) {
                throw ClaimWindowExpiredException::alreadyClosed(
                    $payout->bet_id !== null ? (int) $payout->bet_id : 0,
                    (string) $record['closed_at'],
                    ['payout_id' => (int) $payout->getKey()],
                );
            }

            throw ClaimWindowExpiredException::expired(
                $payout->bet_id !== null ? (int) $payout->bet_id : 0,
                (string) ($record['window_closes_at'] ?? ''),
                ['payout_id' => (int) $payout->getKey()],
            );
        }

        $stamp = $this->windowRecord($payout) ?? [];
        $stamp['status'] = ClaimWindowStatus::Closed->value;
        $stamp['closed_at'] = now()->toIso8601String();
        $stamp['closed_via'] = 'paid';

        $this->writeRecord($payout, $stamp);

        $this->recordAudit($payout, 'Window closed (prize paid within window)', RiskLevel::Low);
    }

    /**
     * @return array{closed_count: int, total_lapsed_amount: string|null, totals_by_currency: array<string, string>, annotation_only: bool}
     */
    public function closeExpired(?int $chunkSize = null): array
    {
        $chunkSize ??= 200;

        $closed = 0;
        $totalsByCurrency = [];
        $guard = app(PrizeClaimGuard::class);

        Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereRaw("json_extract(metadata, '$.claim_window.status') = ?", [ClaimWindowStatus::Open->value])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($payouts) use (&$closed, &$totalsByCurrency, $guard): bool {
                foreach ($payouts as $candidate) {
                    DB::transaction(function () use ($candidate, &$closed, &$totalsByCurrency, $guard): void {
                        $payout = Payout::query()->lockForUpdate()->find((int) $candidate->getKey());
                        if (! $payout instanceof Payout) {
                            return;
                        }

                        if ($guard->protected($payout) || ! $guard->hasWindowSnapshot($payout)) {
                            return;
                        }

                        $record = $this->windowRecord($payout);
                        if (! is_array($record) || ($record['status'] ?? null) !== ClaimWindowStatus::Open->value) {
                            return;
                        }

                        $deadline = $guard->deadline($record['window_closes_at'] ?? null);
                        if (! $deadline instanceof Carbon || ! $deadline->isPast()) {
                            return;
                        }

                        $record['status'] = ClaimWindowStatus::Closed->value;
                        $record['closed_at'] = now()->toIso8601String();
                        $record['closed_via'] = 'expired';

                        $this->writeRecord($payout, $record);

                        $closed++;
                        $currency = $payout->currency->value;
                        $totalsByCurrency[$currency] = bcadd($totalsByCurrency[$currency] ?? '0.00', (string) $payout->amount, 2);

                        $this->recordAudit($payout, sprintf(
                            'Window closed: deadline %s passed with no payment',
                            (string) ($record['window_closes_at'] ?? ''),
                        ), RiskLevel::High);
                    });
                }

                return true;
            });

        return [
            'closed_count' => $closed,
            'total_lapsed_amount' => count($totalsByCurrency) > 1
                ? null
                : (count($totalsByCurrency) === 1 ? (string) reset($totalsByCurrency) : '0.00'),
            'totals_by_currency' => $totalsByCurrency,
            'annotation_only' => true,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function windowRecord(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function closesAtOf(array $record): Carbon
    {
        $parsed = app(PrizeClaimGuard::class)->deadline($record['window_closes_at'] ?? null);

        return $parsed ?? Carbon::createFromTimestampUTC(0);
    }

    /**
     * @param  array<string, mixed>  $stamp
     */
    private function writeRecord(Payout $payout, array $stamp): void
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $metadata[self::METADATA_KEY] = $stamp;

        $payout->metadata = $metadata;
        $payout->save();
    }

    private function recordAudit(Payout $payout, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => sprintf(
                'Prize claim window %s on payout #%d (%s %s)',
                $description,
                (int) $payout->getKey(),
                (string) $payout->amount,
                $payout->currency->value,
            ),
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
                'action' => 'claim_window_closed',
            ],
        ]);

        $log->save();
    }
}
