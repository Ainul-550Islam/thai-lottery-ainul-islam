<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Lottery;

use App\Enums\GloSourceState;
use App\Services\Lottery\GloLiveDrawService;
use App\Services\Lottery\GloNextDrawService;
use App\Services\Lottery\GloPrizeCatalogue;
use App\Services\Lottery\GloPublicHomeService;
use App\Services\Lottery\GloPublicPrizeSummaryService;
use App\Services\Lottery\GloPublicResultService;
use Mockery;
use Tests\TestCase;

final class GloPublicHomeServiceTest extends TestCase
{
    public function test_live_card_preserves_provider_outage_as_unavailable(): void
    {
        $live = Mockery::mock(GloLiveDrawService::class);
        $live->shouldReceive('liveStatus')->once()->andReturn([
            'live_status' => 'unavailable',
            'source_state' => GloSourceState::Unavailable->value,
            'provider' => null,
            'embed_url' => null,
            'replay_status' => 'unavailable',
            'message' => 'Provider could not be checked.',
        ]);

        $home = new GloPublicHomeService(
            Mockery::mock(GloPublicResultService::class),
            Mockery::mock(GloNextDrawService::class),
            Mockery::mock(GloPublicPrizeSummaryService::class),
            $live,
            Mockery::mock(GloPrizeCatalogue::class),
        );

        $card = $home->liveCard();

        self::assertSame('UNAVAILABLE', $card['status']);
        self::assertSame('unavailable', $card['live_status']);
        self::assertSame('UNAVAILABLE', $card['replay_status']);
        self::assertSame(GloSourceState::Unavailable->value, $card['source_state']);
    }

    public function test_live_card_keeps_missing_configuration_distinct_from_outage(): void
    {
        $live = Mockery::mock(GloLiveDrawService::class);
        $live->shouldReceive('liveStatus')->once()->andReturn([
            'live_status' => 'not_configured',
            'source_state' => GloSourceState::NotConfigured->value,
            'provider' => null,
            'embed_url' => null,
            'replay_status' => 'not_configured',
            'message' => 'No source has been configured.',
        ]);

        $home = new GloPublicHomeService(
            Mockery::mock(GloPublicResultService::class),
            Mockery::mock(GloNextDrawService::class),
            Mockery::mock(GloPublicPrizeSummaryService::class),
            $live,
            Mockery::mock(GloPrizeCatalogue::class),
        );

        $card = $home->liveCard();

        self::assertSame('NOT_CONFIGURED', $card['status']);
        self::assertSame('not_configured', $card['live_status']);
    }
}
