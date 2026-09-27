<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Support\AbstractLotteryResultVersion;

/**
 * Immutable provenance for one attempt to state a PCSO draw's result
 * (PROMPT 9).
 *
 * The immutability guard, the deletion refusal and every
 * ProvidesResultProvenance accessor live in AbstractLotteryResultVersion,
 * shared with the other lanes.
 *
 * INTEGRITY IS HASH-ONLY HERE. This lane runs no independent verifier, so
 * integrity_status is INTEGRITY_HASH_ONLY and integrity_native_verified is
 * false on every row. The Weekly Rust crate is deliberately NOT wired in: it
 * canonicalises the Weekly schema, and running PCSO bytes through it - or
 * labelling PCSO results "signed verified" because another lane has a
 * verifier - would be a claim this lane has not earned.
 */
class PcsoLotteryResultVersion extends AbstractLotteryResultVersion
{
    protected $table = 'pcso_lottery_result_versions';

    /**
     * @return class-string<PcsoLotteryDraw>
     */
    protected static function drawClass(): string
    {
        return PcsoLotteryDraw::class;
    }

    /**
     * @return class-string<PcsoLotteryResult>
     */
    protected static function resultClass(): string
    {
        return PcsoLotteryResult::class;
    }
}
