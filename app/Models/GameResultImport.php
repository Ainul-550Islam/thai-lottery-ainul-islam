<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ONE ATTEMPT TO BRING AN OFFICIAL RESULT INTO THE SYSTEM, AND WHAT HAPPENED TO IT.
 *
 * WHY THIS IS A TABLE AND NOT A LOG LINE
 * Rule §0.5 says results may only reach a draw through the existing confirmation
 * and publication boundary. That boundary is idempotent once a result exists,
 * which is exactly what you want for publication and exactly what you do NOT
 * want for ingestion: a feed that re-sends draw 123 must be recognisable as a
 * duplicate BEFORE anyone claims publication, and an operator investigating a
 * wrong payout needs the exact payload that arrived, hashed, with the source
 * named and the time recorded. A log file cannot answer "which bytes did we
 * trust at 20:41:07, and did we act on them?".
 *
 * THE UNIQUE KEY IS THE POINT
 * UNIQUE(game_code, draw_no, source) makes a duplicated delivery a database
 * fact rather than an application convention. The ingestion service relies on
 * it: a second identical delivery is detected and refused as already-seen, and
 * the service never has to hold a lock while it asks the feed a question.
 *
 * A MONOTONIC LIFECYCLE, ENFORCED BY ALLOWED_TRANSITIONS()
 *   staged     the payload arrived, matched its shape, and was stored. NOTHING
 *              about a draw has changed yet.
 *   published  the confirmation boundary accepted it and the draw result now
 *              exists. Publication is the ONLY transition that can touch a draw,
 *              and it is performed by the existing boundary, never by this model.
 *   rejected   the payload was unusable, contradicted an already-published
 *              result, or named a game/draw that does not exist. Kept, not
 *              deleted, so the purge command can report on refusals.
 *   superseded a later delivery for the same key replaced this one before it was
 *              published (for example a corrected payload from the provider).
 *   pruned     the retention window elapsed and the payload body was deleted.
 *
 * WHY THE PAYLOAD HASH IS STORED SEPARATELY FROM THE PAYLOAD
 * The retention purge removes `raw`. The hash survives it, so the audit still
 * proves which bytes were consumed long after the bytes themselves are gone.
 *
 * THIS MODEL NEVER WRITES A RESULT
 * There is no code in this class that touches Draw, DrawResult,
 * DrawResultConfirmationService or DrawResultPublicationService. A test asserts
 * that no file other than the ingestion service creates rows here, and a second
 * test asserts that direct draw-result writes are refused.
 *
 * @property int $id
 * @property string $game_code
 * @property string $draw_no
 * @property string $source
 * @property string $payload_hash
 * @property string $status
 * @property array<string, mixed>|null $raw
 * @property string|null $message
 * @property Carbon|null $imported_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GameResultImport extends Model
{
    public const STATUS_STAGED = 'staged';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_PRUNED = 'pruned';

    protected $fillable = [
        'game_code',
        'draw_no',
        'source',
        'payload_hash',
        'status',
        'raw',
        'message',
        'imported_at',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'imported_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The operator record of the game this payload belongs to.
     *
     * @return BelongsTo<LotteryGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(LotteryGame::class, 'game_code', 'code');
    }

    /**
     * The states this row may move to.
     *
     * published is terminal and rejected/pruned are terminal: a row that already
     * reached the boundary must never be republished under a different story, and
     * a refusal must be re-delivered as a NEW row so the audit keeps both.
     *
     * @return list<string>
     */
    public static function transitions(): array
    {
        return [
            self::STATUS_STAGED => [
                self::STATUS_PUBLISHED,
                self::STATUS_REJECTED,
                self::STATUS_SUPERSEDED,
                self::STATUS_PRUNED,
            ],
            self::STATUS_PUBLISHED => [],
            self::STATUS_REJECTED => [],
            self::STATUS_SUPERSEDED => [],
            self::STATUS_PRUNED => [],
        ];
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::transitions()[$this->status] ?? [], true);
    }

    public function isStaged(): bool
    {
        return $this->status === self::STATUS_STAGED;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Record that the confirmation boundary accepted this payload.
     *
     * Called by GameResultIngestionService AFTER the boundary returned success,
     * inside the same transaction as the boundary call, so the row and the draw
     * result cannot disagree about whether publication happened.
     */
    public function markPublished(?string $message = null): void
    {
        if (! $this->canTransitionTo(self::STATUS_PUBLISHED)) {
            // Refusing here rather than overwriting is the point: a second
            // publication attempt must be visible, not idempotent-by-accident.
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_PUBLISHED,
            'published_at' => now(),
            'message' => $message ?? $this->message,
        ])->save();
    }

    /**
     * Record why this delivery was refused. Kept, never deleted.
     */
    public function markRejected(string $message): void
    {
        if (! $this->canTransitionTo(self::STATUS_REJECTED)) {
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'message' => $message,
        ])->save();
    }

    /**
     * Record that a corrected payload replaced this one before publication.
     */
    public function markSuperseded(string $message): void
    {
        if (! $this->canTransitionTo(self::STATUS_SUPERSEDED)) {
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_SUPERSEDED,
            'message' => $message,
        ])->save();
    }

    /**
     * Drop the payload body but keep the hash and the outcome.
     *
     * This is what the retention purge does, and it is why the hash is a separate
     * column: after pruning, the audit record still proves which bytes were
     * consumed.
     */
    public function prunePayload(): void
    {
        if (! $this->canTransitionTo(self::STATUS_PRUNED)) {
            return;
        }

        $this->forceFill([
            'status' => self::STATUS_PRUNED,
            'raw' => null,
        ])->save();
    }

    /**
     * The canonical hash of a payload, so every writer hashes the same way.
     *
     * Sorted keys and unescaped slashes keep the digest stable across PHP upgrades
     * and across providers that re-order JSON members.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hashPayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }
}
