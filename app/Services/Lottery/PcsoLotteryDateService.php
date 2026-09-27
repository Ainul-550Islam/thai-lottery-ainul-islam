<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Services\Lottery\Support\AbstractLotteryCalendarService;

/**
 * Asia/Bangkok business dates and Buddhist-era conversion for the Bingo/Mega
 * lane (PROMPT 9).
 *
 * Every method it exposes lives in AbstractLotteryCalendarService, shared with
 * the National and Weekly lanes. Copying that logic would produce classes that
 * each add 543 somewhere, free to drift the moment one is corrected and the
 * other is not - and a page that prints the wrong era next to a draw date is
 * stating the wrong year, not making a cosmetic mistake.
 *
 * The only lane-specific fact is the config prefix, which is the only thing
 * this class states. The Buddhist offset, the year bounds, the accepted date
 * formats and the timezone all come from config/pcso_lottery.php.
 *
 * This is also the single reader of that offset: no Blade template and no
 * JavaScript file in this lane performs calendar arithmetic, and a test greps
 * those files to keep it that way.
 */
class PcsoLotteryDateService extends AbstractLotteryCalendarService
{
    protected function configPrefix(): string
    {
        return 'pcso_lottery';
    }
}
