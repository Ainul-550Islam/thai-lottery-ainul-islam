<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Exceptions\DrawPublicationException;
use App\Services\Draw\DrawLifecycleService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;

/**
 * Fail-closed compatibility facade for the retired GLO publication API.
 *
 * This legacy service used to create and save DrawResult directly. That made
 * it a second authoritative writer and allowed a caller-supplied operator_id
 * to stand in for a persisted maker/checker conversation. Neither behavior is
 * safe. The class and method remain available for compatibility, but this API
 * cannot publish because it has no authenticated checker identity or trusted
 * confirmation context. Callers must stage through DrawResultIngestionService
 * and confirm through DrawResultConfirmationService, which delegates the only
 * authoritative write to DrawResultPublicationService.
 *
 * The dependencies and public provenance API are retained to avoid an
 * unnecessary construction/API break for existing integrations. They confer
 * no authority here: this facade does not read or mutate result rows.
 */
class GloResultPublicationService
{
    public const PROVENANCE_EXTERNALLY_AUTHENTICATED = 'externally-authenticated';

    public const PROVENANCE_SOURCE_VERIFIED = 'source-verified';

    public const PROVENANCE_SOURCE_KNOWN = 'source-known';

    public const PROVENANCE_FIXTURE = 'fixture';

    public const PROVENANCE_UNKNOWN = 'unknown';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ConfigRepository $config,
        private readonly DrawLifecycleService $lifecycle,
    ) {}

    /**
     * Compatibility endpoint. It always fails closed because the legacy
     * payload cannot prove a persisted, authenticated checker distinct from
     * the persisted ingestion maker. It never writes DrawResult or winning
     * numbers.
     *
     * @param array{
     *     first_prize?: string,
     *     second_prize?: list<string>,
     *     third_prize?: list<string>,
     *     fourth_prize?: list<string>,
     *     fifth_prize?: list<string>,
     *     front_three?: list<string>,
     *     last_three?: list<string>,
     *     last_two?: string|list<string>,
     *     provenance?: string,
     *     provenance_reference?: string,
     *     operator_id?: int,
     * } $payload
     * @return array{draw: never, result: never}
     *
     * @throws DrawPublicationException
     */
    public function publish(int $drawId, array $payload): array
    {
        $candidate = $payload['provenance'] ?? self::PROVENANCE_UNKNOWN;
        $provenance = is_string($candidate) ? $candidate : self::PROVENANCE_UNKNOWN;
        $this->assertProvenanceAllowed($provenance);

        throw DrawPublicationException::fourEyesRequired(
            'the legacy API cannot establish an authenticated persisted checker distinct from the ingestion maker; '
            .'stage and confirm through the controlled draw-result workflow',
            ['draw_id' => $drawId],
        );
    }

    /**
     * Validate a provenance label against the legacy facade's closed matrix.
     *
     * This validates a label only; successful validation does not authorize
     * publication. The actual writer independently resolves provenance from
     * persisted ingestion and GLO import records.
     */
    public function assertProvenanceAllowed(string $provenance): void
    {
        if ($provenance === self::PROVENANCE_UNKNOWN) {
            throw DrawPublicationException::untrustedProvenance(
                'unknown provenance cannot be published as an official result',
            );
        }

        if ($provenance === self::PROVENANCE_FIXTURE
            && (string) $this->config->get('app.env', 'production') === 'production'
        ) {
            throw DrawPublicationException::untrustedProvenance(
                'fixture provenance is forbidden in production',
            );
        }

        if (! in_array($provenance, [
            self::PROVENANCE_EXTERNALLY_AUTHENTICATED,
            self::PROVENANCE_SOURCE_VERIFIED,
            self::PROVENANCE_SOURCE_KNOWN,
            self::PROVENANCE_FIXTURE,
        ], true)) {
            throw DrawPublicationException::untrustedProvenance(
                'the provenance label is outside the supported closed set',
            );
        }
    }
}
