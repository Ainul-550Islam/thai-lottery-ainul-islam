<?php

declare(strict_types=1);

namespace App\DTOs\Operations;

use App\Enums\Currency;
use App\Enums\DrawLifecycleState;
use App\Enums\KycStatus;
use App\Enums\ReportType;
use App\Enums\RiskLevel;
use App\Enums\WalletType;
use App\Exceptions\OperationalReportException;
use Illuminate\Support\Carbon;

/**
 * OperationalReportData — a report ask: family + sealed filter
 * blend + time horizon + requester. The same blend = one job row.
 */
final class OperationalReportData
{
    private const MAX_HORIZON_DAYS = 366;

    /**
     * A key may be asked for ONLY if the reader that receives it narrows rows
     * with it, on a column or metadata lane that some writer in app/ stamps.
     *
     * WHY THE RULE IS HERE AND NOT IN A COMMENT
     * This list used to carry 14 keys no reader consulted, out of 29. Asking for one
     * was accepted, sealed into the job fingerprint, dropped on the floor, and the
     * job reported Completed - for a money report that is a wrong total presented as
     * a right one. Two more of the 15 that WERE consulted could not narrow anything:
     * entry_purpose filtered a metadata lane no writer stamps, and user_id compiled to
     * a predicate true of every row. Cluster #50 measured all 29, wired up the 7 that
     * have data behind them, dropped the 7 that do not, and repaired the 2 that were
     * consulted and broken. The gate asserts both halves for every key kept: the reader
     * consults it, and consulting it changes the rows.
     */
    private const ALLOWED_FILTERS = [
        'financial' => ['currency', 'wallet_kind', 'user_id'],
        'payment' => ['provider', 'method', 'status', 'user_id'],
        'draw' => ['draw_id', 'draw_type', 'lifecycle_state'],
        'prize' => [],
        'compliance' => ['case_type', 'risk_level', 'status', 'user_id'],
        'player' => ['kyc_status', 'user_id', 'registered_within'],
        'operational' => ['lane', 'risk_rating', 'user_id', 'auditable_type'],
    ];

    public function __construct(
        public readonly int $requesterUserId,
        public readonly ReportType $reportType,
        public readonly array $filters,
        public readonly Carbon $horizonStart,
        public readonly Carbon $horizonEnd,
    ) {}

    /**
     * @param  array{requester_user_id:int, report_type:string|ReportType, filters?:array, horizon_start?:string|\DateTimeInterface|null, horizon_end?:string|\DateTimeInterface|null}  $data
     */
    public static function fromInput(array $data): self
    {
        $type = $data['report_type'] ?? null;
        if (! $type instanceof ReportType) {
            $type = is_string($type) ? ReportType::tryFrom(strtolower(trim($type))) : null;
        }
        if (! $type instanceof ReportType) {
            throw OperationalReportException::malformed('A valid report type is required');
        }

        $filters = $data['filters'] ?? [];
        if (! is_array($filters)) {
            throw OperationalReportException::malformed('Filters must be a sealed array');
        }

        $allowed = self::ALLOWED_FILTERS[$type->value] ?? [];
        foreach (array_keys($filters) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw OperationalReportException::unsupportedFilter($type->value, (string) $key);
            }
        }
        // Whitelist + value vocabulary + string trim: sealed, stable order.
        $sealed = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $value = $filters[$key];
                $sealed[$key] = is_bool($value) ? $value : mb_substr(trim((string) $value), 0, 96);
            }
        }

        $now = Carbon::now();
        $start = self::toCarbon($data['horizon_start'] ?? null) ?? $now->copy()->subDays(30);
        $end = self::toCarbon($data['horizon_end'] ?? null) ?? $now->copy();

        if ($end->lte($start)) {
            throw OperationalReportException::malformed('The horizon end must stand after the start');
        }
        if ($start->diffInDays($end) > self::MAX_HORIZON_DAYS) {
            throw OperationalReportException::malformed('Horizon wider than the desk ceiling of '.self::MAX_HORIZON_DAYS.' days');
        }

        self::assertFilterVocabulary($type, $sealed);

        return new self(
            requesterUserId: (int) ($data['requester_user_id'] ?? 0),
            reportType: $type,
            filters: $sealed,
            horizonStart: $start,
            horizonEnd: $end,
        );
    }

    /**
     * Every filter whose target column is an enum vocabulary must name a case of it.
     *
     * A typo and a genuinely quiet horizon must not look alike. Before this rule
     * `currency=THB` (an upper-case slip) sealed, applied, matched nothing, and
     * reported "completed, 0 rows" - the same answer the desk gives when nobody
     * moved money this week.
     */
    private static function assertFilterVocabulary(ReportType $type, array $sealed): void
    {
        /** @var array<string, class-string<\BackedEnum>|'days'> $vocab */
        $vocab = match ($type) {
            ReportType::Financial => ['currency' => Currency::class, 'wallet_kind' => WalletType::class],
            ReportType::Draw => ['lifecycle_state' => DrawLifecycleState::class],
            ReportType::Player => ['kyc_status' => KycStatus::class, 'registered_within' => 'days'],
            ReportType::Operational => ['risk_rating' => RiskLevel::class],
            default => [],
        };

        foreach ($vocab as $key => $vocabulary) {
            if (! array_key_exists($key, $sealed)) {
                continue;
            }

            $value = (string) $sealed[$key];

            if ($vocabulary === 'days') {
                if (! ctype_digit($value) || (int) $value < 1) {
                    throw OperationalReportException::malformed(
                        sprintf('The %s filter needs a whole number of days of at least 1.', $key)
                    );
                }

                continue;
            }

            if ($vocabulary::tryFrom($value) === null) {
                throw OperationalReportException::malformed(
                    sprintf('"%s" is not a value the %s filter can hold.', $value, $key)
                );
            }
        }
    }

    /**
     * Same ask = one generation job row, forever.
     */
    public function queryFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-opreport', (string) $this->requesterUserId, $this->reportType->value,
            json_encode($this->filters),
            $this->horizonStart->toIso8601String(), $this->horizonEnd->toIso8601String(),
        ]));
    }

    /**
     * Whether the ask stands inside the synchronous sound barrier.
     */
    public function withinSyncHorizon(): bool
    {
        return $this->horizonStart->diffInDays($this->horizonEnd) <= $this->reportType->syncHorizonDays();
    }

    private static function toCarbon(string|\DateTimeInterface|null $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw OperationalReportException::malformed('Horizon edges must parse as timestamps');
        }
    }
}
