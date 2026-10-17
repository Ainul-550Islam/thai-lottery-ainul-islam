<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloSourceState;
use App\Enums\LiveStreamProvider;
use App\Exceptions\GloDealerException;

/**
 * GLO-18 live draw / replay metadata provider.
 *
 * The live draw is NOT_CONFIGURED by default. It becomes CONFIGURED only
 * when the operator sets mode= configured AND names a supported provider
 * AND supplies a URL on that provider's allowlist; the returned embed_url
 * is the canonical embed rebuilt by LiveStreamEmbedResolver from the
 * validated video identifier, never the raw operator input (tracking
 * parameters and foreign hosts cannot reach the page). A missing provider
 * defaults to YouTube so existing YouTube-only configuration keeps working.
 * Every other input keeps the honest NOT_CONFIGURED state, and a TikTok
 * LIVE page is refused because it has no embeddable identifier.
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
        $mode = strtolower(trim((string) config('glo.result_experience.live_draw.mode', 'not_configured')));
        $providerConfig = config('glo.result_experience.live_draw.provider');
        $embed = config('glo.result_experience.live_draw.embed_url');
        $replay = (string) config('glo.result_experience.live_draw.replay_catalog', 'not_configured');

        $notConfigured = fn (?string $provider, string $message): array => [
            'live_status' => 'not_configured',
            'source_state' => GloSourceState::NotConfigured->value,
            'provider' => $provider,
            'embed_url' => null,
            'replay_status' => $replay,
            'message' => $message,
        ];

        if ($mode !== 'configured') {
            return $notConfigured(
                is_string($providerConfig) ? LiveStreamProvider::fromConfig($providerConfig)?->value : null,
                'No authorized GLO live stream source is configured for this project.',
            );
        }

        if (is_string($providerConfig) && trim($providerConfig) !== '') {
            $provider = LiveStreamProvider::fromConfig($providerConfig);

            if ($provider === null) {
                return $notConfigured(
                    null,
                    sprintf('The configured live stream provider is not supported: %s', trim($providerConfig)),
                );
            }
        } else {
            $provider = LiveStreamProvider::YouTube;
        }

        $embedUrl = LiveStreamEmbedResolver::resolve($provider, is_string($embed) ? $embed : null);

        if ($embedUrl === null) {
            return $notConfigured(
                $provider->value,
                sprintf('The configured live stream URL is not an allowed %s video or live URL.', $provider->label()),
            );
        }

        return [
            'live_status' => 'configured',
            'source_state' => GloSourceState::OfficialSourceConfigured->value,
            'provider' => $provider->value,
            'embed_url' => $embedUrl,
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
