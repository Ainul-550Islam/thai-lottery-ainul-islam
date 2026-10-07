<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Exceptions\DrawPublicationException;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultPublicationService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Compatibility facade over the authoritative four-eyes publication boundary.
 * This class deliberately performs no result persistence of its own.
 */
class GloResultPublicationService
{
    public const PROVENANCE_EXTERNALLY_AUTHENTICATED = 'externally-authenticated';
    public const PROVENANCE_SOURCE_VERIFIED = 'source-verified';
    public const PROVENANCE_SOURCE_KNOWN = 'source-known';
    public const PROVENANCE_FIXTURE = 'fixture';
    public const PROVENANCE_UNKNOWN = 'unknown';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DrawResultConfirmationService $confirmation,
        private readonly DrawResultPublicationService $publication,
    ) {}

    /**
     * @param array{first_prize: string, bottom_two?: string|null, provenance: string, provenance_reference?: string, operator_id: int} $payload
     * @return array{draw: Draw, result: DrawResult}
     */
    public function publish(int $drawId, array $payload): array
    {
        $this->assertProvenanceAllowed((string) ($payload['provenance'] ?? self::PROVENANCE_UNKNOWN));
        $operatorId = filter_var($payload['operator_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($operatorId === false) {
            throw DrawPublicationException::untrustedProvenance('A valid second-operator id is required.');
        }

        $firstPrize = trim((string) ($payload['first_prize'] ?? ''));
        $bottomTwo = array_key_exists('bottom_two', $payload)
            ? trim((string) $payload['bottom_two'])
            : substr($firstPrize, -2);

        $this->confirmation->confirm($drawId, (int) $operatorId, [
            'first_prize' => $firstPrize,
            'bottom_two' => $bottomTwo,
        ]);

        $draw = Draw::query()->find($drawId);
        $result = $this->publication->resultFor($drawId);

        if (! $draw instanceof Draw || ! $result instanceof DrawResult) {
            throw DrawPublicationException::untrustedProvenance('Authoritative publication was incomplete.');
        }

        return ['draw' => $draw, 'result' => $result];
    }

    public function assertProvenanceAllowed(string $provenance): void
    {
        $valid = [
            self::PROVENANCE_EXTERNALLY_AUTHENTICATED,
            self::PROVENANCE_SOURCE_VERIFIED,
            self::PROVENANCE_SOURCE_KNOWN,
            self::PROVENANCE_FIXTURE,
        ];

        if (! in_array($provenance, $valid, true)) {
            throw DrawPublicationException::untrustedProvenance('Unknown or invalid provenance is forbidden.');
        }

        if ((string) $this->config->get('app.env', 'production') === 'production'
            && $provenance === self::PROVENANCE_FIXTURE) {
            throw DrawPublicationException::untrustedProvenance('Fixture provenance is forbidden in production.');
        }
    }
}
