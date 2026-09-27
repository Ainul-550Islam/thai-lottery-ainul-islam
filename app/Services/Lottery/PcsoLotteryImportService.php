<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\PcsoLotteryDraw;
use App\Models\PcsoLotteryResult;
use App\Models\PcsoLotteryResultVersion;
use App\Services\Lottery\Support\AbstractLotteryImportService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * The only writer of Bingo/PCSO result data (PROMPT 9).
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
 * The canonical byte layout is the lane's own ('PCSO1'), so a PCSO fingerprint
 * and a Weekly fingerprint for the same numbers on the same date are different
 * values and cannot be confused for one another.
 */
final class PcsoLotteryImportService extends AbstractLotteryImportService
{
    public function __construct(
        ConfigRepository $config,
        PcsoLotteryDateService $dates,
        PcsoLotterySourceService $sources,
        PcsoLotteryResultService $results,
        PcsoLotteryHistoryService $history,
    ) {
        parent::__construct($config, $dates, $sources, $results, $history);
    }

    protected function configPrefix(): string
    {
        return 'pcso_lottery';
    }

    /**
     * @return class-string<PcsoLotteryDraw>
     */
    protected function drawModel(): string
    {
        return PcsoLotteryDraw::class;
    }

    /**
     * @return Builder<PcsoLotteryDraw>
     */
    protected function drawQuery(): Builder
    {
        return PcsoLotteryDraw::query();
    }

    /**
     * @return class-string<PcsoLotteryResult>
     */
    protected function resultModel(): string
    {
        return PcsoLotteryResult::class;
    }

    /**
     * @return Builder<PcsoLotteryResult>
     */
    protected function resultQuery(): Builder
    {
        return PcsoLotteryResult::query();
    }

    /**
     * @return class-string<PcsoLotteryResultVersion>
     */
    protected function versionModel(): string
    {
        return PcsoLotteryResultVersion::class;
    }

    /**
     * @return Builder<PcsoLotteryResultVersion>
     */
    protected function versionQuery(): Builder
    {
        return PcsoLotteryResultVersion::query();
    }

    /**
     * Hash the canonical bytes in PHP and say exactly that.
     *
     * CANONICAL LAYOUT 'PCSO1'
     * -----------------------------------------------------------------------
     * Every value is length-prefixed:
     *
     *     PCSO1\n<field count>\n
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
            : 'PCSO1';

        // Field order is fixed and includes the draw time, because in this
        // lane two draws can share a date and only the time tells them apart.
        // Omitting it would give the 14:00 and 21:00 draws the same
        // fingerprint whenever their numbers matched.
        $order = array_merge(
            ['draw_reference', 'draw_date', 'draw_time_local'],
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
