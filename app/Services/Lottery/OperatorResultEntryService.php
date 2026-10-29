<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\GloSourceState;
use App\Enums\ResultVersionState;
use App\Enums\RiskLevel;
use App\Models\Support\AbstractLotteryResultVersion;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Draw\AdminDrawResultWorkflow;
use App\Services\Lottery\Support\AbstractLotteryImportService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;

/**
 * Operator result entry. Thai draw cards are delegated to the existing
 * AdminDrawResultWorkflow; the separately stored sourced product lanes retain
 * their own versioned import engines. Both require two-person review.
 *
 * ============================================================================
 * WHY THIS EXISTS
 * ============================================================================
 * The four lanes (national, weekly, bingo, pcso) each have a canonical import
 * engine that turns a provider payload into a version row and its numbers. What
 * they did not have was a documented way for a human to be the provider on the
 * day the feed is late — and the absence of that path is not a safety feature.
 * An operator with no supported way to record a result will find an unsupported
 * one, and that one will not have a second signature, an audit row, or a
 * provenance label.
 *
 * So the manual path is first-class, and it is deliberately the SLOWEST path
 * available:
 *
 *   submit()   operator A enters the numbers. The version is stored, VERIFIED
 *              against the lane's own field rules, and NOT published.
 *   approve()  operator B, who is provably a different person, states why and
 *              publishes it. Operator A cannot do this; the lane refuses.
 *
 * ============================================================================
 * IT DOES NOT RE-IMPLEMENT THE LANES
 * ============================================================================
 * Every write goes through the lane's own {@see AbstractLotteryImportService},
 * so the field widths, the leading-zero preservation, the fingerprint, the
 * integrity gate, the conflict rule and the "retire, never overwrite" rule are
 * literally the same code that a provider import uses. This class only adds the
 * human-workflow half: which provider label to claim, who may approve, and what
 * gets audited.
 *
 * ============================================================================
 * IMMUTABLE AFTER PUBLICATION
 * ============================================================================
 * There is no update path here and no update path in the import engine for
 * stored result values. Once a version is live:
 *   - `approve()` returns DUPLICATE with `published: true` and changes nothing;
 *   - a correction is a NEW version, which supersedes (never rewrites) the old
 *     one, and both stay readable;
 *   - the DTO this class returns for a published version is built from the
 *     stored row, not from anything the caller sent.
 *
 * ============================================================================
 * WHAT IT REFUSES
 * ============================================================================
 *   - an unknown lane name (no fallback to "the first one");
 *   - a lane whose configured provider order does not carry the operator
 *     provider label — because then the import engine would reject the payload
 *     with an opaque UNKNOWN_PROVIDER;
 *   - a submission by an actor who is not permitted to import;
 *   - an approval by the importer, by a version with no importer, or by anyone
 *     of a version that is not Verified;
 *   - a blank approval reason.
 */
final class OperatorResultEntryService
{
    /** Lane key => the config file prefix the lane reads. */
    public const LANES = [
        'national' => 'national_lottery',
        'weekly' => 'weekly_lottery',
        'bingo' => 'bingo_lottery',
        'pcso' => 'pcso_lottery',
    ];

    /**
     * Lane key => the concrete import engine.
     *
     * NOT EVERY LANE'S ENGINE IS THE SHARED ONE. Weekly, Bingo and Pcso are
     * subclasses of the shared {@see AbstractLotteryImportService} and therefore
     * inherit `approveVersion()`. National is still a standalone implementation
     * with its own `import()` and no approval method.
     *
     * That difference is handled by {@see capableEngine()}, which refuses a lane
     * whose engine cannot perform a two-person approval instead of calling a
     * method that does not exist. A TypeError in a Filament action is a crash; a
     * stated refusal is an answer an operator can act on — and, more importantly,
     * neither one lets a single person publish a result.
     */
    private const ENGINES = [
        'national' => NationalLotteryImportService::class,
        'weekly' => WeeklyLotteryImportService::class,
        'bingo' => BingoLotteryImportService::class,
        'pcso' => PcsoLotteryImportService::class,
    ];

    /**
     * The provider label a manual entry claims.
     *
     * It is `internal`, the label the lanes already use for "this platform
     * recorded it", and never `official`: an operator typing numbers is not an
     * official source and the provenance badge must never say so. The label is
     * configurable per lane so an operator can rename it, and the default is the
     * value that renders as INTERNAL_RECONCILED.
     */
    public const DEFAULT_PROVIDER_LABEL = 'internal';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly AuditLogService $audit,
        private readonly AdminDrawResultWorkflow $drawWorkflow,
    ) {}

    /** Stage a Thai draw result; actor, source and authorization are server-owned. */
    public function ingestDraw(int $drawId, array $numbers): array
    {
        return $this->drawWorkflow->ingest($drawId, $numbers);
    }

    /** A different authorized operator confirms the exact, fingerprinted card. */
    public function confirmDraw(int $drawId, array $numbers, string $notes, string $fingerprint): array
    {
        return $this->drawWorkflow->confirm($drawId, $numbers, $notes, $fingerprint);
    }

    /**
     * Every lane this service supports, with its operator-provider state, for
     * the admin page and for tests.
     *
     * @return array<string, array{config_prefix: string, provider: string, available: bool, reason: string|null}>
     */
    public function lanes(): array
    {
        $out = [];

        foreach (self::LANES as $lane => $prefix) {
            $provider = $this->providerLabel($lane);
            $priority = $this->priority($prefix);
            $engine = $this->capableEngine($lane);

            $providerInLadder = in_array($provider, $priority, true);
            $engineSupportsApproval = $engine !== null;

            $out[$lane] = [
                'config_prefix' => $prefix,
                'provider' => $provider,
                'available' => $providerInLadder && $engineSupportsApproval,
                'reason' => match (true) {
                    ! $engineSupportsApproval => 'approval_engine_unsupported',
                    ! $providerInLadder => 'provider_not_in_lane_priority',
                    default => null,
                },
            ];
        }

        return $out;
    }

    /**
     * Operator A: enter a result. It is stored and withheld.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> the lane's own outcome array plus `lane` and `provider`
     *
     * @throws InvalidArgumentException on an unknown lane or an unavailable operator provider
     */
    public function submit(string $lane, array $payload, User $actor): array
    {
        $engine = $this->requiredEngine($lane);
        $provider = $this->providerLabel($lane);

        if (! in_array($provider, $this->priority(self::LANES[$lane]), true)) {
            throw new InvalidArgumentException(sprintf(
                'The operator provider "%s" is not in the %s lane priority list, so a manual entry could never be published.',
                $provider,
                $lane,
            ));
        }

        $outcome = $engine->import(
            payload: $payload,
            provider: $provider,
            actorId: (int) $actor->getKey(),
            autoPublish: false,
            dryRun: false,
        );

        $this->record(
            actor: $actor,
            action: AuditAction::DataImport,
            description: 'Operator entered a lottery result; publication withheld for a second person.',
            metadata: [
                'lane' => $lane,
                'provider' => $provider,
                'outcome' => (string) ($outcome['status'] ?? 'unknown'),
                'draw_reference' => $outcome['draw_reference'] ?? null,
                'version' => $outcome['version'] ?? null,
                'source_state' => $outcome['source_state'] ?? null,
                'errors' => array_values(array_map('strval', (array) ($outcome['errors'] ?? []))),
            ],
        );

        return $outcome + ['lane' => $lane, 'provider' => $provider];
    }

    /**
     * Operator B: approve and publish.
     *
     * @return array<string, mixed> the lane's own outcome array plus `lane` and `provider`
     *
     * @throws InvalidArgumentException on an unknown lane
     */
    public function approve(string $lane, AbstractLotteryResultVersion $version, User $approver, string $reason): array
    {
        $engine = $this->requiredEngine($lane);
        $provider = $this->providerLabel($lane);

        $outcome = $engine->approveVersion($version, (int) $approver->getKey(), $reason);

        $this->record(
            actor: $approver,
            action: AuditAction::Update,
            description: 'Operator approved a manually entered lottery result for publication.',
            metadata: [
                'lane' => $lane,
                'provider' => $provider,
                'outcome' => (string) ($outcome['status'] ?? 'unknown'),
                'draw_reference' => $outcome['draw_reference'] ?? null,
                'version' => $outcome['version'] ?? null,
                'published' => (bool) ($outcome['published'] ?? false),
                'reason_length' => mb_strlen(trim($reason)),
                'errors' => array_values(array_map('strval', (array) ($outcome['errors'] ?? []))),
            ],
        );

        return $outcome + ['lane' => $lane, 'provider' => $provider];
    }

    /**
     * Versions awaiting a second signature in one lane.
     *
     * A "pending" version here means: Verified by the engine, imported by a
     * person, and not the draw's current version. That is exactly the set an
     * operator must action, and it is derived from stored state rather than from
     * a separate queue table that could drift.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingApprovals(string $lane, int $limit = 50): array
    {
        /** @var class-string $versionModel */
        $versionModel = $this->resolveVersionModel($lane);
        $resultModel = $this->resolveResultModel($lane);
        $drawModel = $this->resolveDrawModel($lane);

        $rows = $versionModel::query()
            ->where('state', ResultVersionState::Verified->value)
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->get();

        $out = [];

        foreach ($rows as $version) {
            $draw = $drawModel::query()->find($version->draw_id);

            if ($draw === null) {
                continue;
            }

            if ((int) ($draw->current_result_version_id ?? 0) === (int) $version->id) {
                continue;
            }

            $result = $resultModel::query()->where('result_version_id', $version->id)->first();

            if ($result === null) {
                continue;
            }

            $out[] = [
                'version_id' => (int) $version->id,
                'version_number' => (int) $version->version_number,
                'draw_reference' => (string) $draw->draw_reference,
                'provider' => (string) $version->provider,
                'source_state' => (string) $version->source_state,
                'imported_by' => $version->imported_by === null ? null : (int) $version->imported_by,
                'imported_at' => $version->imported_at?->toIso8601String(),
                'result_status' => (string) $result->result_status,
            ];
        }

        return $out;
    }

    /**
     * The lane's engine when it can perform a two-person approval, else null.
     *
     * The check is `instanceof` rather than a method_exists() probe: the shared
     * engine's `approveVersion()` is the ONLY implementation of the approval
     * rule in this codebase, and a lane that merely happens to define a method of
     * that name must not be treated as having it.
     */
    public function capableEngine(string $lane): ?AbstractLotteryImportService
    {
        $key = strtolower(trim($lane));

        if (! isset(self::ENGINES[$key])) {
            throw new InvalidArgumentException(sprintf('Unknown lottery lane "%s".', $lane));
        }

        $engine = app(self::ENGINES[$key]);

        return $engine instanceof AbstractLotteryImportService ? $engine : null;
    }

    /**
     * The lane's engine, or a refusal naming the reason.
     */
    public function requiredEngine(string $lane): AbstractLotteryImportService
    {
        $engine = $this->capableEngine($lane);

        if ($engine === null) {
            throw new InvalidArgumentException(sprintf(
                'The %s lane engine does not implement two-person approval, so a manual entry could never be published there.',
                strtolower(trim($lane)),
            ));
        }

        return $engine;
    }

    /**
     * The lane's engine regardless of approval support, for a caller that only
     * needs `import()`.
     */
    public function engine(string $lane): AbstractLotteryImportService
    {
        return $this->requiredEngine($lane);
    }

    /**
     * The provider label this lane's manual entries claim.
     */
    public function providerLabel(string $lane): string
    {
        $key = strtolower(trim($lane));

        if (! isset(self::LANES[$key])) {
            throw new InvalidArgumentException(sprintf('Unknown lottery lane "%s".', $lane));
        }

        $configured = $this->config->get(self::LANES[$key].'.sources.operator.provider_label');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return self::DEFAULT_PROVIDER_LABEL;
    }

    /**
     * Whether the lane's provenance can ever be published for a manual entry.
     * The result of this is what an operator sees on the entry form, so they
     * know before typing whether the entry can go live at all.
     */
    public function isPublishable(string $lane): bool
    {
        $key = strtolower(trim($lane));
        $provider = $this->providerLabel($key);
        $priority = $this->priority(self::LANES[$key] ?? '');

        if (! in_array($provider, $priority, true)) {
            return false;
        }

        return $this->capableEngine($key) !== null;
    }

    /**
     * @return list<string>
     */
    private function priority(string $configPrefix): array
    {
        if ($configPrefix === '') {
            return [];
        }

        $priority = $this->config->get($configPrefix.'.sources.priority', []);

        if (! is_array($priority)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $priority,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @return class-string
     */
    private function resolveVersionModel(string $lane): string
    {
        return match (strtolower(trim($lane))) {
            'national' => \App\Models\NationalLotteryResultVersion::class,
            'weekly' => \App\Models\WeeklyLotteryResultVersion::class,
            'bingo' => \App\Models\BingoLotteryResultVersion::class,
            'pcso' => \App\Models\PcsoLotteryResultVersion::class,
            default => throw new InvalidArgumentException(sprintf('Unknown lottery lane "%s".', $lane)),
        };
    }

    /**
     * @return class-string
     */
    private function resolveResultModel(string $lane): string
    {
        return match (strtolower(trim($lane))) {
            'national' => \App\Models\NationalLotteryResult::class,
            'weekly' => \App\Models\WeeklyLotteryResult::class,
            'bingo' => \App\Models\BingoLotteryResult::class,
            'pcso' => \App\Models\PcsoLotteryResult::class,
            default => throw new InvalidArgumentException(sprintf('Unknown lottery lane "%s".', $lane)),
        };
    }

    /**
     * @return class-string
     */
    private function resolveDrawModel(string $lane): string
    {
        return match (strtolower(trim($lane))) {
            'national' => \App\Models\NationalLotteryDraw::class,
            'weekly' => \App\Models\WeeklyLotteryDraw::class,
            'bingo' => \App\Models\BingoLotteryDraw::class,
            'pcso' => \App\Models\PcsoLotteryDraw::class,
            default => throw new InvalidArgumentException(sprintf('Unknown lottery lane "%s".', $lane)),
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(User $actor, AuditAction $action, string $description, array $metadata): void
    {
        $this->audit->log(
            userId: (int) $actor->getKey(),
            action: $action,
            riskLevel: RiskLevel::High,
            auditable: $actor,
            description: $description,
            metadata: $metadata + ['source_state_hint' => GloSourceState::InternalReconciled->value],
        );
    }
}
