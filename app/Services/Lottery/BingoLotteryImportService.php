<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\BingoLotteryDraw;
use App\Models\BingoLotteryResult;
use App\Models\BingoLotteryResultVersion;
use App\Services\Lottery\Support\AbstractLotteryImportService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only writer of Bingo/Mega result data (PROMPT 8).
 *
 * Validation, normalisation, fingerprinting, version creation, conflict
 * detection, publication and the imported/duplicate/conflict/rejected outcome
 * vocabulary all live in AbstractLotteryImportService, shared with the Weekly
 * lane. This class supplies the tables, the value columns, and an honest
 * answer to the one question the base class refuses to answer for anybody:
 * what is actually known about these bytes.
 *
 * THIS LANE HAS NO INDEPENDENT VERIFIER
 * ---------------------------------------------------------------------------
 * The Weekly lane runs the Rust crate in security/weekly-result-integrity,
 * which rebuilds the canonical bytes itself and refuses the import when its
 * hash disagrees with PHP's. Nothing equivalent exists here, so verifyIntegrity
 * below computes a SHA-256 in PHP and reports INTEGRITY_HASH_ONLY with
 * native = false.
 *
 * That is a WEAKER claim, stated plainly rather than hidden. A hash computed
 * by the same process that wrote the row proves the row is internally
 * consistent; it proves nothing about tampering by anything that could also
 * recompute the hash. Reporting SIGNED_VERIFIED, or quietly reusing the Weekly
 * verifier's vocabulary, would turn a modest guarantee into a false one.
 *
 * The canonical byte layout is the lane's own ('MEGA1'), so a Mega fingerprint
 * and a Weekly fingerprint for the same numbers on the same date are different
 * values and cannot be confused for one another.
 */
final class BingoLotteryImportService extends AbstractLotteryImportService
{
    public function __construct(
        ConfigRepository $config,
        BingoLotteryDateService $dates,
        BingoLotterySourceService $sources,
        BingoLotteryResultService $results,
        BingoLotteryHistoryService $history,
    ) {
        parent::__construct($config, $dates, $sources, $results, $history);
    }

    protected function configPrefix(): string
    {
        return 'bingo_lottery';
    }

    /**
     * @return class-string<BingoLotteryDraw>
     */
    protected function drawModel(): string
    {
        return BingoLotteryDraw::class;
    }

    /**
     * @return Builder<BingoLotteryDraw>
     */
    protected function drawQuery(): Builder
    {
        return BingoLotteryDraw::query();
    }

    /**
     * @return class-string<BingoLotteryResult>
     */
    protected function resultModel(): string
    {
        return BingoLotteryResult::class;
    }

    /**
     * @return Builder<BingoLotteryResult>
     */
    protected function resultQuery(): Builder
    {
        return BingoLotteryResult::query();
    }

    /**
     * @return class-string<BingoLotteryResultVersion>
     */
    protected function versionModel(): string
    {
        return BingoLotteryResultVersion::class;
    }

    /**
     * @return Builder<BingoLotteryResultVersion>
     */
    protected function versionQuery(): Builder
    {
        return BingoLotteryResultVersion::query();
    }

    /**
     * Hash the canonical bytes in PHP and say exactly that.
     *
     * CANONICAL LAYOUT 'MEGA1'
     * -----------------------------------------------------------------------
     * Field order is fixed and every value is length-prefixed:
     *
     *     MEGA1\n<field count>\n
     *     <namelen>:<name>=<tag><vallen>:<value>\n   (repeated, fixed order)
     *
     * Tag 'S' is a present string, 'N' an explicit null. The length prefix is
     * what makes the encoding injective: without it, a field ending in a digit
     * followed by a field starting with one could produce the same byte stream
     * as a different pair of values, and two distinct results would share a
     * fingerprint.
     *
     * Values are written as STRINGS, so '001234' hashes as six characters.
     * Nothing here casts, pads or trims a value - the importer has already
     * rejected anything of the wrong width.
     *
     * A SIGNATURE IS REFUSED, NOT IGNORED. If a caller supplies signature
     * material, this lane cannot check it, and accepting the payload anyway
     * would let a caller believe a signature was honoured. The import is
     * rejected instead.
     *
     * @param  array<string, string|null>  $canonicalFields
     * @return array{acceptable: bool, status: string, fingerprint: string, canonical_version: string, native: bool}
     */
    protected function verifyIntegrity(array $canonicalFields, ?string $signature): array
    {
        $canonicalVersion = $this->config->get($this->configPrefix().'.canonical_version');
        $canonicalVersion = is_string($canonicalVersion) && $canonicalVersion !== ''
            ? $canonicalVersion
            : 'MEGA1';

        $order = array_merge(
            ['draw_reference', 'draw_date'],
            $this->valueColumns(),
            ['source_identifier', 'parser_version'],
        );

        $canonical = $canonicalVersion."\n".count($order)."\n";

        foreach ($order as $name) {
            $value = $canonicalFields[$name] ?? null;

            $encoded = $value === null
                ? 'N0:'
                : 'S'.strlen((string) $value).':'.(string) $value;

            $canonical .= strlen($name).':'.$name.'='.$encoded."\n";
        }

        $fingerprint = hash('sha256', $canonical);

        if ($signature !== null && $signature !== '') {
            return [
                'acceptable' => false,
                'status' => 'SIGNATURE_UNSUPPORTED',
                'fingerprint' => $fingerprint,
                'canonical_version' => $canonicalVersion,
                'native' => false,
            ];
        }

        return [
            'acceptable' => true,
            // The honest ceiling for this lane: the bytes are internally
            // consistent, and nobody independent has vouched for them.
            'status' => 'INTEGRITY_HASH_ONLY',
            'fingerprint' => $fingerprint,
            'canonical_version' => $canonicalVersion,
            'native' => false,
        ];
    }
}
