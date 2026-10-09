<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\DrawConfirmationStatus;
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
use App\Services\Draw\DrawResultIngestionService;
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

        // ── FOUR-EYES GATE ────────────────────────────────────────────────
        //
        // This service was a SECOND publication door that bypassed the whole
        // maker/checker pipeline. It wrote the draw_results row itself, set
        // published_at itself, and — critically — took `operator_id` from the
        // CALLER-SUPPLIED PAYLOAD. A caller could therefore name any operator,
        // or none, and publish an official result unilaterally.
        //
        // It had no production callers, which is the only reason this was not a
        // live exploit rather than a latent one. It is guarded here rather than
        // deleted because a class that is resolvable from the container is
        // callable by any future code, and "nobody calls it today" is not a
        // security control.
        //
        // Publication may now only proceed when the draw carries a CONFIRMED
        // ingestion — i.e. the four-eyes pipeline has actually been traversed by
        // two different operators. That record is written exclusively by
        // DrawResultConfirmationService::confirm().
        $this->assertFourEyesSatisfied($drawId, $payload);

        $firstPrize = trim((string) ($payload['first_prize'] ?? ''));
        if (! preg_match('/^\d{6}$/', $firstPrize)) {
            // Was `DrawPublicationException::malformedNumber(...)`, a factory that
            // does not exist on the class — so this line was a guaranteed
            // "Call to undefined method" fatal. Together with the missing
            // four-eyes gate and the fact that nothing calls this service, that
            // is strong evidence this publication path has never successfully
            // run once. `malformed()` is the real factory.
            throw DrawPublicationException::malformed(
                sprintf('first_prize [%s] must be exactly 6 digits', $firstPrize),
                ['draw_id' => $drawId, 'field' => 'first_prize'],
            );
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
    /**
     * Refuse unless a SECOND operator has confirmed this draw's ingestion.
     *
     * THE CHECK
     * The draw metadata carries the ingestion record at
     * DrawResultIngestionService::METADATA_KEY. A record only reaches
     * DrawConfirmationStatus::Confirmed through
     * DrawResultConfirmationService::confirm(), which refuses when the
     * confirming operator is the ingesting one. Requiring that status here means
     * this door cannot be used to bypass the separation — there is no payload a
     * caller can supply that fabricates it.
     *
     * WHY IT ALSO REFUSES AN UNCONFIRMED RECORD RATHER THAN PUBLISHING IT
     * A Pending record means exactly one operator has seen these numbers. This
     * method is therefore the second half of the same control the confirmation
     * service implements, expressed on the other door into the same table.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws DrawPublicationException
     */
    private function assertFourEyesSatisfied(int $drawId, array $payload): void
    {
        $draw = Draw::query()->find($drawId);

        if ($draw === null) {
            throw DrawPublicationException::fourEyesRequired(
                sprintf('draw %d does not exist, so no ingestion could have been confirmed.', $drawId),
                ['draw_id' => $drawId],
            );
        }

        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $record = $metadata[DrawResultIngestionService::METADATA_KEY] ?? null;

        if (! is_array($record)) {
            throw DrawPublicationException::fourEyesRequired(
                sprintf(
                    'draw %d has no ingestion record. An official result must be ingested and '
                    .'confirmed by two different operators before it can be published.',
                    $drawId,
                ),
                ['draw_id' => $drawId],
            );
        }

        $status = (string) ($record['status'] ?? '');

        if ($status !== DrawConfirmationStatus::Confirmed->value) {
            throw DrawPublicationException::fourEyesRequired(
                sprintf(
                    'draw %d\'s ingestion is "%s", not "%s". A second operator must confirm the '
                    .'result before publication.',
                    $drawId,
                    $status === '' ? 'unknown' : $status,
                    DrawConfirmationStatus::Confirmed->value,
                ),
                ['draw_id' => $drawId, 'ingestion_status' => $status],
            );
        }

        $ingestedBy = isset($record['ingested_by']) ? (int) $record['ingested_by'] : null;
        $confirmedBy = isset($record['confirmed_by']) ? (int) $record['confirmed_by'] : null;

        if ($ingestedBy === null || $confirmedBy === null || $ingestedBy === $confirmedBy) {
            throw DrawPublicationException::fourEyesRequired(
                sprintf(
                    'draw %d has no attributable separation between the ingesting and confirming '
                    .'operator (ingested_by=%s, confirmed_by=%s).',
                    $drawId,
                    $ingestedBy === null ? 'null' : (string) $ingestedBy,
                    $confirmedBy === null ? 'null' : (string) $confirmedBy,
                ),
                ['draw_id' => $drawId],
            );
        }

        // The publisher must not be the person who entered the numbers either —
        // otherwise the "second pair of eyes" is the same pair, twice.
        $operatorId = isset($payload['operator_id']) ? (int) $payload['operator_id'] : null;

        if ($operatorId !== null && $operatorId === $ingestedBy) {
            throw DrawPublicationException::fourEyesRequired(
                sprintf('operator %d ingested draw %d and may not also publish it.', $operatorId, $drawId),
                ['draw_id' => $drawId, 'operator_id' => $operatorId],
            );
        }
    }

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
