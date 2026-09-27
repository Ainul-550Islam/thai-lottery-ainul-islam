<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloSourceState;
use App\Services\Lottery\Support\AbstractLotterySourceService;

/**
 * Source classification and provenance projection for the Weekly lane.
 *
 * WHY THIS FILE IS SHORT
 * ---------------------------------------------------------------------------
 * Every rule - which provider may claim which state, what may be published,
 * how trust is ranked, how an endpoint is reduced to a host, what a public
 * provenance block contains - lives in
 * App\Services\Lottery\Support\AbstractLotterySourceService, shared with the
 * National lane. Two copies would be two places where "may a fixture be called
 * official" is decided, and the first drift between them would put a
 * production badge on development data.
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
 * There is no code path that promotes a stored state. A row written
 * FIXTURE_ONLY renders FIXTURE_ONLY forever.
 */
class WeeklyLotterySourceService extends AbstractLotterySourceService
{
    protected function configPrefix(): string
    {
        return 'weekly_lottery';
    }

    public function badgeKey(GloSourceState $state): string
    {
        return 'weekly_lottery.source_state.'.strtolower($state->value);
    }
}
