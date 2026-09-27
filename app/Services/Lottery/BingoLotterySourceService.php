<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloSourceState;
use App\Services\Lottery\Support\AbstractLotterySourceService;

/**
 * Source classification and provenance projection for the Bingo/Mega lane
 * (PROMPT 8).
 *
 * Every rule - which provider may claim which state, what may be published,
 * how trust is ranked, how an endpoint is reduced to a host, what a public
 * provenance block contains - lives in AbstractLotterySourceService, shared
 * with the National and Weekly lanes. Two copies would be two places where
 * "may a fixture be called official" is decided, and the first drift between
 * them would put a production badge on development data.
 *
 * WHAT THIS LANE INHERITS, RESTATED SO IT IS NOT A SURPRISE
 * ---------------------------------------------------------------------------
 * fixture  -> FIXTURE_ONLY (only while fixtures are enabled, else NOT_CONFIGURED)
 * internal -> INTERNAL_RECONCILED
 * replay   -> INTERNAL_RECONCILED at best; a re-read of a payload we already
 *             hold brings no new external evidence, so it can never outrank one
 * official -> OFFICIAL_SOURCE_VERIFIED only when an endpoint is configured AND
 *             the payload validated; NOT_CONFIGURED when no endpoint exists
 * anything else -> UNAVAILABLE
 *
 * config/bingo_lottery.php ships no endpoint, so in this repository the lane
 * cannot reach OFFICIAL_SOURCE_VERIFIED at all. There is no code path that
 * promotes a stored state: a row written FIXTURE_ONLY renders FIXTURE_ONLY
 * forever, whatever is configured later.
 */
class BingoLotterySourceService extends AbstractLotterySourceService
{
    protected function configPrefix(): string
    {
        return 'bingo_lottery';
    }

    public function badgeKey(GloSourceState $state): string
    {
        return 'bingo_lottery.source_state.'.strtolower($state->value);
    }
}
