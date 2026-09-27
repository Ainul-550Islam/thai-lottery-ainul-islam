<?php

declare(strict_types=1);

namespace App\Services\Lottery\Support;

use App\Enums\DrawPublicationStatus;
use App\Enums\GloSourceState;
use App\Enums\ResultVersionState;
use App\Lottery\Schema\LaneSchema;
use App\Lottery\Schema\LaneSchemaFactory;
use App\Models\Support\AbstractLotteryDraw;
use App\Models\Support\AbstractLotteryResult;
use App\Models\Support\AbstractLotteryResultVersion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The only writer in the Weekly Lottery lane.
 *
 * FIVE OUTCOMES, AND ONLY FIVE
 * ---------------------------------------------------------------------------
 *   IMPORTED   a new, valid version was stored (and published, if policy and
 *              source state allow it);
 *   DUPLICATE  this payload - or these exact numbers - were already stored for
 *              this draw. Nothing was written, and that is a SUCCESS, not an
 *              error: a retrying queue must not be told it failed;
 *   CONFLICT   a payload disagrees with an already-verified version about the
 *              numbers. The version is stored in state CONFLICT, publication
 *              is BLOCKED, and the live page keeps showing the previously
 *              verified result;
 *   REJECTED   the payload failed field validation, provider policy, or the
 *              integrity check. Nothing is written, because a malformed or
 *              unverifiable payload is not evidence of anything;
 *   (dry run)  validated and fingerprinted, nothing written at all.
 *
 * IDEMPOTENCY IS ENFORCED BY THE DATABASE, NOT BY A CHECK
 * ---------------------------------------------------------------------------
 * The fingerprint pre-check is an optimisation; the guarantee is UNIQUE
 * (draw_id, payload_fingerprint). Two workers importing the same payload at
 * the same moment both pass the pre-check, both attempt the insert, one wins,
 * and the loser catches the violation and reports DUPLICATE with the winner's
 * version. A check-then-insert without the constraint would let both through
 * in exactly that window - the window a retrying queue hits most often.
 *
 * The draw row follows the same discipline: weekly_lottery_draws has UNIQUE
 * (draw_date), so two workers importing the same date cannot create two draws.
 *
 * A MISSING RESULT IS IMPORTABLE, AND IS NEVER ZEROS
 * ---------------------------------------------------------------------------
 * A payload may legitimately carry no numbers. That produces a draw with
 * result_status = unavailable and three NULL columns. It is NOT turned into
 * 000000 / 000 / 00, and it is not silently skipped - the draw happened, and a
 * history that hides it misrepresents the record. All three numbers must be
 * present together or all three absent; a partial payload is rejected, because
 * storing it would render a table cell that is silently missing rather than
 * honestly empty.
 *
 * PUBLISHED RESULTS ARE NEVER EDITED
 * ---------------------------------------------------------------------------
 * There is no UPDATE of a result value in this file. A correction inserts a
 * new version and a new result row, flips the old row's is_current to false,
 * marks the old version SUPERSEDED, and records who did it, from what source,
 * why, and both fingerprints.
 *
 * A FIXTURE NEVER BECOMES OFFICIAL
 * ---------------------------------------------------------------------------
 * The caller names a PROVIDER; AbstractLotterySourceService decides what that
 * provider is entitled to be called. No method here accepts a source state.
 *
 * THE INTEGRITY GATE
 * ---------------------------------------------------------------------------
 * Before anything is written, WeeklyResultIntegrityService canonicalizes the
 * result and - when the Rust verifier is installed - has it independently
 * rebuild and hash the same bytes. A disagreement, an invalid signature, or a
 * required-but-missing verifier stops the import. This is defence in depth: it
 * catches a canonicalizer bug or a tampered value, and it makes no claim
 * beyond that.
 */
abstract class AbstractLotteryImportService
{
    use AppliesLaneOrdering;

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        protected readonly ConfigRepository $config,
        protected readonly AbstractLotteryCalendarService $dates,
        protected readonly AbstractLotterySourceService $sources,
        protected readonly AbstractLotteryResultService $results,
        protected readonly AbstractLotteryHistoryService $history,
    ) {}

    /** The config file this lane reads, e.g. 'weekly_lottery'. */
    abstract protected function configPrefix(): string;

    /**
     * This lane's schema: its fields, their widths, which of them are
     * searchable, and whether a date alone identifies a draw.
     *
     * Built from the lane's config file, so the engine never has to be told
     * the same fact twice.
     */
    protected function schema(): LaneSchema
    {
        return app(LaneSchemaFactory::class)->for($this->configPrefix());
    }

    /**
     * Ordered value column names for this lane.
     *
     * Comes from the lane schema, so the importer, the canonical payload and
     * the stored row cannot disagree about which columns exist. Kept as a
     * method rather than inlined because the canonical payload builder and the
     * normaliser both need the same order.
     *
     * @return list<string>
     */
    protected function valueColumns(): array
    {
        return $this->schema()->columns();
    }

    /**
     * @return class-string<AbstractLotteryDraw>
     */
    abstract protected function drawModel(): string;

    /**
     * @return Builder<covariant AbstractLotteryDraw>
     */
    abstract protected function drawQuery(): Builder;

    /**
     * @return class-string<AbstractLotteryResult>
     */
    abstract protected function resultModel(): string;

    /**
     * @return Builder<covariant AbstractLotteryResult>
     */
    abstract protected function resultQuery(): Builder;

    /**
     * @return class-string<AbstractLotteryResultVersion>
     */
    abstract protected function versionModel(): string;

    /**
     * @return Builder<covariant AbstractLotteryResultVersion>
     */
    abstract protected function versionQuery(): Builder;

    /**
     * Establish what is actually known about this payload's bytes.
     *
     * A lane with an independent verifier delegates here; a lane without one
     * hashes in PHP and reports INTEGRITY_HASH_ONLY. The distinction is the
     * whole point: the return value must never claim more than the lane can
     * demonstrate, so there is no default implementation to inherit by
     * accident.
     *
     * @param  array<string, string|null>  $canonicalFields
     * @param  array<string, string|null>  $canonicalFields
     * @param  string|null  $signature  lowercase hex Ed25519 signature, when supplied
     * @return array{acceptable: bool, status: string, fingerprint: string, canonical_version: string, native: bool}
     */
    abstract protected function verifyIntegrity(array $canonicalFields, ?string $signature): array;

    /**
     * Import one draw's result.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     status: string,
     *     draw_reference: string|null,
     *     version: int|null,
     *     source_state: string,
     *     result_status: string|null,
     *     published: bool,
     *     integrity: array<string, mixed>|null,
     *     errors: list<string>,
     *     message: string
     * }
     */
    public function import(
        array $payload,
        string $provider,
        ?int $actorId = null,
        bool $autoPublish = true,
        bool $dryRun = false,
    ): array {
        $provider = trim($provider);

        if (! in_array($provider, $this->sources->priority(), true)) {
            return $this->outcome(
                self::STATUS_REJECTED,
                null,
                null,
                GloSourceState::Unavailable,
                null,
                false,
                null,
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
                null,
                false,
                null,
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
                null,
                false,
                null,
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
                null,
                false,
                null,
                ['PROVIDER_NOT_CONFIGURED'],
                'The provider is not configured; no source state can be claimed for this payload.',
            );
        }

        $reference = ($this->drawModel())::buildReference($normalised['iso_date'], $normalised['local_time'] ?? null);

        // --- Integrity gate, before any write ---------------------------
        $canonicalFields = [
            'draw_reference' => $reference,
            'draw_date' => $normalised['iso_date'],
            ...($this->schema()->hasDrawTimes ? ['draw_time_local' => $normalised['local_time']] : []),
            ...$normalised['values'],
            'source_identifier' => $normalised['source_identifier'],
            'parser_version' => $this->parserVersion(),
        ];

        $integrity = $this->verifyIntegrity($canonicalFields, $normalised['signature']);

        if ($integrity['acceptable'] !== true) {
            return $this->outcome(
                self::STATUS_REJECTED,
                $reference,
                null,
                $sourceState,
                $normalised['result_status'],
                false,
                $integrity,
                ['INTEGRITY_'.$integrity['status']],
                'Payload refused by the integrity verifier.',
            );
        }

        $payloadFingerprint = $this->payloadFingerprint($payload);
        $normalizedFingerprint = $integrity['fingerprint'];

        if ($dryRun) {
            // Everything validated, nothing written. The fingerprints are real
            // so an operator can compare them against a stored version before
            // deciding to run for real.
            return $this->outcome(
                self::STATUS_IMPORTED,
                $reference,
                null,
                $sourceState,
                $normalised['result_status'],
                false,
                $integrity,
                [],
                'Dry run: payload is valid and was not written.',
            );
        }

        try {
            return DB::transaction(function () use (
                $normalised,
                $provider,
                $sourceState,
                $payloadFingerprint,
                $normalizedFingerprint,
                $integrity,
                $actorId,
                $autoPublish,
                $payload
            ): array {
                $draw = $this->lockOrCreateDraw($normalised['iso_date'], $normalised['local_time'] ?? null);

                // --- Idempotency pre-check (the index is the guarantee) ----
                $existing = $this->versionQuery()
                    ->where('draw_id', $draw->id)
                    ->where('payload_fingerprint', $payloadFingerprint)
                    ->first();

                if ($existing instanceof AbstractLotteryResultVersion) {
                    return $this->outcome(
                        self::STATUS_DUPLICATE,
                        (string) $draw->draw_reference,
                        (int) $existing->version_number,
                        $this->sources->resolve($existing->source_state),
                        (string) $draw->result_status,
                        $draw->isPubliclyLive(),
                        $integrity,
                        [],
                        'Payload already imported; no new version was created.',
                    );
                }

                $verified = $this->versionQuery()
                    ->where('draw_id', $draw->id)
                    ->where('state', ResultVersionState::Verified->value)
                    ->orderByDesc('version_number')
                    ->first();

                if ($verified instanceof AbstractLotteryResultVersion
                    && hash_equals((string) $verified->normalized_fingerprint, $normalizedFingerprint)) {
                    // The envelope differs (a re-fetch carries a new
                    // retrieved_at, so the payload hash moved) but the NUMBERS
                    // are identical to what is already verified. Storing a new
                    // version would supersede a correct record with an
                    // identical one and fill the audit trail with noise.
                    return $this->outcome(
                        self::STATUS_DUPLICATE,
                        (string) $draw->draw_reference,
                        (int) $verified->version_number,
                        $this->sources->resolve($verified->source_state),
                        (string) $draw->result_status,
                        $draw->isPubliclyLive(),
                        $integrity,
                        [],
                        'The verified version already states these values; no new version was created.',
                    );
                }

                $isConflict = $verified instanceof AbstractLotteryResultVersion;

                $versionNumber = ((int) $this->versionQuery()
                    ->where('draw_id', $draw->id)
                    ->max('version_number')) + 1;

                $state = $isConflict ? ResultVersionState::Conflict : ResultVersionState::Verified;

                $versionClass = $this->versionModel();
                $version = new $versionClass;
                $version->forceFill([
                    'draw_id' => $draw->id,
                    'version_number' => $versionNumber,
                    'state' => $state,
                    'provider' => $provider,
                    'source_state' => $sourceState->value,
                    'source_identifier' => $normalised['source_identifier'],
                    'source_endpoint_host' => $provider === 'official'
                        ? $this->sources->officialEndpointHost()
                        : null,
                    'payload_fingerprint' => $payloadFingerprint,
                    'normalized_fingerprint' => $normalizedFingerprint,
                    'parser_version' => $this->parserVersion(),
                    'integrity_status' => (string) $integrity['status'],
                    'canonical_version' => (string) $integrity['canonical_version'],
                    'integrity_native_verified' => (bool) $integrity['native'],
                    'retrieved_at' => $normalised['retrieved_at'],
                    'imported_at' => now(),
                    'imported_by' => $actorId,
                    'validation_status' => AbstractLotteryResultVersion::VALIDATION_PASSED,
                    'validation_errors' => null,
                    'conflict_reason' => $isConflict ? 'NORMALIZED_FINGERPRINT_MISMATCH' : null,
                    'audit' => [
                        'action' => $isConflict ? 'conflict_recorded' : 'version_imported',
                        'provider' => $provider,
                        'actor_id' => $actorId,
                        'result_status' => $normalised['result_status'],
                        // Key NAMES only. The values are the provider's data
                        // and have no business in an audit column that a
                        // support tool will render.
                        'payload_keys' => array_values(array_map('strval', array_keys($payload))),
                    ],
                ]);

                try {
                    $version->save();
                } catch (UniqueConstraintViolationException|QueryException $exception) {
                    // Lost the race. The winner's row is the truth.
                    $winner = $this->versionQuery()
                        ->where('draw_id', $draw->id)
                        ->where('payload_fingerprint', $payloadFingerprint)
                        ->first();

                    if (! $winner instanceof AbstractLotteryResultVersion) {
                        throw $exception;
                    }

                    return $this->outcome(
                        self::STATUS_DUPLICATE,
                        (string) $draw->draw_reference,
                        (int) $winner->version_number,
                        $this->sources->resolve($winner->source_state),
                        (string) $draw->result_status,
                        $draw->isPubliclyLive(),
                        $integrity,
                        [],
                        'Payload already imported by a concurrent writer; no new version was created.',
                    );
                }

                // The numbers themselves: one row per version, always inserted.
                $resultClass = $this->resultModel();
                $result = new $resultClass;
                $result->forceFill([
                    'draw_id' => $draw->id,
                    'result_version_id' => $version->id,
                    ...$normalised['values'],
                    'result_status' => $normalised['result_status'],
                    'is_current' => false,
                ]);
                $result->save();

                if ($isConflict) {
                    return $this->outcome(
                        self::STATUS_CONFLICT,
                        (string) $draw->draw_reference,
                        $versionNumber,
                        $sourceState,
                        (string) $draw->result_status,
                        // THIS version was not published. The draw may still
                        // be live with its previously verified result, which
                        // is a different fact and is not reported here.
                        false,
                        $integrity,
                        ['NORMALIZED_FINGERPRINT_MISMATCH'],
                        'Payload disagrees with the verified version; publication blocked pending resolution.',
                    );
                }

                $published = false;

                if ($autoPublish && $this->mayPublish($draw, $sourceState)) {
                    $this->publishVersion($draw, $version, $result, null, $actorId, 'import');
                    $published = true;
                }

                return $this->outcome(
                    self::STATUS_IMPORTED,
                    (string) $draw->draw_reference,
                    $versionNumber,
                    $sourceState,
                    $normalised['result_status'],
                    $published,
                    $integrity,
                    [],
                    $published ? 'Version imported and published.' : 'Version imported; publication withheld.',
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
                null,
                false,
                $integrity,
                ['IMPORT_FAILED'],
                'Import failed and was rolled back.',
            );
        }
    }

    /**
     * Resolve a recorded conflict by publishing the conflicting version as a
     * correction.
     *
     * An explicit, attributed act: the actor, the reason and both fingerprints
     * are written into the audit trail. The superseded version and its numbers
     * are retained.
     *
     * @return array{
     *     status: string,
     *     draw_reference: string|null,
     *     version: int|null,
     *     source_state: string,
     *     result_status: string|null,
     *     published: bool,
     *     integrity: array<string, mixed>|null,
     *     errors: list<string>,
     *     message: string
     * }
     */
    public function resolveConflict(
        AbstractLotteryResultVersion $version,
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
                null,
                false,
                null,
                ['RESOLUTION_REASON_REQUIRED'],
                'A resolution reason is required.',
            );
        }

        try {
            return DB::transaction(function () use ($version, $actorId, $reason): array {
                /** @var AbstractLotteryResultVersion|null $locked */
                $locked = $this->versionQuery()
                    ->whereKey($version->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $locked instanceof AbstractLotteryResultVersion
                    || $locked->state !== ResultVersionState::Conflict) {
                    return $this->outcome(
                        self::STATUS_REJECTED,
                        null,
                        null,
                        $this->sources->resolve($version->source_state),
                        null,
                        false,
                        null,
                        ['VERSION_NOT_IN_CONFLICT'],
                        'Only a version in CONFLICT can be resolved.',
                    );
                }

                /** @var AbstractLotteryDraw|null $draw */
                $draw = $this->drawQuery()
                    ->whereKey($locked->draw_id)
                    ->lockForUpdate()
                    ->first();

                $result = $this->resultQuery()
                    ->where('result_version_id', $locked->id)
                    ->first();

                if (! $draw instanceof AbstractLotteryDraw || ! $result instanceof AbstractLotteryResult) {
                    return $this->outcome(
                        self::STATUS_REJECTED,
                        null,
                        null,
                        $this->sources->resolve($locked->source_state),
                        null,
                        false,
                        null,
                        ['RESOLUTION_TARGET_MISSING'],
                        'The conflicting version has no stored result row.',
                    );
                }

                $superseded = $this->versionQuery()
                    ->where('draw_id', $draw->id)
                    ->where('state', ResultVersionState::Verified->value)
                    ->orderByDesc('version_number')
                    ->first();

                $audit = is_array($locked->audit) ? $locked->audit : [];
                $audit['resolution'] = [
                    'actor_id' => $actorId,
                    'reason' => $reason,
                    'old_normalized_fingerprint' => $superseded instanceof AbstractLotteryResultVersion
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
                    (string) $result->result_status,
                    true,
                    $locked->integrityProjection(),
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
                null,
                false,
                null,
                ['RESOLUTION_FAILED'],
                'Conflict resolution failed and was rolled back.',
            );
        }
    }

    /**
     * Make a version the live one, retiring whatever came before it.
     *
     * Runs inside the caller's transaction. No result value is UPDATEd; the
     * only column that moves on an existing result row is is_current, which is
     * what retires it.
     */
    protected function publishVersion(
        AbstractLotteryDraw $draw,
        AbstractLotteryResultVersion $version,
        AbstractLotteryResult $result,
        ?AbstractLotteryResultVersion $superseded,
        ?int $actorId,
        string $trigger,
    ): void {
        $previousReference = (string) $draw->draw_reference;
        $previousVersion = $draw->current_result_version_id !== null
            ? (int) ($draw->currentVersion?->version_number ?? 0)
            : 0;

        // Retire the outgoing numbers. The row itself is retained.
        $this->resultQuery()
            ->where('draw_id', $draw->id)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        $result->is_current = true;
        $result->save();

        if ($superseded instanceof AbstractLotteryResultVersion && $superseded->id !== $version->id) {
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

        // The DRAW stays Published across a correction. It is the VERSION that
        // rotates (Verified -> Superseded), and that rotation is already
        // recorded on the version rows. Pushing the draw through
        // Retracted/RePublished would take the public page dark between two
        // correct results.
        $draw->forceFill([
            'result_status' => (string) $result->result_status,
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
     * Find the draw this payload belongs to, or create it, under a row lock.
     *
     * DRAW IDENTITY IS LANE-DEFINED. In Weekly and Mega a date identifies a
     * draw, and the UNIQUE index on draw_date is what makes the concurrent
     * case safe. PCSO publishes SEVERAL draws on one date - 14:00, 17:00,
     * 21:00 - so a date alone identifies nothing there, and a lookup keyed on
     * date would have found the 14:00 row and overwritten it with the 17:00
     * result.
     *
     * The schema says which it is, so neither lane needs its own copy of this
     * method, and the composite UNIQUE index in the lane's migration is what
     * the catch below recovers from either way.
     *
     * @param  string|null  $localTime  'HH:MM' when the lane has draw times
     */
    protected function lockOrCreateDraw(string $isoDate, ?string $localTime = null): AbstractLotteryDraw
    {
        $hasTimes = $this->schema()->hasDrawTimes;

        $locate = function () use ($isoDate, $localTime, $hasTimes): ?AbstractLotteryDraw {
            $query = $this->drawQuery()->where('draw_date', $isoDate);

            if ($hasTimes) {
                $query->where('draw_time_local', $localTime);
            }

            $found = $query->lockForUpdate()->first();

            return $found instanceof AbstractLotteryDraw ? $found : null;
        };

        $existing = $locate();

        if ($existing instanceof AbstractLotteryDraw) {
            return $existing;
        }

        $drawClass = $this->drawModel();
        $fresh = new $drawClass;

        $attributes = [
            'draw_reference' => ($this->drawModel())::buildReference($isoDate, $localTime),
            'draw_date' => $isoDate,
            'draw_year' => (int) substr($isoDate, 0, 4),
            'draw_timezone' => $this->dates->timezone(),
            'result_status' => AbstractLotteryDraw::RESULT_UNAVAILABLE,
            'publication_status' => DrawPublicationStatus::Pending,
            'source_state' => GloSourceState::Unavailable->value,
            'metadata' => [],
        ];

        if ($hasTimes) {
            $attributes['draw_time_local'] = $localTime;
        }

        $fresh->forceFill($attributes);

        try {
            $fresh->save();

            return $fresh;
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            $winner = $locate();

            if ($winner instanceof AbstractLotteryDraw) {
                return $winner;
            }

            throw $exception;
        }
    }

    protected function mayPublish(AbstractLotteryDraw $draw, GloSourceState $sourceState): bool
    {
        if (! $this->sources->isPublishableState($sourceState)) {
            return false;
        }

        if ((bool) $this->config->get($this->configPrefix().'.publication.block_publication_on_conflict', true)) {
            $hasOpenConflict = $this->versionQuery()
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
     * padded into shape: a five-character "six digit" value is an error, not
     * something to fix with str_pad, because padding invents a digit the
     * source never sent.
     *
     * COMPLETENESS FOLLOWS THE LANE SCHEMA. Where a lane publishes all of its
     * numbers or none, a partly filled payload is rejected rather than stored
     * as a half-empty row. Where a lane legitimately runs some categories and
     * not others, the absent ones are stored as NULL and rendered as "Off" -
     * never as zeros.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     errors: list<string>,
     *     iso_date: string,
     *     local_time: string|null,
     *     result_status: string,
     *     source_identifier: string|null,
     *     signature: string|null,
     *     retrieved_at: CarbonImmutable|null,
     *     values: array<string, string|null>
     * }
     */
    protected function normalise(array $payload): array
    {
        $errors = [];

        $rawDate = isset($payload['draw_date']) && is_string($payload['draw_date'])
            ? $payload['draw_date']
            : '';

        $date = $this->dates->parsePublicDate($rawDate);

        if ($date === null) {
            $errors[] = 'INVALID_DRAW_DATE';
        }

        // DRAW TIME. Only lanes whose schema says a date is not enough carry
        // one. It is normalised to a 24-hour 'HH:MM' local string by the
        // lane's date service, which is also what stops a server in another
        // zone moving a 21:00 draw onto the next day.
        $localTime = null;

        if ($this->schema()->hasDrawTimes) {
            $rawTime = $payload['draw_time_local'] ?? ($payload['draw_time'] ?? null);

            $localTime = is_string($rawTime) || is_int($rawTime)
                ? $this->dates->parseLocalDrawTime((string) $rawTime)
                : null;

            if ($localTime === null) {
                $errors[] = 'INVALID_DRAW_TIME';
            }
        }

        $columns = $this->valueColumns();
        $values = array_fill_keys($columns, null);
        $presentCount = 0;

        foreach ($columns as $field) {
            $raw = $payload[$field] ?? null;

            // An explicit null, an absent key, or an empty string all mean
            // "not published". None of them mean zero.
            if ($raw === null || $raw === '') {
                continue;
            }

            if (! is_string($raw) && ! is_int($raw)) {
                $errors[] = 'INVALID_TYPE_'.strtoupper($field);

                continue;
            }

            // Cast to string WITHOUT reformatting. An int that arrived as 49
            // for a three-wide field fails the pattern below; it is not
            // rescued by padding, because the source's own leading zeros are
            // the only ones this system is entitled to store.
            $value = trim((string) $raw);

            if (preg_match($this->fieldPattern($field), $value) !== 1) {
                $errors[] = 'INVALID_'.strtoupper($field);

                continue;
            }

            $values[$field] = $value;
            $presentCount++;
        }

        // COMPLETENESS IS A LANE POLICY, NOT A CONSTANT.
        //
        // This read `$presentCount !== 3` until PROMPT 9 - Weekly's field
        // count, written in as though every lane had three fields. PCSO has
        // four, so a COMPLETE payload was rejected as partial while a payload
        // missing one field was accepted as complete: exactly backwards.
        //
        // And the policy itself differs. In Weekly and Mega a draw publishes
        // all of its numbers or none, so a half-filled payload is a broken
        // delivery. PCSO runs its categories independently and shows "Off"
        // for one that did not run, so a partly filled draw is a REAL state
        // and refusing it would discard a legitimate result.
        $expectedCount = $this->schema()->fieldCount();

        if ($this->schema()->requiresCompleteResultSet
            && $presentCount !== 0
            && $presentCount !== $expectedCount) {
            $errors[] = 'PARTIAL_RESULT_SET';
        }

        // Any published value at all makes the draw a published draw. Zero
        // values means the draw published nothing, which is 'unavailable' -
        // never a row of zeros.
        $resultStatus = $presentCount > 0
            ? AbstractLotteryDraw::RESULT_PUBLISHED
            : AbstractLotteryDraw::RESULT_UNAVAILABLE;

        // An operator may state which draw they believe they are importing.
        // It must AGREE with the date rather than override it: letting a
        // stated reference redirect a payload to a different draw is exactly
        // how the wrong numbers get published under the right heading.
        if (isset($payload['expected_draw_reference'])
            && is_string($payload['expected_draw_reference'])
            && $payload['expected_draw_reference'] !== ''
            && $date !== null
            && $payload['expected_draw_reference'] !== ($this->drawModel())::buildReference($this->dates->toIsoDate($date), $payload['local_time'] ?? null)) {
            $errors[] = 'DRAW_REFERENCE_MISMATCH';
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

        $signature = isset($payload['signature_hex']) && is_string($payload['signature_hex'])
            && preg_match('/^[0-9a-fA-F]{128}$/', $payload['signature_hex']) === 1
                ? strtolower($payload['signature_hex'])
                : null;

        return [
            'errors' => array_values(array_unique($errors)),
            'iso_date' => $date !== null ? $this->dates->toIsoDate($date) : '',
            'local_time' => $localTime,
            'result_status' => $resultStatus,
            'source_identifier' => $sourceIdentifier === '' ? null : $sourceIdentifier,
            'signature' => $signature,
            'retrieved_at' => $retrievedAt,
            'values' => $values,
        ];
    }

    /**
     * Hash of the payload AS DELIVERED.
     *
     * Keys are sorted recursively so a re-delivery differing only in key order
     * is recognised as the same bytes, while a genuine change of value
     * produces a different hash.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function payloadFingerprint(array $payload): string
    {
        // The lane is part of the hashed domain. Without it, two lanes that
        // happened to publish the same numbers on the same date would produce
        // the same fingerprint, and a duplicate check keyed on it would
        // silently reject a legitimate import in the other lane.
        // Lane AND schema version. Two lanes that happen to publish the same
        // numbers on the same date must not collide, and neither must the same
        // lane before and after a canonical-layout change.
        $domain = str_replace('_', '-', $this->configPrefix())
            .'.'.strtolower($this->schema()->canonicalVersion)
            .'.payload.v1|';

        return hash('sha256', $domain.$this->canonicalJson($payload));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function canonicalJson(array $data): string
    {
        $sorted = $this->recursiveKeySort($data);

        $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function recursiveKeySort(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $out[$key] = is_array($value) ? $this->recursiveKeySort($value) : $value;
        }

        // Only associative arrays are re-keyed; a LIST keeps its order.
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    protected function fieldPattern(string $field): string
    {
        // The schema is the single description of a field, so the width the
        // importer enforces and the width the search engine accepts cannot
        // drift apart.
        return $this->schema()->field($field)?->pattern ?? '/^\z./';
    }

    protected function parserVersion(): string
    {
        $version = $this->config->get($this->configPrefix().'.sources.parser_version');

        return is_string($version) && $version !== '' ? mb_substr($version, 0, 16) : '1';
    }

    /**
     * @param  array<string, mixed>|null  $integrity
     * @param  list<string>  $errors
     * @return array{
     *     status: string,
     *     draw_reference: string|null,
     *     version: int|null,
     *     source_state: string,
     *     result_status: string|null,
     *     published: bool,
     *     integrity: array<string, mixed>|null,
     *     errors: list<string>,
     *     message: string
     * }
     */
    protected function outcome(
        string $status,
        ?string $drawReference,
        ?int $version,
        GloSourceState $sourceState,
        ?string $resultStatus,
        bool $published,
        ?array $integrity,
        array $errors,
        string $message,
    ): array {
        return [
            'status' => $status,
            'draw_reference' => $drawReference,
            'version' => $version,
            'source_state' => $sourceState->value,
            'result_status' => $resultStatus,
            'published' => $published,
            'integrity' => $integrity,
            'errors' => $errors,
            'message' => $message,
        ];
    }
}
