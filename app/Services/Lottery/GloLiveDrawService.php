<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloSourceState;
use App\Exceptions\GloDealerException;

/**
 * GLO-18 live draw / replay metadata provider.
 *
 * No authorized GLO live stream URL is configured for this project —
 * this provider reports NOT_CONFIGURED and never fabricates a stream.
 * If config later supplies an authorized embed_url it is returned as-is
 * (still labeled configured, not verified, until validated).
 */
class GloLiveDrawService
{
    /**
     * @return array{
     *     live_status: string,
     *     source_state: string,
     *     provider: string|null,
     *     embed_url: string|null,
     *     replay_status: string,
     *     message: string
     * }
     */
    public function liveStatus(): array
    {
        $mode = (string) config('glo.result_experience.live_draw.mode', 'not_configured');
        $provider = config('glo.result_experience.live_draw.provider');
        $embed = config('glo.result_experience.live_draw.embed_url');
        $replay = (string) config('glo.result_experience.live_draw.replay_catalog', 'not_configured');

        if ($mode === 'not_configured' || $embed === null || $embed === '') {
            return [
                'live_status' => 'not_configured',
                'source_state' => GloSourceState::NotConfigured->value,
                'provider' => is_string($provider) ? $provider : null,
                'embed_url' => null,
                'replay_status' => $replay,
                'message' => 'No authorized GLO live stream source is configured for this project.',
            ];
        }

        return [
            'live_status' => 'configured',
            'source_state' => GloSourceState::OfficialSourceConfigured->value,
            'provider' => is_string($provider) ? $provider : null,
            'embed_url' => (string) $embed,
            'replay_status' => $replay,
            'message' => 'Authorized live stream URL is configured.',
        ];
    }

    /**
     * Replay catalog listing — NOT_CONFIGURED without a real catalog.
     *
     * @return list<array<string, mixed>>
     */
    public function replays(): array
    {
        $status = $this->liveStatus();

        if ($status['replay_status'] === 'not_configured') {
            throw GloDealerException::liveDrawNotConfigured();
        }

        return [];
    }
}
