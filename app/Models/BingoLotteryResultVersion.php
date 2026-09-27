<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Support\AbstractLotteryResultVersion;

/**
 * Immutable provenance for one attempt to state a Bingo/Mega draw's result
 * (PROMPT 8).
 *
 * The immutability guard, the deletion refusal and every
 * ProvidesResultProvenance accessor live in AbstractLotteryResultVersion,
 * shared with the Weekly lane. Only the table and the two related models are
 * this lane's own.
 *
 * INTEGRITY IS HASH-ONLY HERE. This lane runs no independent verifier, so
 * integrity_status is INTEGRITY_HASH_ONLY and integrity_native_verified is
 * false on every row. Those values are set by BingoLotteryImportService and
 * mean exactly what they say: PHP hashed the canonical bytes. Nothing in this
 * lane may report SIGNED_VERIFIED, because nothing in this lane checks a
 * signature.
 */
class BingoLotteryResultVersion extends AbstractLotteryResultVersion
{
    protected $table = 'bingo_lottery_result_versions';

    /**
     * @return class-string<BingoLotteryDraw>
     */
    protected static function drawClass(): string
    {
        return BingoLotteryDraw::class;
    }

    /**
     * @return class-string<BingoLotteryResult>
     */
    protected static function resultClass(): string
    {
        return BingoLotteryResult::class;
    }
}
