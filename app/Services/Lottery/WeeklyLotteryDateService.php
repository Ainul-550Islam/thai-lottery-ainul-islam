<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Services\Lottery\Support\AbstractLotteryCalendarService;

/**
 * Asia/Bangkok business dates and Buddhist-era conversion for the Weekly lane.
 *
 * WHY THIS FILE IS FOUR LINES
 * ---------------------------------------------------------------------------
 * Every method it exposes lives in
 * App\Services\Lottery\Support\AbstractLotteryCalendarService, shared with the
 * National lane. Copying that logic would have produced two classes that each
 * add 543 somewhere, free to drift the moment one is corrected and the other
 * is not - and a page that prints the wrong era next to a draw date is stating
 * the wrong year, not making a cosmetic mistake.
 *
 * The only lane-specific fact is the config prefix, which is the only thing
 * this class states. The Buddhist offset, the year bounds, the accepted date
 * formats and the timezone all come from config/weekly_lottery.php, so the two
 * lanes can be configured differently while behaving identically.
 *
 * This is also the single reader of that offset: no Blade template and no
 * JavaScript file in the Weekly lane performs calendar arithmetic, and a test
 * greps those files to keep it that way.
 */
class WeeklyLotteryDateService extends AbstractLotteryCalendarService
{
    protected function configPrefix(): string
    {
        return 'weekly_lottery';
    }
}
