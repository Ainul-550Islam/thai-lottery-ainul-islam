<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\DrawPublicationStatus;
use App\Enums\GloSourceState;
use App\Enums\ResultVersionState;
use App\Models\NationalLotteryDraw;
use App\Models\NationalLotteryResult;
use App\Models\NationalLotteryResultVersion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only writer in the National Lottery lane.
 *
 * FOUR OUTCOMES, AND ONLY FOUR
 * ---------------------------------------------------------------------------
 *   IMPORTED   a new, valid version was stored (and published, if policy and
 *              source state allow it);
 *   DUPLICATE  this exact payload was already stored for this draw - nothing
 *              was written, and that is a SUCCESS, not an error;
 *   CONFLICT   a payload disagrees with an already-verified version about the
 *              numbers. The version is stored in state CONFLICT, publication
 *              is BLOCKED, and the live page keeps showing the previously
 *              verified result;
 *   REJECTED   the payload failed field validation. Nothing is written,
 *              because a malformed payload is not evidence of anything.
 *
 * IDEMPOTENCY IS ENFORCED BY THE DATABASE, NOT BY A CHECK
 * ---------------------------------------------------------------------------
 * The fingerprint pre-check below is an optimisation; the guarantee is the
 * UNIQUE (draw_id, payload_fingerprint) index. Two workers importing the same
 * payload at the same moment both pass the pre-check, both attempt the
 * insert, one wins, and the loser catches the violation and reports
 * DUPLICATE with the winner's version. A check-then-insert without the
 * constraint would let both through in exactly that window - which is the
 * window a retrying queue hits most often.
 *
 * The draw row is created under the same discipline: national_lottery_draws
 * has UNIQUE (draw_date), so two workers importing the same date cannot
 * create two draws, and the whole body runs inside a transaction with
 * lockForUpdate() on the draw once it exists.
 *
 * PUBLISHED RESULTS ARE NEVER EDITED
 * ---------------------------------------------------------------------------
 * There is no UPDATE of a result value in this file. A correction inserts a
 * new version and a new result row, flips the old row's is_current to false,
 * marks the old version SUPERSEDED, and records who did it, from what source,
 * why, and both fingerprints. The old numbers remain readable forever, which
 * is what makes a correction notice honest rather than a silent rewrite.
 *
 * A FIXTURE NEVER BECOMES OFFICIAL
 * ---------------------------------------------------------------------------
 * The caller names a PROVIDER; NationalLotterySourceService decides what that
 * provider is entitled to be called. There is no parameter on any method here
 * that accepts a source state.
 */
class NationalLotteryImportService
{
    public const STATUS_IMPORTED = 'imported';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly NationalLotteryDateService $dates,
        private readonly NationalLotterySourceService $sources,
        private readonly NationalLotteryResultService $results,
        private readonly NationalLotteryHistoryService $history,
    ) {}

    /**
     * Import one draw's result.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     status: string,
     *     draw_reference: string|null,
     *     version: int|null,
     *     source_state: string,
     *     published: bool,
     *     errors: list<string>,
     *     message: string
     * }
     */
    public function import(array $payload, string $provider, ?int $actorId = null, bool $autoPublish = true): array
    {
        $provider = trim($provider);

        if (! in_array($provider, $this->sources->priority(), true)) {
            return $this->outcome(
                self::STATUS_REJECTED,
                null,
                null,
                GloSourceState::Unavailable,
                false,
                ['UNKNOWN_PROVIDER'],
                'Unknown provider.',
            );
        }

        if ($provider === 'fixture' && ! $this->sources->fixturesEnabled()) {
            return $this->outcome(
                self::STATUS_REJECTED,
                null,
                null,
                GloSourceState::NotConfigured,
                false,
                ['FIXTURE_LANE_DISABLED'],
                'The fixture lane is disabled.',
            );
        }

        $normalised = $this->normalise($payload);

        if ($normalised['errors'] !== []) {
            return $this->outcome(
                self::STATUS_REJECTED,
                null,
                null,
                $this->sources->stateForProvider($provider, false),
                false,
                $normalised['errors'],
                'Payload rejected by field validation.',
            );
        }

        $sourceState = $this->sources->stateForProvider($provider, true);

        if ($sourceState === GloSourceState::NotConfigured) {
            return $this->outcome(
                self::STATUS_REJECTED,
                null,
                null,
                $sourceState,
                false,
                ['PROVIDER_NOT_CONFIGURED'],
                'The provider is not configured; no source state can be claimed for this payload.',
            );
        }

        $payloadFingerprint = $this->payloadFingerprint($payload);
        $normalizedFingerprint = $this->normalizedFingerprint($normalised['values'], $normalised['iso_date']);

        try {
            return DB::transaction(function () use (
                $normalised,
                $provider,
                $sourceState,
                $payloadFingerprint,
                $normalizedFingerprint,
                $actorId,
                $autoPublish,
                $payload
            ): array {
                $draw = $this->lockOrCreateDraw($normalised['iso_date']);

                // --- Idempotency pre-check (the index is the guarantee) ----
                $existing = NationalLotteryResultVersion::query()
                    ->where('draw_id', $draw->id)
                    ->where('payload_fingerprint', $payloadFingerprint)
                    ->first();

                if ($existing instanceof NationalLotteryResultVersion) {
                    return $this->outcome(
                        self::STATUS_DUPLICATE,
                        (string) $draw->draw_reference,
                        (int) $existing->version_number,
                        $this->sources->resolve($existing->source_state),
                        $draw->isPubliclyLive(),
                        [],
                        'Payload already imported; no new version was created.',
                    );
                }

                // --- Conflict detection --------------------------------------
                $verified = NationalLotteryResultVersion::query()
                    ->where('draw_id', $draw->id)
                    ->where('state', ResultVersionState::Verified->value)
                    ->orderByDesc('version_number')
                    ->first();

                if ($verified instanceof NationalLotteryResultVersion
                    && hash_equals((string) $verified->normalized_fingerprint, $normalizedFingerprint)) {
                    // The envelope differs (a re-fetch carries a new
                    // retrieved_at, so the payload hash moved) but the NUMBERS
                    // are identical to what is already verified. Storing a new
                    // version here would supersede a correct record with an
                    // identical one and fill the audit trail with noise, so
                    // this is reported as the no-op it is.
                    return $this->outcome(
                        self::STATUS_DUPLICATE,
                        (string) $draw->draw_reference,
                        (int) $verified->version_number,
                        $this->sources->resolve($verified->source_state),
                        $draw->isPubliclyLive(),
                        [],
                        'The verified version already states these values; no new version was created.',
                    );
                }

                $isConflict = $verified instanceof NationalLotteryResultVersion
                    && ! hash_equals(
                        (string) $verified->normalized_fingerprint,
                        $normalizedFingerprint,
                    );

                $versionNumber = ((int) NationalLotteryResultVersion::query()
                    ->where('draw_id', $draw->id)
                    ->max('version_number')) + 1;

                $state = $isConflict
                    ? ResultVersionState::Conflict
                    : ResultVersionState::Verified;

                $version = new NationalLotteryResultVersion;
                $version->forceFill([
                    'draw_id' => $draw->id,
                    'version_number' => $versionNumber,
                    'state' => $state,
                    'provider' => $provider,
                    'source_state' => $sourceState->value,
                    'source_identifier' => $normalised['source_identifier'],
                    'source_endpoint_host' => $provider === 'official'
                        ? $this->sources->endpointHost(
                            $this->stringConfig('national_lottery.sources.official.endpoint'),
                        )
                        : null,
                    'payload_fingerprint' => $payloadFingerprint,
                    'normalized_fingerprint' => $normalizedFingerprint,
                    'parser_version' => $this->parserVersion(),
                    'retrieved_at' => $normalised['retrieved_at'],
                    'imported_at' => now(),
                    'imported_by' => $actorId,
                    'validation_status' => NationalLotteryResultVersion::VALIDATION_PASSED,
                    'validation_errors' => null,
                    'conflict_reason' => $isConflict ? 'NORMALIZED_FINGERPRINT_MISMATCH' : null,
                    'audit' => [
                        'action' => $isConflict ? 'conflict_recorded' : 'version_imported',
                        'provider' => $provider,
                        'actor_id' => $actorId,
                        'payload_keys' => array_values(array_map('strval', array_keys($payload))),
                    ],
                ]);

                try {
                    $version->save();
                } catch (UniqueConstraintViolationException|QueryException $exception) {
                    // Lost the race. The winner's row is the truth.
                    $winner = NationalLotteryResultVersion::query()
                        ->where('draw_id', $draw->id)
                        ->where('payload_fingerprint', $payloadFingerprint)
                        ->first();

                    if (! $winner instanceof NationalLotteryResultVersion) {
                        throw $exception;
                    }

                    return $this->outcome(
                        self::STATUS_DUPLICATE,
                        (string) $draw->draw_reference,
                        (int) $winner->version_number,
                        $this->sources->resolve($winner->source_state),
                        $draw->isPubliclyLive(),
                        [],
                        'Payload already imported by a concurrent writer; no new version was created.',
                    );
                }

                // The numbers themselves: one row per version, always inserted.
                $result = new NationalLotteryResult;
                $result->forceFill([
                    'draw_id' => $draw->id,
                    'result_version_id' => $version->id,
                    'first_prize' => $normalised['values']['first_prize'],
                    'three_up' => $normalised['values']['three_up'],
                    'two_up' => $normalised['values']['two_up'],
                    'two_down' => $normalised['values']['two_down'],
                    'three_front' => $normalised['values']['three_front'],
                    'three_after' => $normalised['values']['three_after'],
                    'three_front_count' => count($normalised['values']['three_front']),
                    'three_after_count' => count($normalised['values']['three_after']),
                    'is_current' => false,
                ]);
                $result->save();

                if ($isConflict) {
                    // Publication is blocked. The live page keeps showing the
                    // previously verified version.
                    $draw->forceFill([
                        'status' => NationalLotteryDraw::STATUS_CONFLICT,
                    ])->save();

                    return $this->outcome(
                        self::STATUS_CONFLICT,
                        (string) $draw->draw_reference,
                        $versionNumber,
                        $sourceState,
                        // THIS version was not published. The draw may still
                        // be live with its previously verified result, which
                        // is a different fact and is not reported here.
                        false,
                        ['NORMALIZED_FINGERPRINT_MISMATCH'],
                        'Payload disagrees with the verified version; publication blocked pending resolution.',
                    );
                }

                $published = false;

                if ($autoPublish && $this->mayPublish($draw, $sourceState)) {
                    $this->publishVersion($draw, $version, $result, $verified, $actorId, 'import');
                    $published = true;
                }

                return $this->outcome(
                    self::STATUS_IMPORTED,
                    (string) $draw->draw_reference,
                    $versionNumber,
                    $sourceState,
                    $published,
                    [],
                    $published
                        ? 'Version imported and published.'
                        : 'Version imported; publication withheld.',
                );
            });
        } catch (Throwable $exception) {
            // The message is generic on purpose: a driver exception can carry
            // an SQLSTATE and a fragment of SQL, and neither belongs in
            // anything a caller might surface.
            report($exception);

            return $this->outcome(
                self::STATUS_REJECTED,
                null,
                null,
                $sourceState,
                false,
                ['IMPORT_FAILED'],
                'Import failed and was rolled back.',
            );
        }
    }

    /**
     * Resolve a recorded conflict by publishing the conflicting version as a
     * correction.
     *
     * An explicit, attributed act: the actor, the reason, and both
     * fingerprints are written into the audit trail. The superseded version
     * and its numbers are retained.
     *
     * @return array{
     *     status: string,
     *     draw_reference: string|null,
     *     version: int|null,
     *     source_state: string,
     *     published: bool,
     *     errors: list<string>,
     *     message: string
     * }
     */
    public function resolveConflict(
        NationalLotteryResultVersion $version,
        int $actorId,
        string $reason,
    ): array {
        $reason = trim($reason);

        if ($reason === '') {
            return $this->outcome(
                self::STATUS_REJECTED,
                null,
                null,
                $this->sources->resolve($version->source_state),
                false,
                ['RESOLUTION_REASON_REQUIRED'],
                'A resolution reason is required.',
            );
        }

        try {
            return DB::transaction(function () use ($version, $actorId, $reason): array {
                /** @var NationalLotteryResultVersion|null $locked */
                $locked = NationalLotteryResultVersion::query()
                    ->whereKey($version->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $locked instanceof NationalLotteryResultVersion
                    || $locked->state !== ResultVersionState::Conflict) {
                    return $this->outcome(
                        self::STATUS_REJECTED,
                        null,
                        null,
                        $this->sources->resolve($version->source_state),
                        false,
                        ['VERSION_NOT_IN_CONFLICT'],
                        'Only a version in CONFLICT can be resolved.',
                    );
                }

                /** @var NationalLotteryDraw|null $draw */
                $draw = NationalLotteryDraw::query()
                    ->whereKey($locked->draw_id)
                    ->lockForUpdate()
                    ->first();

                $result = NationalLotteryResult::query()
                    ->where('result_version_id', $locked->id)
                    ->first();

                if (! $draw instanceof NationalLotteryDraw || ! $result instanceof NationalLotteryResult) {
                    return $this->outcome(
                        self::STATUS_REJECTED,
                        null,
                        null,
                        $this->sources->resolve($locked->source_state),
                        false,
                        ['RESOLUTION_TARGET_MISSING'],
                        'The conflicting version has no stored result row.',
                    );
                }

                $superseded = NationalLotteryResultVersion::query()
                    ->where('draw_id', $draw->id)
                    ->where('state', ResultVersionState::Verified->value)
                    ->orderByDesc('version_number')
                    ->first();

                $audit = is_array($locked->audit) ? $locked->audit : [];
                $audit['resolution'] = [
                    'actor_id' => $actorId,
                    'reason' => $reason,
                    'old_normalized_fingerprint' => $superseded instanceof NationalLotteryResultVersion
                        ? (string) $superseded->normalized_fingerprint
                        : null,
                    'new_normalized_fingerprint' => (string) $locked->normalized_fingerprint,
                    'resolved_at' => now()->toIso8601String(),
                ];

                $locked->state = ResultVersionState::Verified;
                $locked->resolution_reason = mb_substr($reason, 0, 500);
                $locked->resolved_by = $actorId;
                $locked->resolved_at = now();
                $locked->audit = $audit;
                $locked->save();

                $this->publishVersion($draw, $locked, $result, $superseded, $actorId, 'conflict_resolution');

                return $this->outcome(
                    self::STATUS_IMPORTED,
                    (string) $draw->draw_reference,
                    (int) $locked->version_number,
                    $this->sources->resolve($locked->source_state),
                    true,
                    [],
                    'Conflict resolved; the corrected version is published and the previous one retained.',
                );
            });
        } catch (Throwable $exception) {
            report($exception);

            return $this->outcome(
                self::STATUS_REJECTED,
                null,
                null,
                $this->sources->resolve($version->source_state),
                false,
                ['RESOLUTION_FAILED'],
                'Conflict resolution failed and was rolled back.',
            );
        }
    }

    /**
     * Make a version the live one, retiring whatever came before it.
     *
     * Runs inside the caller's transaction. No result value is UPDATEd here;
     * the only column that moves on an existing result row is the is_current
     * flag, which is what retires it.
     */
    private function publishVersion(
        NationalLotteryDraw $draw,
        NationalLotteryResultVersion $version,
        NationalLotteryResult $result,
        ?NationalLotteryResultVersion $superseded,
        ?int $actorId,
        string $trigger,
    ): void {
        $previousReference = (string) $draw->draw_reference;
        $previousVersion = $draw->current_result_version_id !== null
            ? (int) ($draw->currentVersion?->version_number ?? 0)
            : 0;

        // Retire the outgoing numbers. The row itself is retained.
        NationalLotteryResult::query()
            ->where('draw_id', $draw->id)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        $result->is_current = true;
        $result->save();

        if ($superseded instanceof NationalLotteryResultVersion && $superseded->id !== $version->id) {
            $supersededAudit = is_array($superseded->audit) ? $superseded->audit : [];
            $supersededAudit['superseded'] = [
                'by_version' => (int) $version->version_number,
                'actor_id' => $actorId,
                'trigger' => $trigger,
                'at' => now()->toIso8601String(),
            ];

            $superseded->state = ResultVersionState::Superseded;
            $superseded->audit = $supersededAudit;
            $superseded->save();

            $version->supersedes_version_id = (int) $superseded->id;
            $version->supersedes_version_number = (int) $superseded->version_number;
            $version->save();
        }

        // The DRAW stays Published across a correction. It is the VERSION
        // that rotates (Verified -> Superseded), and that rotation is already
        // recorded on the version rows. Pushing the draw through
        // Retracted/RePublished would take the public page dark between two
        // correct results, which is a worse answer than the previous verified
        // one it is replacing.
        $draw->forceFill([
            'status' => NationalLotteryDraw::STATUS_RESULT_RECORDED,
            'publication_status' => DrawPublicationStatus::Published,
            'published_at' => $draw->published_at ?? now(),
            'source_state' => (string) $version->source_state,
            'current_result_version_id' => (int) $version->id,
        ])->save();

        // Cache invalidation. The key carries the version, so the new version
        // already reads a different key; these calls retire the old entries
        // rather than leaving them to expire.
        $this->results->forgetDraw($previousReference, $previousVersion);
        $this->results->forgetDraw($previousReference, (int) $version->version_number);
        $this->history->forgetYearIndex();
    }

    /**
     * Find the draw for a date, or create it, under a row lock.
     *
     * The UNIQUE index on draw_date is what makes the concurrent case safe;
     * the catch below is how the loser recovers.
     */
    private function lockOrCreateDraw(string $isoDate): NationalLotteryDraw
    {
        $draw = NationalLotteryDraw::query()
            ->where('draw_date', $isoDate)
            ->lockForUpdate()
            ->first();

        if ($draw instanceof NationalLotteryDraw) {
            return $draw;
        }

        $fresh = new NationalLotteryDraw;
        $fresh->forceFill([
            'draw_reference' => NationalLotteryDraw::buildReference($isoDate),
            'draw_date' => $isoDate,
            'draw_year' => (int) substr($isoDate, 0, 4),
            'status' => NationalLotteryDraw::STATUS_AWAITING_RESULT,
            'publication_status' => DrawPublicationStatus::Pending,
            'source_state' => GloSourceState::Unavailable->value,
            'metadata' => [],
        ]);

        try {
            $fresh->save();

            return $fresh;
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            $winner = NationalLotteryDraw::query()
                ->where('draw_date', $isoDate)
                ->lockForUpdate()
                ->first();

            if ($winner instanceof NationalLotteryDraw) {
                return $winner;
            }

            throw $exception;
        }
    }

    private function mayPublish(NationalLotteryDraw $draw, GloSourceState $sourceState): bool
    {
        if (! $this->sources->isPublishableState($sourceState)) {
            return false;
        }

        if ((bool) $this->config->get('national_lottery.publication.block_publication_on_conflict', true)) {
            $hasOpenConflict = NationalLotteryResultVersion::query()
                ->where('draw_id', $draw->id)
                ->where('state', ResultVersionState::Conflict->value)
                ->exists();

            if ($hasOpenConflict) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate and canonicalise a payload.
     *
     * Values are trimmed and checked against the config patterns. NOTHING is
     * padded into shape here: a five-character "six digit" value is an error,
     * not something to fix with str_pad, because padding it would invent a
     * digit that the source never sent.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     errors: list<string>,
     *     iso_date: string,
     *     source_identifier: string|null,
     *     retrieved_at: CarbonImmutable|null,
     *     values: array{
     *         first_prize: string,
     *         three_up: string|null,
     *         two_up: string|null,
     *         two_down: string|null,
     *         three_front: list<string>,
     *         three_after: list<string>
     *     }
     * }
     */
    private function normalise(array $payload): array
    {
        $errors = [];

        $rawDate = isset($payload['draw_date']) && is_string($payload['draw_date'])
            ? $payload['draw_date']
            : '';

        $date = $this->dates->parsePublicDate($rawDate);

        if ($date === null) {
            $errors[] = 'INVALID_DRAW_DATE';
        }

        $values = [
            'first_prize' => '',
            'three_up' => null,
            'two_up' => null,
            'two_down' => null,
            'three_front' => [],
            'three_after' => [],
        ];

        foreach (['first_prize', 'three_up', 'two_up', 'two_down'] as $field) {
            $spec = $this->fieldSpec($field);
            $raw = $payload[$field] ?? null;

            if ($raw === null || $raw === '') {
                if ($spec['required']) {
                    $errors[] = 'MISSING_'.strtoupper($field);
                }

                continue;
            }

            if (! is_string($raw) && ! is_int($raw)) {
                $errors[] = 'INVALID_TYPE_'.strtoupper($field);

                continue;
            }

            // Cast to string WITHOUT reformatting. An int that arrived as
            // 4615 for a six-wide field fails the pattern below; it is not
            // rescued by padding, because the source's own leading zeros are
            // the only ones this system is entitled to store.
            $value = trim((string) $raw);

            if (preg_match($spec['pattern'], $value) !== 1) {
                $errors[] = 'INVALID_'.strtoupper($field);

                continue;
            }

            $values[$field] = $value;
        }

        foreach (['three_front', 'three_after'] as $field) {
            $spec = $this->fieldSpec($field);
            $raw = $payload[$field] ?? [];

            if ($raw === null || $raw === '') {
                $raw = [];
            }

            if (! is_array($raw)) {
                // A caller handing over '060-521-266-041' is handing over an
                // opaque blob, not a list. It is refused rather than split on
                // a separator this application would have to invent.
                $errors[] = 'INVALID_TYPE_'.strtoupper($field);

                continue;
            }

            $list = [];

            foreach ($raw as $item) {
                if (! is_string($item) && ! is_int($item)) {
                    $errors[] = 'INVALID_'.strtoupper($field);

                    continue 2;
                }

                $value = trim((string) $item);

                if (preg_match($spec['pattern'], $value) !== 1) {
                    $errors[] = 'INVALID_'.strtoupper($field);

                    continue 2;
                }

                // Source order is preserved. Nothing sorts this list.
                $list[] = $value;
            }

            $min = $spec['cardinality']['min'];
            $max = $spec['cardinality']['max'];

            if (count($list) < $min || count($list) > $max) {
                $errors[] = 'CARDINALITY_'.strtoupper($field);

                continue;
            }

            $values[$field] = $list;
        }

        $retrievedAt = null;

        if (isset($payload['retrieved_at']) && is_string($payload['retrieved_at']) && $payload['retrieved_at'] !== '') {
            try {
                $retrievedAt = CarbonImmutable::parse($payload['retrieved_at']);
            } catch (Throwable) {
                $errors[] = 'INVALID_RETRIEVED_AT';
            }
        }

        $sourceIdentifier = isset($payload['source_identifier']) && is_string($payload['source_identifier'])
            ? mb_substr(trim($payload['source_identifier']), 0, 191)
            : null;

        return [
            'errors' => array_values(array_unique($errors)),
            'iso_date' => $date !== null ? $this->dates->toIsoDate($date) : '',
            'source_identifier' => $sourceIdentifier === '' ? null : $sourceIdentifier,
            'retrieved_at' => $retrievedAt,
            'values' => $values,
        ];
    }

    /**
     * Hash of the payload AS DELIVERED.
     *
     * Keys are sorted recursively so that a re-delivery differing only in key
     * order is recognised as the same bytes, while a genuine change of value
     * produces a different hash.
     *
     * @param  array<string, mixed>  $payload
     */
    private function payloadFingerprint(array $payload): string
    {
        return hash('sha256', 'national-lottery.payload.v1|'.$this->canonicalJson($payload));
    }

    /**
     * Hash of the CANONICAL VALUES only.
     *
     * Two payloads that state the same numbers for the same date hash
     * identically here even if their envelopes differ. That is what makes
     * conflict detection a statement about the numbers rather than about the
     * formatting.
     *
     * @param  array<string, mixed>  $values
     */
    private function normalizedFingerprint(array $values, string $isoDate): string
    {
        return hash('sha256', 'national-lottery.normalized.v1|'.$isoDate.'|'.$this->canonicalJson($values));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function canonicalJson(array $data): string
    {
        $sorted = $this->recursiveKeySort($data);

        $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function recursiveKeySort(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $out[$key] = is_array($value) ? $this->recursiveKeySort($value) : $value;
        }

        // Only associative arrays are re-keyed. A LIST keeps its order,
        // because the order of 3Front values is data.
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    /**
     * @return array{pattern: string, required: bool, cardinality: array{min: int, max: int}}
     */
    private function fieldSpec(string $field): array
    {
        $spec = $this->config->get('national_lottery.fields.'.$field);
        $spec = is_array($spec) ? $spec : [];

        $pattern = isset($spec['pattern']) && is_string($spec['pattern']) ? $spec['pattern'] : '/^[0-9]+$/';
        $required = (bool) ($spec['required'] ?? false);

        $cardinality = isset($spec['cardinality']) && is_array($spec['cardinality']) ? $spec['cardinality'] : [];
        $min = isset($cardinality['min']) && is_int($cardinality['min']) ? $cardinality['min'] : 0;
        $max = isset($cardinality['max']) && is_int($cardinality['max']) ? $cardinality['max'] : 4;

        return [
            'pattern' => $pattern,
            'required' => $required,
            'cardinality' => ['min' => $min, 'max' => $max],
        ];
    }

    private function parserVersion(): string
    {
        $version = $this->config->get('national_lottery.sources.parser_version');

        return is_string($version) && $version !== '' ? mb_substr($version, 0, 16) : '1';
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $errors
     * @return array{
     *     status: string,
     *     draw_reference: string|null,
     *     version: int|null,
     *     source_state: string,
     *     published: bool,
     *     errors: list<string>,
     *     message: string
     * }
     */
    private function outcome(
        string $status,
        ?string $drawReference,
        ?int $version,
        GloSourceState $sourceState,
        bool $published,
        array $errors,
        string $message,
    ): array {
        return [
            'status' => $status,
            'draw_reference' => $drawReference,
            'version' => $version,
            'source_state' => $sourceState->value,
            'published' => $published,
            'errors' => $errors,
            'message' => $message,
        ];
    }
}
