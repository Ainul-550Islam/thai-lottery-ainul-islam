<?php

declare(strict_types=1);

namespace App\Services\ResultIngestion;

use App\Exceptions\LegacyLotteryEntryException;
use App\Models\DrawResult;

/**
 * Retired legacy confirmation writer. An arbitrary operator id and a draft
 * fingerprint do not establish authenticated review or canonical provenance.
 * Use the Draw namespace confirmation service via an authorized workflow.
 */
class DrawResultConfirmationService
{
    public function confirmResult(int|string $drawResultId, int|string $confirmingOperatorId): DrawResult
    {
        throw LegacyLotteryEntryException::unavailable('result confirmation');
    }
}
