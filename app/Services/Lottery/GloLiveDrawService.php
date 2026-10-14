<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloSourceState;
use App\Enums\LiveStreamProvider;
use App\Exceptions\GloDealerException;

/**
 * GLO-18 live draw / replay metadata provider.
 *
 * The live draw is configured only when the operator explicitly sets the mode to
 * `configured` AND names a supported provider (youtube, facebook, tiktok) AND supplies a
 * public video URL that passes that provider's strict resolver. Otherwise it reports
 * NOT_CONFIGURED and never fabricates a stream. The embed URL returned is always rebuilt
 * from the canonical identifier, never the raw operator input.
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
        $replay = (string) config('glo.result_experience.live_draw.replay_catalog', 'not_configured');
        $rawEmbed = config('glo.result_experience.live_draw.embed_url');

        if ($mode !== 'configured') {
            return $this->notConfigured(
                $replay,
                'No authorized GLO live stream source is configured for this project.',
            );
        }

        $provider = LiveStreamProvider::fromConfig(config('glo.result_experience.live_draw.provider'));

        if ($provider === null) {
            return $this->notConfigured(
                $replay,
                'The configured live stream provider is not supported. Use youtube, facebook or tiktok.',
            );
        }

        $embed = LiveStreamEmbedResolver::resolve(
            $provider,
            is_string($rawEmbed) ? $rawEmbed : null,
        );

        if ($embed === null) {
            return $this->notConfigured(
                $replay,
                sprintf(
                    'The configured live stream URL is not an allowed %s video or live URL, so the live draw stays NOT_CONFIGURED.',
                    $provider->label(),
                ),
            );
        }

        return [
            'live_status' => 'configured',
            'source_state' => GloSourceState::OfficialSourceConfigured->value,
            'provider' => $provider->value,
            'embed_url' => $embed,
            'replay_status' => $replay,
            'message' => sprintf('Authorized %s live stream is configured.', $provider->value),
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
    private function notConfigured(string $replay, string $message): array
    {
        return [
            'live_status' => 'not_configured',
            'source_state' => GloSourceState::NotConfigured->value,
            'provider' => null,
            'embed_url' => null,
            'replay_status' => $replay,
            'message' => $message,
        ];
    }
}
