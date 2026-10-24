<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use App\Enums\GloSourceState;
use App\Exceptions\DrawResultException;
use App\Exceptions\DrawResultValidationException;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\Draw\DrawResultPublicationService;

/** Source disclosure only: never a funds or publication authorization. */
final class PublishedResultSourceResolver
{
    public function __construct(private readonly DrawResultPublicationService $publication) {}

    public function resolve(Draw|DrawResult|null $first, Draw|DrawResult|null $second = null): GloSourceState
    {
        $result = $first instanceof DrawResult ? $first : ($second instanceof DrawResult ? $second : null);
        $draw = $first instanceof Draw ? $first : ($second instanceof Draw ? $second : null);

        if ($result === null || $draw === null) {
            return GloSourceState::Unavailable;
        }
        $meta = is_array($result->metadata) ? $result->metadata : [];
        $key = (string) config('glo.tiers.metadata_key', 'glo');
        $lane = is_array($meta[$key] ?? null) ? $meta[$key] : [];
        $provider = is_string($lane['import_provider'] ?? null) ? strtolower(trim($lane['import_provider'])) : '';

        // A legacy fixture declaration can only disclose demo data, never
        // prove verification. Production must not treat it as a public source.
        if (in_array($provider, ['fixture', 'fixture_only'], true) || ($meta['provenance'] ?? null) === 'fixture') {
            return (string) config('app.env') === 'production'
                ? GloSourceState::Unavailable : GloSourceState::FixtureOnly;
        }

        try {
            [$provenance] = $this->publication->verifiedProvenanceFor($draw, $result);

            return match ($provenance) {
                'source-verified' => GloSourceState::OfficialSourceVerified,
                'source-known' => GloSourceState::InternalReconciled,
                default => GloSourceState::Unavailable,
            };
        } catch (DrawResultException|DrawResultValidationException) {
            // Missing/contradictory evidence is a downgrade, not an official
            // badge inferred from a provider string or completed draw status.
            return GloSourceState::Unavailable;
        }
    }

    public function isDeclaredFixture(DrawResult $result): bool
    {
        $meta = is_array($result->metadata) ? $result->metadata : [];
        $lane = $meta[(string) config('glo.tiers.metadata_key', 'glo')] ?? [];
        $provider = is_array($lane) && is_string($lane['import_provider'] ?? null)
            ? strtolower(trim($lane['import_provider'])) : '';

        return ($meta['provenance'] ?? null) === 'fixture'
            || in_array($provider, ['fixture', 'fixture_only'], true);
    }
}
