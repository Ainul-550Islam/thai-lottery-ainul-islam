<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloSourceState;
use App\Services\Lottery\Support\AbstractLotterySourceService;

/**
 * Source classification and provenance projection for the National lane.
 *
 * WHY THIS FILE SHRANK IN PROMPT 6
 * ---------------------------------------------------------------------------
 * Every rule that used to live here moved, UNCHANGED, into
 * App\Services\Lottery\Support\AbstractLotterySourceService when the Weekly
 * lane needed the same rules. Two copies would have been two places where
 * "may a fixture be called official" is decided, and the first drift between
 * them would have put a production badge on development data.
 *
 * The public API is unchanged from PROMPT 5 - priority(),
 * officialConfigured(), fixturesEnabled(), mayFallThroughToFixture(),
 * stateForProvider(), resolve(), isPublishableState(), trustRank(),
 * endpointHost(), publicProvenance(), publicState(), badgeKey() - so nothing
 * that consumed this class changed. publicProvenance() now accepts the
 * App\Contracts\Lottery\ProvidesResultProvenance interface instead of a
 * concrete model, which is a widening: every previous caller still type-checks.
 */
class NationalLotterySourceService extends AbstractLotterySourceService
{
    protected function configPrefix(): string
    {
        return 'national_lottery';
    }

    public function badgeKey(GloSourceState $state): string
    {
        return 'national_lottery.source_state.'.strtolower($state->value);
    }
}
