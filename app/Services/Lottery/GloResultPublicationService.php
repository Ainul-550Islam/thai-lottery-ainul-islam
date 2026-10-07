<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\DrawLifecycleState;
use App\Enums\GloPrizeTier;
use App\Enums\RiskLevel;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\DrawPublicationException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\WinningNumber;
use App\Services\Draw\DrawLifecycleService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Authoritative GLO Result Publication Service.
 *
 * Enforces explicit provenance tracking across all published GLO results.
 * Fail-Closed Provenance Matrix:
 *   - 'externally-authenticated': Verified against GLO cryptographic certificate / signed payload.
 *   - 'source-verified': Verified against documented official GLO HTTP endpoints.
 *   - 'source-known': Operator-entered with recorded operator provenance metadata.
 *   - 'fixture': Controlled local test fixture (PROHIBITED IN PRODUCTION).
 *   - 'unknown': REJECTED — UNKNOWN MUST NEVER FALL BACK TO OFFICIAL.
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
     * Publish an authoritative GLO result for a draw.
     *
     * @param array{
     *     first_prize: string,
     *     second_prize?: list<string>,
     *     third_prize?: list<string>,
     *     fourth_prize?: list<string>,
     *     fifth_prize?: list<string>,
     *     front_three?: list<string>,
     *     last_three?: list<string>,
     *     last_two?: string|list<string>,
     *     provenance: string,
     *     provenance_reference?: string,
     *     operator_id?: int,
     * } $payload
     * @return array{draw: Draw, result: DrawResult}
     */
    public function publish(int $drawId, array $payload): array
    {
        $provenance = (string) ($payload['provenance'] ?? self::PROVENANCE_UNKNOWN);
        $this->assertProvenanceAllowed($provenance);

        $firstPrize = trim((string) ($payload['first_prize'] ?? ''));
        if (! preg_match('/^\d{6}$/', $firstPrize)) {
            throw DrawPublicationException::malformedNumber('first_prize', $firstPrize, 'must be exactly 6 digits');
        }

        return $this->db->connection()->transaction(function () use ($drawId, $payload, $firstPrize, $provenance): array {
            $draw = $this->lifecycle->lockForUpdate($drawId);

            $this->lifecycle->assertCanPublishResult($draw, [
                'stage' => 'glo_publication',
                'provenance' => $provenance,
            ]);

            if (DrawResult::query()->where('draw_id', $drawId)->exists()) {
                throw DrawLifecycleException::duplicatePublication(
                    $drawId,
                    'an existing draw_results row already exists for draw',
                    ['stage' => 'glo_publication']
                );
            }

            $secondPrize = $this->sanitizeNumberList($payload['second_prize'] ?? [], 6, 5);
            $thirdPrize = $this->sanitizeNumberList($payload['third_prize'] ?? [], 6, 10);
            $fourthPrize = $this->sanitizeNumberList($payload['fourth_prize'] ?? [], 6, 50);
            $fifthPrize = $this->sanitizeNumberList($payload['fifth_prize'] ?? [], 6, 100);
            $frontThree = $this->sanitizeNumberList($payload['front_three'] ?? [], 3, 2);
            $lastThree = $this->sanitizeNumberList($payload['last_three'] ?? [], 3, 2);
            $lastTwo = is_array($payload['last_two'] ?? null)
                ? $this->sanitizeNumberList($payload['last_two'], 2, 1)
                : $this->sanitizeNumberList([$payload['last_two'] ?? ''], 2, 1);

            $tierMetadata = [
                'fourth' => $fourthPrize,
                'fifth' => $fifthPrize,
                'front_three' => $frontThree,
                'last_three' => $lastThree,
                'last_two' => $lastTwo,
                'provenance' => $provenance,
                'provenance_reference' => (string) ($payload['provenance_reference'] ?? ''),
                'published_at' => now()->toIso8601String(),
            ];

            $metadataKey = (string) $this->config->get('glo.tiers.metadata_key', 'glo');

            $result = new DrawResult();
            $result->draw_id = $draw->getKey();
            $result->first_prize = $firstPrize;
            $result->second_prize = $secondPrize;
            $result->third_prize = $thirdPrize;
            $result->published_at = now();
            $result->metadata = [
                $metadataKey => $tierMetadata,
                'provenance' => $provenance,
                'provenance_reference' => (string) ($payload['provenance_reference'] ?? ''),
                'imported_by_phase' => 'GLO_PUBLICATION_V1',
            ];
            $result->save();

            // Advance draw lifecycle
            $draw = $this->lifecycle->markResultPublished($draw, [
                'stage' => 'glo_publication',
                'first_prize' => $firstPrize,
                'provenance' => $provenance,
            ]);

            // Record audit log
            $operatorId = $payload['operator_id'] ?? null;
            if ($operatorId !== null) {
                AuditLog::create([
                    'user_id' => (int) $operatorId,
                    'action' => AuditAction::Create,
                    'risk_level' => RiskLevel::Critical,
                    'auditable_type' => DrawResult::class,
                    'auditable_id' => $result->getKey(),
                    'description' => 'glo_result_published',
                    'metadata' => [
                        'draw_id' => $drawId,
                        'first_prize' => $firstPrize,
                        'provenance' => $provenance,
                    ],
                ]);
            }

            return [
                'draw' => $draw,
                'result' => $result,
            ];
        });
    }

    /**
     * Validate that provenance is allowed in the current environment.
     */
    public function assertProvenanceAllowed(string $provenance): void
    {
        if ($provenance === self::PROVENANCE_UNKNOWN) {
            throw DrawPublicationException::untrustedProvenance('UNKNOWN provenance cannot be published as official result');
        }

        $appEnv = (string) $this->config->get('app.env', 'production');
        if ($appEnv === 'production' && $provenance === self::PROVENANCE_FIXTURE) {
            throw DrawPublicationException::untrustedProvenance('FIXTURE provenance is forbidden in production environment');
        }

        $valid = [
            self::PROVENANCE_EXTERNALLY_AUTHENTICATED,
            self::PROVENANCE_SOURCE_VERIFIED,
            self::PROVENANCE_SOURCE_KNOWN,
            self::PROVENANCE_FIXTURE,
        ];

        if (! in_array($provenance, $valid, true)) {
            throw DrawPublicationException::untrustedProvenance("Invalid provenance identifier '{$provenance}'");
        }
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private function sanitizeNumberList(mixed $list, int $expectedDigits, int $maxCount): array
    {
        if (! is_array($list)) {
            return [];
        }

        $sanitized = [];
        foreach ($list as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $num = sprintf("%0{$expectedDigits}s", preg_replace('/\D/', '', (string) $item));
            if (strlen($num) === $expectedDigits) {
                $sanitized[] = $num;
            }
            if (count($sanitized) >= $maxCount) {
                break;
            }
        }

        return array_values(array_unique($sanitized));
    }
}
