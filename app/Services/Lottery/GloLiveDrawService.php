<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloSourceState;
use App\Enums\LiveStreamProvider;
use App\Exceptions\GloDealerException;

/**
 * GLO-18 live draw / replay metadata provider.
 *
 * No authorized GLO live stream URL is configured by default. Operator input
 * is resolved through the provider allowlist and rebuilt as a canonical embed
 * URL; unsupported providers or malformed URLs stay NOT_CONFIGURED. A valid
 * URL is only CONFIGURED, never described as an official result source.
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
        $configuredProvider = config('glo.result_experience.live_draw.provider');
        $rawUrl = config('glo.result_experience.live_draw.embed_url');
        $replay = strtolower(trim((string) config('glo.result_experience.live_draw.replay_catalog', 'not_configured')));

        if ($mode !== 'configured' || ! is_string($rawUrl) || trim($rawUrl) === '') {
            return [
                'live_status' => 'not_configured',
                'source_state' => GloSourceState::NotConfigured->value,
                'provider' => null,
                'embed_url' => null,
                'replay_status' => $replay === 'configured' ? 'configured' : 'not_configured',
                'message' => 'No authorized GLO live stream source is configured for this project.',
            ];
        }

        // A blank provider retains the documented YouTube default for older
        // configuration. Explicit unsupported provider names are refused.
        $provider = LiveStreamProvider::fromConfig($configuredProvider);
        if ($provider === null && (! is_string($configuredProvider) || trim($configuredProvider) === '')) {
            $provider = LiveStreamProvider::YouTube;
        }

        if ($provider === null) {
            return [
                'live_status' => 'not_configured',
                'source_state' => GloSourceState::NotConfigured->value,
                'provider' => null,
                'embed_url' => null,
                'replay_status' => $replay === 'configured' ? 'configured' : 'not_configured',
                'message' => 'The configured live stream provider is not supported.',
            ];
        }

        $embedUrl = LiveStreamEmbedResolver::resolve($provider, $rawUrl);
        if ($embedUrl === null) {
            return [
                'live_status' => 'not_configured',
                'source_state' => GloSourceState::NotConfigured->value,
                'provider' => $provider->value,
                'embed_url' => null,
                'replay_status' => $replay === 'configured' ? 'configured' : 'not_configured',
                'message' => 'The configured URL is not an allowed '.$provider->label().' video or live URL.',
            ];
        }

        return [
            'live_status' => 'configured',
            'source_state' => GloSourceState::OfficialSourceConfigured->value,
            'provider' => $provider->value,
            'embed_url' => $embedUrl,
            'replay_status' => $replay === 'configured' ? 'configured' : 'not_configured',
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
