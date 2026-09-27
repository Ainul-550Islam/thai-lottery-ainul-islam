<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\WeeklyLotteryDraw;
use App\Models\WeeklyLotteryResult;
use App\Models\WeeklyLotteryResultVersion;
use App\Services\Lottery\Support\AbstractLotteryImportService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only writer of Weekly result data (PROMPT 6; generalised in PROMPT 8).
 *
 * Validation, normalisation, fingerprinting, version creation, conflict
 * detection, publication and the imported/duplicate/conflict/rejected outcome
 * vocabulary all live in AbstractLotteryImportService, shared with the
 * Bingo/Mega lane. This class supplies the tables, the value columns, and the
 * one thing that is genuinely Weekly's own: an INDEPENDENT integrity verifier.
 *
 * WHY THE VERIFIER IS NOT IN THE BASE CLASS. Weekly runs the Rust crate in
 * security/weekly-result-integrity, which rebuilds the canonical bytes on its
 * own and refuses the import if its hash disagrees with PHP's. A lane without
 * that crate must not inherit a method that makes it look like it has one, so
 * verifyIntegrity() is abstract and every lane states plainly what it can
 * actually prove.
 */
final class WeeklyLotteryImportService extends AbstractLotteryImportService
{
    public function __construct(
        ConfigRepository $config,
        WeeklyLotteryDateService $dates,
        WeeklyLotterySourceService $sources,
        WeeklyLotteryResultService $results,
        WeeklyLotteryHistoryService $history,
        private readonly WeeklyResultIntegrityService $integrity,
    ) {
        parent::__construct($config, $dates, $sources, $results, $history);
    }

    protected function configPrefix(): string
    {
        return 'weekly_lottery';
    }

    /**
     * @return class-string<WeeklyLotteryDraw>
     */
    protected function drawModel(): string
    {
        return WeeklyLotteryDraw::class;
    }

    /**
     * @return Builder<WeeklyLotteryDraw>
     */
    protected function drawQuery(): Builder
    {
        return WeeklyLotteryDraw::query();
    }

    /**
     * @return class-string<WeeklyLotteryResult>
     */
    protected function resultModel(): string
    {
        return WeeklyLotteryResult::class;
    }

    /**
     * @return Builder<WeeklyLotteryResult>
     */
    protected function resultQuery(): Builder
    {
        return WeeklyLotteryResult::query();
    }

    /**
     * @return class-string<WeeklyLotteryResultVersion>
     */
    protected function versionModel(): string
    {
        return WeeklyLotteryResultVersion::class;
    }

    /**
     * @return Builder<WeeklyLotteryResultVersion>
     */
    protected function versionQuery(): Builder
    {
        return WeeklyLotteryResultVersion::query();
    }

    /**
     * @param  array<string, string|null>  $canonicalFields
     * @return array{acceptable: bool, status: string, fingerprint: string, canonical_version: string, native: bool}
     */
    protected function verifyIntegrity(array $canonicalFields, ?string $signature): array
    {
        return $this->integrity->verify($canonicalFields, $signature);
    }
}
