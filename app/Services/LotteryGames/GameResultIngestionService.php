<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\Contracts\LotteryGames\GameResultProvider;
use App\DTOs\LotteryGames\DigitDrawResult;
use App\DTOs\LotteryGames\GameDefinition;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use App\Models\GameResultImport;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService as BatchOneIngestion;
use App\Services\LotteryGames\Providers\GameResultProviderChain;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE ONLY WAY A DIGIT-GAME RESULT ENTERS THE SYSTEM, AND THE ONLY PLACE THAT ASKS
 * THE EXISTING WRITE BOUNDARY TO PUBLISH IT.
 *
 * THE BOUNDARY IS NOT REIMPLEMENTED — Rule §0.5
 * Batch 1 owns the draw-result write boundary: DrawResultIngestionService stages and
 * canonicalises a claimed result, DrawResultConfirmationService compares the claim
 * against the staged payload under four eyes and then DrawResultPublicationService
 * publishes. That boundary exists because a past regression (commit f5cec15) removed
 * the four-eyes check and the source-trust check, and draw results were published by a
 * single actor from single-source data. This class therefore does NOT write a draw
 * result, does NOT stamp a confirmation and does NOT touch winning_numbers. It stages
 * the game's own payload in its own table, then calls the SAME two Batch-1 methods
 * every GLO result goes through, inside one transaction:
 *
 *     DrawResultIngestionService::ingest()      (stages / canonicalises, writes ingested_by)
 *     DrawResultConfirmationService::confirm()  (four eyes, source trust, publication)
 *
 * TWO SEPARATE TABLES, AND WHY THAT IS NOT DUPLICATION
 * game_result_imports records WHAT ARRIVED from a digit-game provider: source, payload
 * hash, draw number, dedupe key. It is the feed's own ledger and it is keyed by
 * (game_code, draw_no, source) so a re-delivery is a database-level no-op. The draw's
 * canonical result stays where Batch 1 puts it. Neither table can answer the other's
 * question, and neither is a copy of the other.
 *
 * PUBLICATION IS OPTIONAL, AND THAT IS THE DESIGN
 * A game's digits are not a GLO six-digit first prize. config('lottery_games.games.*
 * .publication_bridge') states HOW this game's digits project onto the boundary's claim
 * shape, and on this deployment every bridge value is REQUIRED_INPUT — no operator has
 * told us the mapping. So ingestion STAGES the result and refuses to publish it, with
 * game_not_configured naming the missing bridge paths. That is exactly the behaviour
 * the configuration documents: a staged row, no claim of publication, and a visible
 * reason. Inventing a mapping would publish numbers nobody authorised.
 *
 * FOUR EYES NEEDS TWO SEATS, AND THIS LANE NAMES ONLY ONE
 * The boundary refuses a confirmation by the operator who ingested the result. So the
 * maker is read from config('lottery_games.ingestion.maker_user_id') — NULL on this
 * deployment, which means "stage and stop" — and the checker is supplied by the caller
 * (the Filament action or the API admin route), never by configuration. If either is
 * absent the publication is refused before anything is written.
 */
final class GameResultIngestionService
{
    public function __construct(
        private readonly GameRegistry $registry,
        private readonly DigitNumberValidator $validator,
        private readonly GameResultProviderChain $providers,
        private readonly BatchOneIngestion $boundaryIngestion,
        private readonly DrawResultConfirmationService $confirmation,
        private readonly ConfigRepository $config,
        private readonly GameAuditRecorder $audit,
    ) {}

    /**
     * Fetch and stage the most recent result of a game.
     *
     * @return array{game_code: string, staged: bool, import_id: int|null, draw_no: string|null, digits: string|null, source: string|null, status: string, publication: array<string, mixed>|null, reason: string|null}
     */
    public function ingestLatest(string $gameCode): array
    {
        $game = $this->registry->definition($gameCode);
        $result = $this->providers->fetchLatest($game);

        if (! $result instanceof DigitDrawResult) {
            // "The feed has nothing for this game yet" is not an error and must never
            // become a fabricated row.
            return $this->outcome($gameCode, null, null, null, null, 'unavailable', null, 'provider_has_no_result');
        }

        return $this->ingestResult($game, $result);
    }

    /**
     * Fetch and stage one specific draw of a game.
     *
     * @return array<string, mixed>
     */
    public function ingestDraw(string $gameCode, string $drawNo): array
    {
        $game = $this->registry->definition($gameCode);
        $result = $this->providers->fetchByDrawNo($game, $drawNo);

        if (! $result instanceof DigitDrawResult) {
            return $this->outcome($gameCode, null, $drawNo, null, null, 'unavailable', null, 'provider_has_no_result');
        }

        return $this->ingestResult($game, $result);
    }

    /**
     * Stage a result that a caller already holds (a feed, an admin correction, a
     * fixture). Idempotent by (game_code, draw_no, source).
     *
     * @return array<string, mixed>
     *
     * @throws LotteryGameException game_result_payload_invalid|game_not_configured
     */
    public function stage(GameDefinition $game, DigitDrawResult $result): array
    {
        // The drawn digits must be a real draw of this game: right width, all digits.
        // A provider that changes its schema returns a shorter string, and that must
        // be a failure rather than a silently-accepted wrong result.
        $digits = $this->validator->validateDrawnDigits($game, $result->digits);

        $payloadHash = hash('sha256', implode('|', [$game->code, $result->drawNo, $digits, $result->source]));

        $existing = GameResultImport::query()
            ->where('game_code', $game->code)
            ->where('draw_no', $result->drawNo)
            ->where('source', $result->source)
            ->first();

        if ($existing instanceof GameResultImport) {
            if (hash_equals((string) $existing->payload_hash, $payloadHash)) {
                // Exact re-delivery: no second row, no second publication.
                return $this->outcome(
                    $game->code,
                    (int) $existing->getKey(),
                    $result->drawNo,
                    $digits,
                    $result->source,
                    $existing->status,
                    null,
                    'duplicate_delivery',
                );
            }

            if ($existing->isPublished()) {
                // A DIFFERENT payload for a result that is already published is a
                // correction, and a correction is an operator decision — never a
                // quiet overwrite by whoever re-ran the import.
                throw LotteryGameException::invalidResultPayload(
                    'This source already published a different result for that draw; a correction must be reviewed.',
                    [
                        'game_code' => $game->code,
                        'draw_no' => $result->drawNo,
                        'source' => $result->source,
                        'import_id' => (int) $existing->getKey(),
                    ],
                );
            }

            // A corrected payload BEFORE publication re-uses the existing row.
            //
            // WHY NOT supersede-then-insert: UNIQUE(game_code, draw_no, source) is the
            // whole idempotency mechanism, and it means there is exactly ONE import slot
            // per provider per draw. Marking the old row superseded and inserting a
            // second one violates that key, so the "corrected payload" path used to
            // surface as a database constraint error - a 500 on a perfectly ordinary
            // event (a provider re-publishing before the operator confirmed). The slot is
            // reused instead: the payload is replaced, the row goes back to staged, and
            // the hash that was replaced is kept in the message so the trail survives the
            // overwrite. Nothing published is ever rewritten: that branch returned above.
            $previousHash = (string) $existing->payload_hash;

            $existing->forceFill([
                'payload_hash' => $payloadHash,
                'status' => GameResultImport::STATUS_STAGED,
                'raw' => $result->toArray(),
                'imported_at' => now(),
                'published_at' => null,
                'message' => 'corrected before publication; replaced payload '.substr($previousHash, 0, 16),
            ])->save();

            return $this->outcome(
                $game->code,
                (int) $existing->getKey(),
                $result->drawNo,
                $digits,
                $result->source,
                GameResultImport::STATUS_STAGED,
                null,
                'corrected_before_publication',
            );
        }

        $import = GameResultImport::query()->create([
            'game_code' => $game->code,
            'draw_no' => $result->drawNo,
            'source' => $result->source,
            'payload_hash' => $payloadHash,
            'status' => GameResultImport::STATUS_STAGED,
            'raw' => $result->toArray(),
            'imported_at' => now(),
        ]);

        $this->audit->record(
            GameAuditRecorder::RESULT_STAGED,
            $game->code,
            null,
            'import',
            (int) $import->getKey(),
            [
                'draw_no' => $result->drawNo,
                'source' => $result->source,
                'payload_hash' => $payloadHash,
            ],
        );

        return $this->outcome(
            $game->code,
            (int) $import->getKey(),
            $result->drawNo,
            $digits,
            $result->source,
            GameResultImport::STATUS_STAGED,
            null,
            null,
        );
    }

    /**
     * Ask the existing boundary to publish a staged result.
     *
     * @param  int  $checkerUserId  the second operator seat; MUST differ from the maker
     * @return array{import_id: int, game_code: string, draw_id: int, published: bool, boundary: array<string, mixed>, reason: string|null}
     *
     * @throws LotteryGameException
     */
    public function publish(int $importId, int $checkerUserId, ?string $notes = null): array
    {
        $import = GameResultImport::query()->find($importId);

        if (! $import instanceof GameResultImport) {
            throw LotteryGameException::invalidResultPayload('Unknown result import.', ['import_id' => $importId]);
        }

        if (! $import->isStaged()) {
            // Publication is a one-way transition. A second attempt is refused here
            // rather than surfacing as a confusing boundary error.
            throw LotteryGameException::invalidResultPayload(
                'Only a staged result may be published.',
                ['import_id' => $importId, 'status' => $import->status],
            );
        }

        $game = $this->registry->definition($import->game_code);
        $claims = $this->claimsFor($game, $import);

        $draw = $this->drawFor($game, $import->draw_no);

        if (! $draw instanceof Draw) {
            // A result for a draw that does not exist is not publishable: publication
            // writes against a draw id, and inventing the draw here would create a
            // settlement target for bets that were never placed.
            $import->markRejected('no draw of game '.$game->code.' carries the draw number '.$import->draw_no);

            throw LotteryGameException::invalidResultPayload(
                'No draw of this game carries that draw number.',
                ['game_code' => $game->code, 'draw_no' => $import->draw_no],
            );
        }

        $makerId = $this->makerUserId();

        if ($makerId === null) {
            // The boundary refuses an unattributable ingest, and it refuses a
            // confirmation by the ingester. With no maker appointed on this
            // deployment the publication cannot be satisfied at all, so it is
            // refused HERE, in this lane's vocabulary, naming the config key an
            // operator must appoint rather than surfacing a boundary error that
            // looks like a bug.
            throw LotteryGameException::notConfigured(
                $game->code,
                ['ingestion.maker_user_id'],
                'No operator seat is appointed to ingest results, so the two-person rule cannot be satisfied.',
            );
        }

        $outcome = DB::transaction(function () use ($import, $game, $claims, $draw, $checkerUserId, $makerId, $notes): array {
            // 1. The boundary stages and canonicalises the claim, recording who
            //    ingested it. This is the Batch-1 entry point, unchanged.
            $ingested = $this->boundaryIngestion->ingest(
                drawId: (int) $draw->getKey(),
                payload: $claims,
                provenance: 'lottery_games',
                ingestedBy: $makerId,
                provenanceReference: (string) $import->source.'|'.(string) $import->payload_hash,
                // The boundary's trust registry names LANES, not feeds. The lane is
                // 'lottery_games'; WHICH provider produced the payload is recorded in the
                // provenance reference (and on the import row) so nothing is lost.
                source: 'lottery_games',
                actorUserId: $makerId,
            );

            // 2. The boundary publishes under four eyes and its own source-trust check.
            $confirmed = $this->confirmation->confirm(
                $draw->getKey(),
                $checkerUserId,
                $claims,
                $notes,
            );

            $import->markPublished('published through the draw result confirmation boundary');

            Log::info('lottery_games.result_published', [
                'import_id' => (int) $import->getKey(),
                'game_code' => $game->code,
                'draw_id' => (int) $draw->getKey(),
                'maker_user_id' => $makerId,
                'checker_user_id' => $checkerUserId,
            ]);

            return [
                'import_id' => (int) $import->getKey(),
                'game_code' => $game->code,
                'draw_id' => (int) $draw->getKey(),
                'published' => true,
                'boundary' => [
                    'ingest' => $ingested,
                    'confirm' => $confirmed,
                ],
                'reason' => null,
            ];
        });

        // Recorded AFTER the publication transaction has committed: an audit row for a
        // publication that rolled back would assert something that never happened, and a
        // failed audit insert must not be able to poison the transaction that did the
        // publishing.
        $this->audit->record(
            GameAuditRecorder::RESULT_PUBLISHED,
            $game->code,
            $checkerUserId,
            'import',
            (int) $import->getKey(),
            [
                'draw_id' => (int) $draw->getKey(),
                'maker_user_id' => $makerId,
                'checker_user_id' => $checkerUserId,
                'source' => (string) $import->source,
                'payload_hash' => (string) $import->payload_hash,
            ],
        );

        return $outcome;
    }

    /**
     * Refuse a staged result, keeping the row and the reason.
     *
     * @return array{import_id: int, status: string, reason: string}
     */
    public function reject(int $importId, string $reason): array
    {
        $import = GameResultImport::query()->find($importId);

        if (! $import instanceof GameResultImport) {
            throw LotteryGameException::invalidResultPayload('Unknown result import.', ['import_id' => $importId]);
        }

        $import->markRejected($reason);

        return ['import_id' => $importId, 'status' => (string) $import->status, 'reason' => $reason];
    }

    /**
     * Drop payload bodies older than the retention window, keeping the hashes.
     *
     * Published rows are never pruned: they are the evidence that a result was
     * published from these bytes. Only staged/rejected/superseded rows lose their
     * body, and only after the configured window.
     *
     * @return array{pruned: int, retention_days: int}
     */
    public function prune(?int $days = null): array
    {
        $retention = $days ?? (int) $this->config->get('lottery_games.ingestion.staging_retention_days', 90);
        $retention = max(1, $retention);

        $cutoff = now()->subDays($retention);

        $rows = GameResultImport::query()
            ->whereIn('status', [
                GameResultImport::STATUS_STAGED,
                GameResultImport::STATUS_REJECTED,
                GameResultImport::STATUS_SUPERSEDED,
            ])
            ->whereNotNull('raw')
            ->where('created_at', '<', $cutoff)
            ->get();

        $pruned = 0;

        foreach ($rows as $row) {
            $row->prunePayload();
            $pruned++;
        }

        return ['pruned' => $pruned, 'retention_days' => $retention];
    }

    /**
     * Everything an operator needs to see about a game's imports, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $gameCode, int $limit = 25): array
    {
        /** @var list<GameResultImport> $rows */
        $rows = GameResultImport::query()
            ->where('game_code', $gameCode)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get()
            ->all();

        return array_map(static function (GameResultImport $row): array {
            // The digits travel with the row because every caller that lists imports
            // needs them: the public result feed serves them, the settlement
            // reconciliation matches against them, and the admin screen shows them. The
            // staged `raw` body itself stays out of this payload.
            $raw = (array) ($row->raw ?? []);
            $digits = isset($raw['digits']) ? trim((string) $raw['digits']) : '';

            return [
                'import_id' => (int) $row->getKey(),
                'game_code' => (string) $row->game_code,
                'draw_no' => (string) $row->draw_no,
                'digits' => $digits !== '' && ctype_digit($digits) ? $digits : null,
                'source' => (string) $row->source,
                'status' => (string) $row->status,
                'payload_hash' => (string) $row->payload_hash,
                'imported_at' => $row->imported_at?->toIso8601String(),
                'published_at' => $row->published_at?->toIso8601String(),
                'message' => $row->message,
            ];
        }, $rows);
    }

    /**
     * Stage a provider result and, when the bridge and both operator seats exist, ask
     * the boundary to publish it.
     *
     * @return array<string, mixed>
     */
    private function ingestResult(GameDefinition $game, DigitDrawResult $result): array
    {
        $staged = $this->stage($game, $result);

        // A duplicate or a refusal stops here: there is nothing new to publish.
        if (($staged['reason'] ?? null) === 'duplicate_delivery' || $staged['import_id'] === null) {
            return $staged;
        }

        $bridgeProblem = $this->bridgeProblem($game);

        if ($bridgeProblem !== null) {
            // Stage and stop. This is the documented behaviour of an unconfigured
            // publication_bridge, and the reason is reported, not swallowed.
            $staged['reason'] = $bridgeProblem;

            return $staged;
        }

        return $staged;
    }

    /**
     * Why this game's results cannot be published yet, or null when they can.
     *
     * @return string|null machine reason
     */
    public function bridgeProblem(GameDefinition $game): ?string
    {
        $missing = [];

        foreach ($game->publicationBridge as $key => $value) {
            if (! is_string($value) || trim($value) === '' || trim($value) === GameDefinition::REQUIRED_INPUT) {
                $missing[] = 'publication_bridge.'.$key;
            }
        }

        if ($missing !== []) {
            return 'publication_bridge_unconfigured:'.implode(',', $missing);
        }

        if ($this->makerUserId() === null) {
            // Without an attributable maker the boundary's four-eyes check cannot be
            // satisfied, so publishing now would only produce a refusal.
            return 'maker_user_id_unconfigured';
        }

        return null;
    }

    /**
     * Project a game's digits onto the boundary's claim shape.
     *
     * The boundary canonicalises `first_prize` as exactly six digits and reads
     * `bottom_two` as its own two-digit draw. A game's digits are neither, so the
     * operator's publication_bridge states the projection. Only instructions that do
     * not invent information are supported:
     *
     *   digits                 the game's digits, used verbatim (requires width 6)
     *   digits_left_padded     zero-padded on the left to six digits
     *   digits_right_padded    zero-padded on the right to six digits
     *   first:N / last:N       a slice; accepted only when it is six digits long
     *
     * Anything else — including an instruction whose result is not six digits — is a
     * refusal, because the alternative is padding or truncating without being told to.
     *
     * @return array{first_prize: string, bottom_two: string|null}
     *
     * @throws LotteryGameException game_not_configured|game_result_payload_invalid
     */
    public function claimsFor(GameDefinition $game, GameResultImport $import): array
    {
        $raw = (array) ($import->raw ?? []);
        $digits = isset($raw['digits']) ? trim((string) $raw['digits']) : '';

        if ($digits === '' || ! ctype_digit($digits)) {
            throw LotteryGameException::invalidResultPayload(
                'The staged payload carries no usable digits.',
                ['game_code' => $game->code, 'import_id' => (int) $import->getKey()],
            );
        }

        $bridge = $game->publicationBridge;
        $firstInstruction = $bridge['first_prize'] ?? null;
        $bottomInstruction = $bridge['bottom_two'] ?? null;

        if (! is_string($firstInstruction) || trim($firstInstruction) === GameDefinition::REQUIRED_INPUT) {
            throw LotteryGameException::notConfigured(
                $game->code,
                ['publication_bridge.first_prize'],
                'The publication bridge does not say how this game\'s digits become the boundary\'s first prize.',
            );
        }

        $firstPrize = $this->project($digits, trim($firstInstruction), 6);

        if ($firstPrize === null) {
            throw LotteryGameException::invalidInstruction($game->code, $firstInstruction, strlen($digits));
        }

        $bottomTwo = null;

        if (is_string($bottomInstruction) && trim($bottomInstruction) !== '' && trim($bottomInstruction) !== GameDefinition::REQUIRED_INPUT) {
            $candidate = $this->project($digits, trim($bottomInstruction), 2);

            if ($candidate === null) {
                throw LotteryGameException::invalidInstruction($game->code, $bottomInstruction, strlen($digits));
            }

            $bottomTwo = $candidate;
        }

        return ['first_prize' => $firstPrize, 'bottom_two' => $bottomTwo];
    }

    /**
     * Apply one bridge instruction at the width the boundary requires.
     *
     * The width is a parameter because the boundary accepts TWO different widths:
     * a six-digit first prize and a two-digit bottom two. An instruction is only
     * accepted when it produces EXACTLY the required width — otherwise it would
     * have to be padded or truncated, which is inventing digits the operator never
     * authorised.
     *
     * @param  int  $width  the number of digits the boundary field requires
     */
    private function project(string $digits, string $instruction, int $width): ?string
    {
        $value = match (true) {
            $instruction === 'digits' => $digits,
            $instruction === 'digits_left_padded' => str_pad($digits, $width, '0', STR_PAD_LEFT),
            $instruction === 'digits_right_padded' => str_pad($digits, $width, '0', STR_PAD_RIGHT),
            str_starts_with($instruction, 'first:') => substr($digits, 0, (int) substr($instruction, 6)),
            str_starts_with($instruction, 'last:') => substr($digits, -(int) substr($instruction, 5)),
            default => null,
        };

        if ($value === null || $value === '' || ! ctype_digit($value)) {
            return null;
        }

        return strlen($value) === $width ? $value : null;
    }

    /**
     * The draw a provider draw number refers to.
     */
    private function drawFor(GameDefinition $game, string $drawNo): ?Draw
    {
        $draw = Draw::query()
            ->where('game_type', $game->code)
            ->where(function ($query) use ($drawNo): void {
                $query->where('draw_code', $drawNo)
                    ->orWhere('draw_number', $drawNo);
            })
            ->first();

        return $draw instanceof Draw ? $draw : null;
    }

    /**
     * The maker seat, or null when this deployment has appointed none.
     */
    private function makerUserId(): ?int
    {
        $value = $this->config->get('lottery_games.ingestion.maker_user_id');

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * The uniform outcome envelope.
     *
     * @return array<string, mixed>
     */
    private function outcome(
        string $gameCode,
        ?int $importId,
        ?string $drawNo,
        ?string $digits,
        ?string $source,
        string $status,
        ?array $publication,
        ?string $reason,
    ): array {
        return [
            'game_code' => $gameCode,
            'staged' => $status !== 'unavailable' && $importId !== null,
            'import_id' => $importId,
            'draw_no' => $drawNo,
            'digits' => $digits,
            'source' => $source,
            'status' => $status,
            'publication' => $publication,
            'reason' => $reason,
        ];
    }
}
