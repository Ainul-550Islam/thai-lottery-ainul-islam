<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\GloSourceState;
use App\Enums\RiskLevel;
use App\Exceptions\DrawResultValidationException;
use App\Models\AuditLog;
use App\Services\Lottery\BingoLotteryImportService;
use App\Services\Lottery\GloResultImportService;
use App\Services\Lottery\NationalLotteryImportService;
use App\Services\Lottery\PcsoLotteryImportService;
use App\Services\Lottery\WeeklyLotteryImportService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Universal Result Ingestion Orchestrator for All Lottery Lanes.
 *
 * Enforces atomic ingestion, provenance capture, field width validation,
 * leading zero preservation, duplicate detection, and conflict isolation
 * across National, Weekly, Mega/Bingo, PCSO, and GLO lottery products.
 */
class ResultImportService
{
    public const LANE_NATIONAL = 'national';
    public const LANE_WEEKLY = 'weekly';
    public const LANE_BINGO = 'bingo';
    public const LANE_PCSO = 'pcso';
    public const LANE_GLO = 'glo';

    /*
    |--------------------------------------------------------------------------
    | Import outcome vocabulary — THESE WERE MISSING, AND THEIR ABSENCE WAS FATAL
    |--------------------------------------------------------------------------
    |
    | `app/Console/Commands/ImportLotteryResults.php` has always done this:
    |
    |     if ($result['status'] === ResultImportService::STATUS_REJECTED) {
    |
    | and STATUS_REJECTED was **not defined on this class**. It is defined on
    | AbstractLotteryImportService, which the four lane services extend and which
    | this orchestrator does NOT — this class is a dispatcher, not a lane. So any
    | invocation of `php artisan lottery:import` that got as far as examining a
    | result raised `Error: Undefined constant`, on top of the GLO lane's
    | undefined-method problem.
    |
    | They are declared here, on the orchestrator, because the orchestrator is
    | the only component that sees all five lanes and is therefore the only place
    | the vocabulary can be stated once. The lane services keep their own
    | inherited copies; the STRINGS are identical on purpose, so a status read
    | from any lane compares equal to these constants.
    */
    public const STATUS_IMPORTED = 'imported';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_REJECTED = 'rejected';

    /**
     * No counterpart in any lane. A GLO payload whose SOURCE failed (an upstream
     * outage, an unconfigured provider) is not a rejection of the caller's
     * bytes, and reporting it as one would blame the wrong system.
     */
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly Container $container,
        private readonly DatabaseManager $db,
    ) {
    }

    /**
     * Ingest a lottery result for any supported lottery lane.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     status: string,
     *     lane: string,
     *     draw_reference: string|null,
     *     version: int|null,
     *     source_state: string,
     *     published: bool,
     *     errors: list<string>,
     *     message: string
     * }
     */
    public function import(
        string $lane,
        array $payload,
        string $provider = 'official',
        ?int $actorId = null,
        bool $autoPublish = true,
        bool $dryRun = false,
    ): array {
        $laneKey = strtolower(trim($lane));

        return match ($laneKey) {
            self::LANE_NATIONAL => $this->importNational($payload, $provider, $actorId, $autoPublish, $dryRun),
            self::LANE_WEEKLY => $this->importWeekly($payload, $provider, $actorId, $autoPublish, $dryRun),
            self::LANE_BINGO, 'mega' => $this->importBingo($payload, $provider, $actorId, $autoPublish, $dryRun),
            self::LANE_PCSO => $this->importPcso($payload, $provider, $actorId, $autoPublish, $dryRun),
            self::LANE_GLO => $this->importGlo($payload, $provider, $actorId, $autoPublish, $dryRun),
            default => throw new InvalidArgumentException(sprintf('Unsupported lottery lane [%s].', $laneKey)),
        };
    }

    private function importNational(array $payload, string $provider, ?int $actorId, bool $autoPublish, bool $dryRun): array
    {
        /** @var NationalLotteryImportService $service */
        $service = $this->container->make(NationalLotteryImportService::class);
        $res = $service->import($payload, $provider, $actorId, $autoPublish, $dryRun);
        $res['lane'] = self::LANE_NATIONAL;
        return $res;
    }

    private function importWeekly(array $payload, string $provider, ?int $actorId, bool $autoPublish, bool $dryRun): array
    {
        /** @var WeeklyLotteryImportService $service */
        $service = $this->container->make(WeeklyLotteryImportService::class);
        $res = $service->import($payload, $provider, $actorId, $autoPublish, $dryRun);
        $res['lane'] = self::LANE_WEEKLY;
        return $res;
    }

    private function importBingo(array $payload, string $provider, ?int $actorId, bool $autoPublish, bool $dryRun): array
    {
        /** @var BingoLotteryImportService $service */
        $service = $this->container->make(BingoLotteryImportService::class);
        $res = $service->import($payload, $provider, $actorId, $autoPublish, $dryRun);
        $res['lane'] = self::LANE_BINGO;
        return $res;
    }

    private function importPcso(array $payload, string $provider, ?int $actorId, bool $autoPublish, bool $dryRun): array
    {
        /** @var PcsoLotteryImportService $service */
        $service = $this->container->make(PcsoLotteryImportService::class);
        $res = $service->import($payload, $provider, $actorId, $autoPublish, $dryRun);
        $res['lane'] = self::LANE_PCSO;
        return $res;
    }

    /**
     * The GLO lane — and the one lane whose rules differ.
     *
     * `$autoPublish` is passed through unchanged and GloResultImportService
     * REFUSES it, returning REJECTED / AUTO_PUBLISH_FORBIDDEN. That refusal is
     * not an oversight to be fixed here: since the four-eyes fix, publication of
     * a GLO result requires a second operator through
     * DrawResultConfirmationService::confirm(), and this orchestrator has no way
     * to supply one. An orchestrator-level `$autoPublish` default of true — which
     * is what the universal command sends — would otherwise be a documented
     * bypass of the guard, reachable from a single command.
     *
     * So the lane is usable from here ONLY with --no-publish, and the error
     * message says so and names the command that does work.
     */
    private function importGlo(array $payload, string $provider, ?int $actorId, bool $autoPublish, bool $dryRun): array
    {
        /** @var GloResultImportService $service */
        $service = $this->container->make(GloResultImportService::class);
        $res = $service->import($payload, $provider, $actorId, $autoPublish, $dryRun);
        $res['lane'] = self::LANE_GLO;

        return $res;
    }
}
