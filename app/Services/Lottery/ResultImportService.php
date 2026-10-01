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

    private function importGlo(array $payload, string $provider, ?int $actorId, bool $autoPublish, bool $dryRun): array
    {
        /** @var GloResultImportService $service */
        $service = $this->container->make(GloResultImportService::class);
        $res = $service->import($payload, $provider, $actorId, $autoPublish, $dryRun);
        $res['lane'] = self::LANE_GLO;
        return $res;
    }
}
