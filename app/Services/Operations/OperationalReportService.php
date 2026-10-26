<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\DTOs\Operations\OperationalReportData;
use App\DTOs\Operations\ReportExportData;
use App\Enums\DrawLifecycleState;
use App\Enums\ReportJobStatus;
use App\Enums\ReportType;
use App\Exceptions\OperationalReportException;
use App\Models\AuditLog;
use App\Models\ComplianceCase;
use App\Models\Draw;
use App\Models\LedgerEntry;
use App\Models\OperationalReportJob;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * OperationalReportService — READ-ONLY cross-lane reporting from
 * lanes that own the truth. Rows are projections of desk tables,
 * timestamps pinned, and identical asks (same requester + blend)
 * seal into one generation job row, forever. Nothing here mutates
 * the desks it reads.
 *
 * FILTER TRUTH
 * Every advertised filter is applied to the query that produces the
 * rows, so a filtered report can never carry the unfiltered count.
 * Each clause targets a real column, a real relation or a metadata
 * lane some writer in app/ stamps; there are no LIKE '%%' predicates
 * and no filter is accepted by the DTO then dropped on the floor. The
 * prize family has no projection, so its reader refuses out loud and
 * the job seats Failed instead of completing with zero rows.
 */
final class OperationalReportService
{
    private const MAX_ROWS = 50000;

    /**
     * ASK: same ask = one job row (replay free).
     */
    public function ask(OperationalReportData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var OperationalReportJob|null $existing */
            $existing = OperationalReportJob::query()
                ->where('query_fingerprint', $data->queryFingerprint())
                ->first();

            if ($existing instanceof OperationalReportJob) {
                return ['job' => $existing, 'created' => false];
            }

            $row = OperationalReportJob::query()->create([
                'query_fingerprint' => $data->queryFingerprint(),
                'requester_user_id' => $data->requesterUserId,
                'report_type' => $data->reportType,
                'filters' => $data->filters,
                'status' => ReportJobStatus::Queued,
                'horizon_start' => $data->horizonStart,
                'horizon_end' => $data->horizonEnd,
                'expires_at' => now()->addHours(ReportExportData::RETENTION_HOURS),
            ]);

            return ['job' => $row, 'created' => true];
        });
    }

    /**
     * RUN a queued job: gathers rows from the lane projections and
     * seats Completed (the rows themselves travel to the export
     * lane for deterministic rendering — the job records counts).
     */
    public function run(string $queryFingerprint): OperationalReportJob
    {
        // Validate before opening the work transaction: an unknown or already
        // terminal row is a request error, never a generation failure.
        $job = OperationalReportJob::query()
            ->where('query_fingerprint', $queryFingerprint)
            ->first();

        if (! $job instanceof OperationalReportJob) {
            throw OperationalReportException::notFound(substr($queryFingerprint, 0, 12));
        }

        if ($job->status === ReportJobStatus::Completed) {
            return $job;
        }

        if ($job->status !== ReportJobStatus::Queued && $job->status !== ReportJobStatus::Running) {
            throw OperationalReportException::terminalJob($queryFingerprint, $job->status->value);
        }

        try {
            return DB::transaction(function () use ($job): OperationalReportJob {
                /** @var OperationalReportJob $locked */
                $locked = OperationalReportJob::query()->lockForUpdate()->findOrFail($job->getKey());

                $locked->status = ReportJobStatus::Running;
                $locked->started_at = now();
                $locked->save();

                // A reader can refuse (the prize family has no projection); that
                // exception rolls this transaction back.
                $rows = $this->rows($locked);

                $locked->row_count = $rows->count();
                $locked->status = ReportJobStatus::Completed;
                $locked->completed_at = now();
                $locked->save();

                return $locked->refresh();
            });
        } catch (\Throwable $e) {
            // The work transaction rolled back and took the Running marker with
            // it, so the terminal Failed state is seated in a fresh transaction
            // the rollback cannot reach. The desk keeps a durable failure_reason.
            $reason = substr('generation lane: '.$e->getMessage(), 0, 96);

            DB::transaction(function () use ($job, $reason): void {
                /** @var OperationalReportJob|null $seat */
                $seat = OperationalReportJob::query()->lockForUpdate()->find($job->getKey());

                if ($seat instanceof OperationalReportJob
                    && in_array($seat->status, [ReportJobStatus::Queued, ReportJobStatus::Running], true)) {
                    $seat->status = ReportJobStatus::Failed;
                    $seat->failure_reason = $reason;
                    $seat->save();
                }
            });

            throw OperationalReportException::generationFailure($reason);
        }
    }

    /**
     * THE PROJECTIONS — read-only rows per report family. Every
     * where clause is additive and deterministic in output.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(OperationalReportJob $job): Collection
    {
        $filters = $job->filters;

        return match ($job->report_type) {
            ReportType::Financial => $this->financialRows($job, $filters),
            ReportType::Payment => $this->paymentRows($job, $filters),
            ReportType::Draw => $this->drawRows($job, $filters),
            ReportType::Prize => $this->prizeRows($job, $filters),
            ReportType::Compliance => $this->complianceRows($job, $filters),
            ReportType::Player => $this->playerRows($job, $filters),
            ReportType::Operational => $this->operationalRows($job, $filters),
        };
    }

    /**
     * Ledger entries. Currency is a column on the entry; the player and
     * wallet kind travel through the entry's wallet relation, so the
     * financial user filter can never again compile to a LIKE that is
     * true of every row with a wallet.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function financialRows(OperationalReportJob $job, array $filters): Collection
    {
        return LedgerEntry::query()
            ->whereBetween('posted_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['currency']), fn ($q) => $q->where('currency', $filters['currency']))
            ->when(isset($filters['wallet_kind']), fn ($q) => $q->whereHas('wallet', fn ($w) => $w->where('type', $filters['wallet_kind'])))
            ->when(isset($filters['user_id']), fn ($q) => $q->whereHas('wallet', fn ($w) => $w->where('user_id', (int) $filters['user_id'])))
            ->orderBy('posted_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (LedgerEntry $e): array => [
                'id' => $e->id,
                'wallet_id' => $e->wallet_id,
                'type' => $e->type,
                'amount' => $e->amount,
                'currency' => $e->currency,
                'reference_type' => $e->reference_type,
                'posted_at' => $e->posted_at?->toIso8601String(),
            ]);
    }

    /**
     * Payment aggregates. The provider filter reads the gateway column,
     * which is where the driver name is persisted.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function paymentRows(OperationalReportJob $job, array $filters): Collection
    {
        return Payment::query()
            ->whereBetween('created_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['provider']), fn ($q) => $q->where('gateway', $filters['provider']))
            ->when(isset($filters['method']), fn ($q) => $q->where('method', $filters['method']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('user_id', (int) $filters['user_id']))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Payment $p): array => [
                'id' => $p->id,
                'reference_number' => $p->reference_number,
                'user_id' => $p->user_id,
                'method' => $p->method,
                'status' => $p->status,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'gateway' => $p->gateway,
                'created_at' => $p->created_at?->toIso8601String(),
            ]);
    }

    /**
     * Draws. The desk vocabulary lifecycle_state is mapped onto the
     * persisted DrawStatus value by the total DrawLifecycleState mapping,
     * rather than guessed with a string comparison.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function drawRows(OperationalReportJob $job, array $filters): Collection
    {
        return Draw::query()
            ->whereBetween('scheduled_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['draw_id']), fn ($q) => $q->where('id', (int) $filters['draw_id']))
            ->when(isset($filters['draw_type']), fn ($q) => $q->where('type', $filters['draw_type']))
            ->when(isset($filters['lifecycle_state']), fn ($q) => $q->where('status', $this->lifecycleStateToStatus((string) $filters['lifecycle_state'])))
            ->orderBy('draw_number')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Draw $d): array => [
                'id' => $d->id,
                'draw_number' => $d->draw_number,
                'type' => $d->type,
                'status' => $d->status,
                'scheduled_at' => $d->scheduled_at?->toIso8601String(),
                'total_bets' => $d->total_bets,
            ]);
    }

    /**
     * The prize family has no projection. Seating a Completed job with
     * zero rows would make "this family is not built" indistinguishable
     * from "no claims this horizon", so the reader refuses and the job
     * seats Failed with the same reason.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function prizeRows(OperationalReportJob $job, array $filters): Collection
    {
        throw OperationalReportException::malformed(
            'the prize report family has no projection in this desk; refusing instead of reporting an empty horizon'
        );
    }

    /**
     * Compliance cases. The player filter reads subject_user_id, the
     * column that names the player a case is about.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function complianceRows(OperationalReportJob $job, array $filters): Collection
    {
        return ComplianceCase::query()
            ->whereBetween('created_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['case_type']), fn ($q) => $q->where('case_type', $filters['case_type']))
            ->when(isset($filters['risk_level']), fn ($q) => $q->where('risk_level', $filters['risk_level']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('subject_user_id', (int) $filters['user_id']))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (ComplianceCase $c): array => [
                'id' => $c->id,
                'case_key' => $c->case_key,
                'subject_user_id' => $c->subject_user_id,
                'case_type' => $c->case_type,
                'risk_level' => $c->risk_level,
                'status' => $c->status,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);
    }

    /**
     * Players. The registration window is a lower bound on created_at;
     * the DTO already guaranteed it is a whole number of days.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function playerRows(OperationalReportJob $job, array $filters): Collection
    {
        return User::query()
            ->whereBetween('created_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['kyc_status']), fn ($q) => $q->where('kyc_status', $filters['kyc_status']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('id', (int) $filters['user_id']))
            ->when(isset($filters['registered_within']), fn ($q) => $q->where('created_at', '>=', now()->subDays((int) $filters['registered_within'])))
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'username' => $u->username,
                'status' => $u->status->value,
                'kyc_status' => $u->kyc_status,
                'created_at' => $u->created_at?->toIso8601String(),
            ]);
    }

    /**
     * Audit log lines. lane and risk_rating are metadata lanes stamped
     * by the audit listeners in app/Listeners, so they are filtered on
     * the JSON column rather than a column that does not exist.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function operationalRows(OperationalReportJob $job, array $filters): Collection
    {
        return AuditLog::query()
            ->whereBetween('created_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['lane']), fn ($q) => $q->where('metadata->lane', $filters['lane']))
            ->when(isset($filters['risk_rating']), fn ($q) => $q->where('metadata->risk_rating', $filters['risk_rating']))
            ->when(isset($filters['auditable_type']), fn ($q) => $q->where('auditable_type', $filters['auditable_type']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('user_id', (int) $filters['user_id']))
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (AuditLog $a): array => [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'action' => $a->action instanceof \BackedEnum ? $a->action->value : (string) $a->action,
                'auditable_type' => $a->auditable_type,
                'metadata' => $a->metadata,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);
    }

    /**
     * Translate a desk lifecycle vocabulary value to the persisted
     * draw status. The DTO already validated the vocabulary, so the
     * mapping is total here.
     */
    private function lifecycleStateToStatus(string $lifecycleState): string
    {
        $state = DrawLifecycleState::tryFrom($lifecycleState);

        if (! $state instanceof DrawLifecycleState) {
            throw OperationalReportException::malformed(sprintf(
                'filter [lifecycle_state] value [%s] is not a known draw lifecycle state',
                $lifecycleState,
            ));
        }

        return $state->toDrawStatus()->value;
    }

    /**
     * EXPIRE horizon-passed jobs whose artifacts aged out.
     */
    public function expireStale(int $limit = 200): int
    {
        $expired = 0;

        OperationalReportJob::query()
            ->whereIn('status', [ReportJobStatus::Queued->value, ReportJobStatus::Running->value, ReportJobStatus::Completed->value])
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->get()
            ->each(function (OperationalReportJob $job) use (&$expired): void {
                DB::transaction(function () use ($job, &$expired): void {
                    /** @var OperationalReportJob|null $locked */
                    $locked = OperationalReportJob::query()->lockForUpdate()->findOrFail($job->id);
                    if ($locked->status->isTerminal()) {
                        return;
                    }
                    $locked->status = ReportJobStatus::Expired;
                    $locked->save();
                    $expired++;
                });
            });

        return $expired;
    }
}
