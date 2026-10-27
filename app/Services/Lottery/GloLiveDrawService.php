<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloSourceState;
use App\Enums\LiveStreamProvider;
use App\Exceptions\GloDealerException;

/**
 * GLO-18 live draw / replay metadata provider.
 *
 * The operator supplies ONE public video URL in configuration. This service is
 * the only place that turns it into something the page may embed, and it does so
 * through LiveStreamEmbedResolver, which enforces the per-provider host
 * allowlist and rebuilds the URL from a canonical video identifier.
 *
 * WHAT IT REFUSES, AND WHY IT REFUSES RATHER THAN PASSTHROUGH
 * -----------------------------------------------------------
 * The previous behaviour returned the configured string verbatim and labelled
 * the stream "configured". That made the configuration value a trusted input to
 * an iframe: any string an operator pasted — a TikTok LIVE page, a lookalike
 * domain, a tracker-laden URL, an attacker's page after a config mistake —
 * reached visitors' browsers inside the site. A LIVE page in particular cannot
 * be embedded at all, so the visitor saw a broken frame presented as the feed.
 *
 * A refused URL is therefore NOT an error to swallow silently and NOT a reason
 * to fall back to something else: the card reports NOT_CONFIGURED with the
 * reason, because "there is no embeddable live stream" is the truthful state.
 *
 * Source state is OFFICIAL_SOURCE_CONFIGURED at best, never VERIFIED: a
 * configured embed URL proves the operator configured something, not that GLO
 * authorised it.
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
        $rawProvider = config('glo.result_experience.live_draw.provider');
        $rawEmbed = config('glo.result_experience.live_draw.embed_url');
        $replay = (string) config('glo.result_experience.live_draw.replay_catalog', 'not_configured');

        $embed = is_string($rawEmbed) ? trim($rawEmbed) : '';

        if ($mode !== 'configured') {
            return $this->refused(
                $replay,
                'No authorized GLO live stream source is configured for this project.',
                LiveStreamProvider::fromConfig($rawProvider)?->value,
            );
        }

        if ($embed === '') {
            return $this->refused(
                $replay,
                'Live stream mode is configured but no stream URL was supplied.',
                LiveStreamProvider::fromConfig($rawProvider)?->value,
            );
        }

        $declared = is_string($rawProvider) ? trim($rawProvider) : '';
        $provider = LiveStreamProvider::fromConfig($rawProvider);

        if ($provider === null && $declared !== '') {
            // An operator named a platform this build does not embed. Falling back
            // to "whatever the URL looks like" would silently ignore what they
            // configured, so the card says the provider is unsupported instead.
            return $this->refused(
                $replay,
                'The configured live stream provider is not supported; allowed providers are YouTube, Facebook and TikTok.',
            );
        }

        if ($provider === null) {
            // Legacy configuration: `provider` was not part of the original
            // setting, so the platform is derived from the URL itself. The
            // resolver's allowlists are the authority — a host that matches none
            // of them yields no provider and therefore no embed.
            $provider = $this->inferProvider($embed);

            if ($provider === null) {
                // No platform could be derived, so the honest statement is that
                // the URL did not pass the primary platform's allowlist either —
                // the message names the strictest rule rather than guessing which
                // platform was intended.
                return $this->refused($replay, $this->refusalReason(LiveStreamProvider::YouTube));
            }
        }

        $embedUrl = LiveStreamEmbedResolver::resolve($provider, $embed);

        if ($embedUrl === null) {
            return $this->refused($replay, $this->refusalReason($provider), $provider->value);
        }

        return [
            'live_status' => 'configured',
            'source_state' => GloSourceState::OfficialSourceConfigured->value,
            'provider' => $provider->value,
            'embed_url' => $embedUrl,
            'replay_status' => $replay,
            'message' => 'An authorized '.$provider->label().' live stream URL is configured.',
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
     * Derive the platform from the URL when configuration does not name one.
     *
     * The check is "does the strict resolver accept this URL for that provider",
     * not "does the host contain the provider's name", so a lookalike domain or a
     * tracker-laden link cannot become an embed by being classified here.
     */
    private function inferProvider(string $url): ?LiveStreamProvider
    {
        foreach (LiveStreamProvider::cases() as $candidate) {
            if (LiveStreamEmbedResolver::resolve($candidate, $url) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The visitor-facing reason a configured URL was refused. Naming the
     * provider and what it accepts is what lets an operator fix the setting
     * without reading the resolver's source.
     */
    private function refusalReason(LiveStreamProvider $provider): string
    {
        return match ($provider) {
            LiveStreamProvider::TikTok => 'The configured URL is not an allowed TikTok video or live URL; TikTok LIVE pages cannot be embedded and are refused.',
            LiveStreamProvider::Facebook => 'The configured URL is not an allowed Facebook video or live URL; only facebook.com video, reel and watch links are accepted over https.',
            LiveStreamProvider::YouTube => 'The configured URL is not an allowed YouTube video or live URL; only youtube.com and youtu.be identifiers are accepted over https.',
        };
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
    private function refused(string $replay, string $message, ?string $provider = null): array
    {
        return [
            'live_status' => 'not_configured',
            'source_state' => GloSourceState::NotConfigured->value,
            'provider' => $provider,
            'embed_url' => null,
            'replay_status' => $replay,
            'message' => $message,
        ];
    }
}
