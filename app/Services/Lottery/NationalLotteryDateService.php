<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Services\Lottery\Support\AbstractLotteryCalendarService;

/**
 * Asia/Bangkok business dates and Buddhist-era conversion for the National
 * Lottery lane.
 *
 * WHY THIS FILE IS NOW FOUR LINES (PROMPT 6)
 * ---------------------------------------------------------------------------
 * Every method that used to live here moved, UNCHANGED, into
 * App\Services\Lottery\Support\AbstractLotteryCalendarService when the Weekly
 * Lottery lane needed the same behaviour. Copying it would have produced two
 * classes that each add 543 somewhere, and the first time one was corrected
 * and the other was not, one lane would print the wrong era.
 *
 * The public API is byte-for-byte the same as it was in PROMPT 5 -
 * timezone(), today(), toBuddhistYear(), toGregorianYear(),
 * normaliseYearInput(), isAcceptableYear(), minGregorianYear(),
 * maxGregorianYear(), parsePublicDate(), toIsoDate(), formatForDisplay(),
 * projection(), yearLabel() - so nothing that consumed this class changed.
 *
 * The only lane-specific fact is the config prefix, which is why that is the
 * only thing this class states.
 */
class NationalLotteryDateService extends AbstractLotteryCalendarService
{
    protected function configPrefix(): string
    {
        return 'national_lottery';
    }
}
