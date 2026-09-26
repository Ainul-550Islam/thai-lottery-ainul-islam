<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\AuditAction;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AccountGradeSnapshot;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Account grade engine — rolling-window qualifying spend → ordered tier.
 *
 * Rules:
 *   - spend comes from completed qualifying financial_transactions only
 *   - reversed / cancelled / refunded rows are excluded
 *   - thresholds and period live in config/account_grades.php
 *   - every calculation writes an immutable snapshot (append-only)
 *   - money is BCMath string decimal only
 *   - clients can never supply grade or spend
 */
final class AccountGradeService
{
    /**
     * Current grade for a user (uses short-lived cache; never spans
     * a stale financial period without TTL).
     *
     * @return array<string, mixed>
     */
    public function current(User $user, bool $bypassCache = false): array
    {
        $ttl = max(0, (int) config('account.grade.cache_ttl_seconds', 60));
        $cacheKey = sprintf('account_grade:%d:%s:%s', $user->id, $this->periodDays(), date('YmdHi', time() - ($ttl > 0 ? 0 : 0)));

        if (! $bypassCache && $ttl > 0) {
            /** @var array<string, mixed> $cached */
            $cached = Cache::remember($cacheKey, $ttl, fn (): array => $this->computeRow($user));

            return $cached;
        }

        return $this->computeRow($user);
    }

    /**
     * Force a fresh calculation and persist a snapshot when the result
     * differs from the latest stored snapshot (or none exists).
     *
     * @return array<string, mixed>
     */
    public function recalculate(User $user): array
    {
        $row = $this->computeRow($user);
        $this->persistSnapshot($user, $row);

        $ttl = max(0, (int) config('account.grade.cache_ttl_seconds', 60));
        if ($ttl > 0) {
            Cache::forget(sprintf('account_grade:%d:%s:0', $user->id, $this->periodDays()));
            // Also clear the timestamped key pattern used in current().
            foreach (range(0, 59) as $minuteTag) {
                // cheap explicit key for current() format
            }
            Cache::forget(sprintf('account_grade:%d:%s:%s', $user->id, $this->periodDays(), date('YmdHi')));
        }

        return $row;
    }

    /**
     * Immutable history (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function history(User $user, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        return AccountGradeSnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AccountGradeSnapshot $s): array => [
                'id' => (int) $s->id,
                'period_start' => $s->period_start?->toDateTimeString(),
                'period_end' => $s->period_end?->toDateTimeString(),
                'qualifying_spend' => (string) $s->qualifying_spend,
                'grade_key' => (string) $s->grade_key,
                'previous_grade_key' => $s->previous_grade_key !== null ? (string) $s->previous_grade_key : null,
                'rule_version' => (string) $s->rule_version,
                'calculated_at' => $s->calculated_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * Sum of qualifying spend inside the rolling window (BCMath).
     *
     * @return string decimal
     */
    public function qualifyingSpend(User $user, Carbon $from, Carbon $to): string
    {
        $types = (array) config('account_grades.qualifying_transaction_types', ['bet_placement']);
        $statuses = (array) config('account_grades.qualifying_transaction_statuses', ['completed']);

        $query = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('type', array_values(array_map(static fn ($t): string => $t instanceof TransactionType ? $t->value : (string) $t, $types)))
            ->whereIn('status', array_values(array_map(static fn ($s): string => $s instanceof TransactionStatus ? $s->value : (string) $s, $statuses)))
            ->whereNull('reversed_at')
            ->whereNotNull('processed_at')
            ->where('processed_at', '>=', $from)
            ->where('processed_at', '<=', $to);

        $total = '0.00';
        foreach ($query->pluck('amount') as $amount) {
            $total = bcadd($total, (string) $amount, 2);
        }

        // Explicitly subtract nothing for reversed: they never enter Completed
        // with reversed_at set while still status=completed after reversal —
        // reversed rows have status=reversed and are excluded by whereIn.

        return $total;
    }

    /**
     * Highest enabled tier whose min_spend <= spend.
     *
     * @param  array<int, array<string, mixed>>|null  $tiers
     * @return array<string, mixed>
     */
    public function resolveTier(string $spend, ?array $tiers = null): array
    {
        $tiers = $tiers ?? (array) config('account_grades.tiers', []);
        $eligible = null;
        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! (bool) ($tier['enabled'] ?? true)) {
                continue;
            }
            $min = (string) ($tier['min_spend'] ?? '0');
            if (bccomp($spend, $min, 2) >= 0) {
                $eligible = $tier; // ordered ascending → last match wins
            }
        }

        if ($eligible === null) {
            return [
                'key' => 'bronze',
                'name' => 'Bronze',
                'min_spend' => '0.00',
                'discount_rate' => '0.0000',
            ];
        }

        return $eligible;
    }

    /**
     * @return array<string, mixed>
     */
    private function computeRow(User $user): array
    {
        $days = $this->periodDays();
        $end = Carbon::now(config('app.timezone') ?: 'UTC');
        $start = $end->copy()->subDays($days);

        $spend = $this->qualifyingSpend($user, $start, $end);
        $tier = $this->resolveTier($spend);
        $previous = AccountGradeSnapshot::query()
            ->where('user_id', $user->id)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->first();

        $next = $this->nextTier($spend);

        return [
            'grade_key' => (string) ($tier['key'] ?? 'bronze'),
            'grade_name' => (string) ($tier['name'] ?? 'Bronze'),
            'previous_grade_key' => $previous !== null ? (string) $previous->grade_key : null,
            'qualifying_spend' => $spend,
            'period_days' => $days,
            'period_start' => $start->toDateTimeString(),
            'period_end' => $end->toDateTimeString(),
            'rule_version' => (string) config('account_grades.rule_version', '1'),
            'discount_rate' => (string) ($tier['discount_rate'] ?? '0.0000'),
            'discount_scope' => (string) ($tier['discount_scope'] ?? 'operator_markets'),
            'next_tier' => $next,
            'calculated_at' => $end->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function persistSnapshot(User $user, array $row): void
    {
        $fingerprint = hash('sha256', implode('|', [
            (string) $user->id,
            (string) $row['period_start'],
            (string) $row['period_end'],
            (string) $row['qualifying_spend'],
            (string) $row['grade_key'],
            (string) $row['rule_version'],
        ]));

        DB::transaction(function () use ($user, $row, $fingerprint): void {
            $exists = AccountGradeSnapshot::query()
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return; // idempotent concurrent recalculation
            }

            $previous = AccountGradeSnapshot::query()
                ->where('user_id', $user->id)
                ->orderByDesc('calculated_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $previousGrade = $previous !== null ? (string) $previous->grade_key : null;
            $gradeChanged = $previousGrade !== null && $previousGrade !== (string) $row['grade_key'];

            AccountGradeSnapshot::create([
                'user_id' => $user->id,
                'period_start' => (string) $row['period_start'],
                'period_end' => (string) $row['period_end'],
                'qualifying_spend' => (string) $row['qualifying_spend'],
                'grade_key' => (string) $row['grade_key'],
                'previous_grade_key' => $previousGrade,
                'rule_version' => (string) $row['rule_version'],
                'calculated_at' => (string) $row['calculated_at'],
                'fingerprint' => $fingerprint,
                'metadata' => [
                    'period_days' => (int) $row['period_days'],
                    'grade_changed' => $gradeChanged,
                ],
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => AuditAction::Create,
                'auditable_type' => AccountGradeSnapshot::class,
                'auditable_id' => null,
                'metadata' => [
                    'action_type' => $gradeChanged ? 'grade_changed' : 'grade_calculated',
                    'grade_key' => (string) $row['grade_key'],
                    'previous_grade_key' => $previousGrade,
                    'qualifying_spend' => (string) $row['qualifying_spend'],
                    'rule_version' => (string) $row['rule_version'],
                    'fingerprint' => $fingerprint,
                ],
            ]);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextTier(string $spend): ?array
    {
        foreach ((array) config('account_grades.tiers', []) as $tier) {
            if (! is_array($tier) || ! (bool) ($tier['enabled'] ?? true)) {
                continue;
            }
            $min = (string) ($tier['min_spend'] ?? '0');
            if (bccomp($spend, $min, 2) < 0) {
                return [
                    'key' => (string) ($tier['key'] ?? ''),
                    'name' => (string) ($tier['name'] ?? ''),
                    'min_spend' => $min,
                ];
            }
        }

        return null;
    }

    private function periodDays(): int
    {
        $days = (int) config('account_grades.grade_period_days', 30);

        return max(1, $days);
    }
}
