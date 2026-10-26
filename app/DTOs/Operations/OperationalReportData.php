<?php

declare(strict_types=1);

namespace App\DTOs\Operations;

use App\Enums\ComplianceCaseStatus;
use App\Enums\Currency;
use App\Enums\DrawLifecycleState;
use App\Enums\DrawType;
use App\Enums\KycStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReportType;
use App\Enums\RiskLevel;
use App\Enums\WalletType;
use App\Exceptions\OperationalReportException;
use Illuminate\Support\Carbon;

/**
 * OperationalReportData — a report ask: family + sealed filter
 * blend + time horizon + requester. The same blend = one job row.
 *
 * FILTER TRUTH CONTRACT
 * Every key advertised here is consulted by the matching reader in
 * OperationalReportService, and every value is validated against the
 * vocabulary the underlying column actually stores. A key whose data no
 * writer stamps is not advertised at all (it is refused at the door), and a
 * value outside the column's enum vocabulary is refused rather than silently
 * matching nothing. The prize family has no projection, so it advertises no
 * filter and its reader refuses out loud.
 */
final class OperationalReportData
{
    private const MAX_HORIZON_DAYS = 366;

    /**
     * The advertised filter whitelist, one entry per family.
     *
     * Pinned by OperationalReportFilterTruthTest: 21 keys in total. Changing a
     * whitelist is a measured decision, not a silent edit — the static proof
     * reads this constant and fails if a reader consults anything else or
     * fails to consult everything listed here.
     */
    private const ALLOWED_FILTERS = [
        'financial' => ['currency', 'wallet_kind', 'user_id'],
        'payment' => ['provider', 'method', 'status', 'user_id'],
        'draw' => ['draw_id', 'draw_type', 'lifecycle_state'],
        // The prize family has no projection: it may accept no filter and its
        // reader refuses instead of seating an empty Completed job.
        'prize' => [],
        'compliance' => ['case_type', 'risk_level', 'status', 'user_id'],
        'player' => ['kyc_status', 'user_id', 'registered_within'],
        'operational' => ['lane', 'risk_rating', 'auditable_type', 'user_id'],
    ];

    /**
     * Enum-backed vocabularies. A sealed value must be the backing value of
     * one of the listed cases, or the ask is refused. Keys absent from this
     * map are validated by the dedicated rules below (numeric ids, windows,
     * free-text metadata lanes that have no closed enum).
     *
     * @var array<string, class-string<\BackedEnum>>
     */
    private const ENUM_VOCABULARIES = [
        'financial.currency' => Currency::class,
        'financial.wallet_kind' => WalletType::class,
        'payment.method' => PaymentMethod::class,
        'payment.status' => PaymentStatus::class,
        'draw.draw_type' => DrawType::class,
        'draw.lifecycle_state' => DrawLifecycleState::class,
        'compliance.risk_level' => RiskLevel::class,
        'compliance.status' => ComplianceCaseStatus::class,
        'player.kyc_status' => KycStatus::class,
        'operational.risk_rating' => RiskLevel::class,
    ];

    /**
     * Filters whose value must be a positive integer string (surrogate ids and
     * day windows). Nothing here ever reaches a LIKE predicate.
     *
     * @var array<string, true>
     */
    private const INTEGER_FILTERS = [
        'financial.user_id' => true,
        'payment.user_id' => true,
        'draw.draw_id' => true,
        'compliance.user_id' => true,
        'player.user_id' => true,
        'operational.user_id' => true,
        'player.registered_within' => true,
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
        // Whitelist + string trim: sealed, stable order.
        $sealed = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $value = is_bool($value = $filters[$key]) ? $value : mb_substr(trim((string) $value), 0, 96);
                $sealed[$key] = $value;
            }
        }

        // Every sealed value is checked against the vocabulary its column
        // actually stores. An out-of-vocabulary value is a malformed ask, not
        // a filter that quietly matches no row.
        foreach ($sealed as $key => $value) {
            self::assertValueInVocabulary($type->value, (string) $key, $value);
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

        return new self(
            requesterUserId: (int) ($data['requester_user_id'] ?? 0),
            reportType: $type,
            filters: $sealed,
            horizonStart: $start,
            horizonEnd: $end,
        );
    }

    /**
     * Refuse a value that the target column could never store honestly.
     */
    private static function assertValueInVocabulary(string $family, string $key, mixed $value): void
    {
        $dotted = $family.'.'.$key;

        if (isset(self::INTEGER_FILTERS[$dotted])) {
            if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
                throw OperationalReportException::malformed(sprintf(
                    'filter [%s] for the [%s] report must be a positive whole number',
                    $key,
                    $family,
                ));
            }

            return;
        }

        $enum = self::ENUM_VOCABULARIES[$dotted] ?? null;

        if ($enum !== null) {
            $haystack = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());

            if (! in_array((string) $value, $haystack, true)) {
                throw OperationalReportException::malformed(sprintf(
                    'filter [%s] for the [%s] report must be one of: %s',
                    $key,
                    $family,
                    implode(', ', $haystack),
                ));
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
