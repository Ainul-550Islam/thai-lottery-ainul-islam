<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccountGradeSnapshot;
use App\Models\User;
use App\Services\Account\AccountGradeEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * grades:rebuild-snapshots (PROMPT 2, section L).
 *
 * Rebuilds append-only grade snapshots from the canonical evaluator —
 * the SAME evaluator the account-grade API and the grade page read,
 * so a rebuilt history and a live answer can never disagree.
 *
 * IDEMPOTENT BY FINGERPRINT. Each evaluation is fingerprinted
 * (sha256 over user, window start/end, spend, grade key and rule
 * version) and the fingerprint column is UNIQUE: a re-run that
 * produces an identical fingerprint is recognised as "unchanged" and
 * writes NOTHING, and a duplicate row is impossible BY CONSTRAINT,
 * not by a flag. There is deliberately no --force - append-only
 * history cannot be forced, only appended to.
 *
 * APPEND-ONLY. Existing rows are never updated, never rewritten and
 * never deleted; a changed evaluation becomes a NEW row. A bad
 * --as-of fails the command (FAILURE) instead of writing a wrong
 * window into history.
 */
final class RebuildAccountGradeSnapshots extends Command
{
    protected $signature = 'grades:rebuild-snapshots
                            {--account= : Rebuild a single account (user id) instead of everybody}
                            {--as-of= : Pin the evaluation instant (Y-m-d H:i:s), window is the N days before it}
                            {--dry-run : Evaluate and report without writing anything}
                            {--chunk=200 : Users per query chunk, clamped to 1..1000}';

    protected $description = 'Rebuild append-only account grade snapshots from the canonical evaluator';

    public function handle(AccountGradeEvaluator $evaluator): int
    {
        $asOf = $this->asOf();

        if ($asOf === false) {
            $this->error('Invalid --as-of value: '.(string) $this->option('as-of'));

            return self::FAILURE;
        }

        $chunk = max(1, min(1000, (int) $this->option('chunk') ?: 200));
        $dryRun = (bool) $this->option('dry-run');
        $accountId = $this->option('account') !== null ? (int) $this->option('account') : null;

        if ($accountId !== null && User::query()->whereKey($accountId)->doesntExist()) {
            $this->error(sprintf('Account %d does not exist.', $accountId));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Rebuilding grade snapshots%s (%s, chunk %d)%s',
            $accountId !== null ? sprintf(' for account %d', $accountId) : ' for all users',
            $asOf !== null ? 'as of '.$asOf->toDateTimeString() : 'as of now',
            $chunk,
            $dryRun ? ' [DRY RUN]' : '',
        ));

        $query = User::query()->orderBy('id');
        if ($accountId !== null) {
            $query->whereKey($accountId);
        }

        $created = 0;
        $unchanged = 0;
        $failed = 0;

        $query->chunkById($chunk, function (iterable $users) use ($evaluator, $asOf, $dryRun, &$created, &$unchanged, &$failed): void {
            foreach ($users as $user) {
                try {
                    $result = $evaluator->evaluate($user, $asOf);
                } catch (\Throwable $exception) {
                    ++$failed;
                    $this->warn(sprintf('Account %d failed: %s', (int) $user->id, $exception->getMessage()));

                    continue;
                }

                $written = $dryRun
                    ? $this->wouldPersist($user, $result)
                    : $this->persist($user, $result);

                if ($written) {
                    ++$created;
                    $this->line(sprintf('Account %d: %s (%s) — %s', (int) $user->id, $result->tier->key, $result->qualifyingSpend, $dryRun ? 'would snapshot' : 'snapshotted'));

                    continue;
                }

                ++$unchanged;
                $this->line(sprintf('Account %d: %s (%s) — unchanged', (int) $user->id, $result->tier->key, $result->qualifyingSpend));
            }
        });

        if ($dryRun) {
            $this->info(sprintf('Dry run complete: %d would be created, %d already current, %d failed.', $created, $unchanged, $failed));
        } else {
            $this->info(sprintf('Done: %d created, %d unchanged, %d failed.', $created, $unchanged, $failed));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The idempotency fingerprint of one evaluation: sha256 over the
     * user, the exact window, the spend seen, the resolved grade and
     * the rule version. Identical evaluations hash identically.
     */
    private function fingerprint(User $user, \App\DTOs\Account\GradeEvaluationResult $result): string
    {
        return hash('sha256', implode('|', [
            (string) $user->id,
            $result->windowStart,
            $result->windowEnd,
            $result->qualifyingSpend,
            $result->level->value,
            $result->ruleVersion,
        ]));
    }

    /**
     * Would this evaluation write a new row? (Read-only check used by
     * --dry-run.)
     */
    private function wouldPersist(User $user, \App\DTOs\Account\GradeEvaluationResult $result): bool
    {
        return ! AccountGradeSnapshot::query()
            ->where('fingerprint', $this->fingerprint($user, $result))
            ->exists();
    }

    /**
     * Append one snapshot row unless an identical fingerprint already
     * exists (idempotency). Returns true when a row was written.
     */
    private function persist(User $user, \App\DTOs\Account\GradeEvaluationResult $result): bool
    {
        $fingerprint = $this->fingerprint($user, $result);

        if (AccountGradeSnapshot::query()->where('fingerprint', $fingerprint)->exists()) {
            // The fingerprint is UNIQUE: history stays append-only and a
            // rerun is recognised as unchanged.
            return false;
        }

        AccountGradeSnapshot::query()->create([
            'user_id' => (int) $user->id,
            'period_start' => $result->windowStart,
            'period_end' => $result->windowEnd,
            'qualifying_spend' => $result->qualifyingSpend,
            'grade_key' => $result->level->value,
            'grade_discount_rate' => $result->appliedRate,
            'entitlement_hash' => $result->entitlementHash,
            'previous_grade_key' => $result->previousLevel?->value,
            'rule_version' => $result->ruleVersion,
            'source_version' => $result->sourceVersion,
            'calculated_at' => $result->evaluatedAt,
            'fingerprint' => $fingerprint,
            'metadata' => [
                'window_days' => $result->windowDays,
                'eligible_games' => array_map(
                    static fn ($game): string => $game->value,
                    $result->eligibleGames,
                ),
                'rebuilt_by' => 'grades:rebuild-snapshots',
            ],
        ]);

        return true;
    }

    /**
     * Parse --as-of into a Carbon instant. Returns null when absent,
     * or FALSE when the supplied value does not parse — the caller
     * must fail the command rather than write a wrong window.
     */
    private function asOf(): Carbon|bool|null
    {
        $value = $this->option('as-of');

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return false;
        }
    }
}
