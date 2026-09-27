<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloClaimStatus;
use App\Enums\GloPublicStatus;
use App\Exceptions\GloDealerException;
use App\Models\GloPrizeClaim;
use App\Models\GloTicket;
use App\Models\LotteryTicketVerification;
use App\Models\Ticket;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Public prize / ticket verification orchestrator (PROMPT 4).
 *
 * THIS CLASS DECIDES NOTHING ON ITS OWN.
 * Every substantive answer comes from a service that already owned that
 * question before this wave existed:
 *
 *   normalisation + bounds     TicketIdentityService
 *   barcode / Data Matrix      TicketBarcodeService -> GloDataMatrixParser
 *   authenticity verdict       TicketAuthenticityService
 *   GLO result + prize match   GloPublicResultService -> GloTicketChecker
 *   GLO N3 prize match         GloN3TicketChecker
 *   freeze / payment hold      GloPublicTicketVerificationService
 *   claim state                GloPrizeClaim (read-only status column)
 *
 * What this class adds is the ORDER, the public vocabulary, and the
 * disclosure boundary. No claim logic, no freeze logic and no prize
 * arithmetic is reimplemented here.
 *
 * PRODUCT SEPARATION IS ABSOLUTE
 * A GLO L6/N3 lookup and an operator betting-ticket lookup never share a
 * branch. An operator ticket can never come back labelled as a GLO ticket,
 * and a GLO answer is never produced from operator data.
 *
 * THE PUBLIC ANSWER IS BUILT FROM AN ALLOW-LIST
 * The returned array is assembled key by key. There is no "return the model"
 * path and no spread of an internal payload, so a future column cannot leak
 * by accident. Owner identity, contact details, bank details, national id,
 * freeze case notes and internal ids are never read into the response.
 */
final class PrizeVerificationService
{
    public const NOT_FOUND = 'NOT_FOUND';

    public const FOUND_NOT_WINNING = 'FOUND_NOT_WINNING';

    public const WINNING = 'WINNING';

    public const PAYMENT_HOLD = 'PAYMENT_HOLD';

    public const PAID = 'PAID';

    public const INVALID = 'INVALID';

    public const UNAVAILABLE = 'UNAVAILABLE';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly TicketIdentityService $identity,
        private readonly TicketBarcodeService $barcode,
        private readonly TicketAuthenticityService $authenticity,
        private readonly GloPublicResultService $publicResults,
        private readonly GloN3TicketChecker $n3Checker,
        private readonly GloPublicTicketVerificationService $publicTicketStatus,
    ) {}

    /**
     * Verify one public request.
     *
     * @return array{
     *     status: string,
     *     status_is_public_vocabulary: true,
     *     kind: string,
     *     product: string,
     *     query_echo: string|null,
     *     draw: array{id: int|null, number: string|null, date: string|null},
     *     prize: array{category: string|null, amount: string|null, matches: list<array<string, mixed>>},
     *     authenticity: array<string, mixed>,
     *     barcode: array<string, mixed>|null,
     *     official_source: bool,
     *     fixture: bool,
     *     reason: string|null,
     *     evidence_uuid: string|null,
     *     verified_at: string
     * }
     */
    public function verify(
        string $kind,
        string $value,
        ?string $product = null,
        ?string $drawRef = null,
        ?string $correlationId = null,
    ): array {
        $startedAt = microtime(true);

        if ((bool) $this->config->get('ticket_verification.enabled', true) === false) {
            return $this->finish(
                $this->answer(self::UNAVAILABLE, $kind, TicketIdentityService::PRODUCT_UNKNOWN, reason: 'VERIFICATION_DISABLED'),
                null,
                $correlationId,
                $startedAt,
            );
        }

        if (! $this->modeEnabled($kind)) {
            return $this->finish(
                $this->answer(self::INVALID, $kind, TicketIdentityService::PRODUCT_UNKNOWN, reason: 'MODE_DISABLED'),
                null,
                $correlationId,
                $startedAt,
            );
        }

        $normalised = $this->identity->normalise($kind, $value, $product);

        if ($normalised['valid'] === false) {
            // Bounds and character-set failures never reach the database.
            return $this->finish(
                $this->answer(self::INVALID, $kind, TicketIdentityService::PRODUCT_UNKNOWN, reason: (string) $normalised['reason']),
                null,
                $correlationId,
                $startedAt,
            );
        }

        $barcodeRead = null;

        if ($normalised['kind'] === TicketIdentityService::KIND_BARCODE) {
            $barcodeRead = $this->barcode->read($normalised['canonical']);

            if ($barcodeRead['state'] === TicketBarcodeService::INVALID) {
                return $this->finish(
                    $this->answer(
                        self::INVALID,
                        $kind,
                        TicketIdentityService::PRODUCT_UNKNOWN,
                        reason: $barcodeRead['reason'] ?? 'INVALID_PAYLOAD',
                        barcode: $barcodeRead,
                    ),
                    $normalised,
                    $correlationId,
                    $startedAt,
                );
            }

            if ($barcodeRead['number'] === null) {
                // NOT_CONFIGURED / UNSUPPORTED_FORMAT: say so, do not guess.
                return $this->finish(
                    $this->answer(
                        self::UNAVAILABLE,
                        $kind,
                        $barcodeRead['product'],
                        reason: $barcodeRead['state'],
                        barcode: $barcodeRead,
                        fixture: (bool) $barcodeRead['fixture'],
                    ),
                    $normalised,
                    $correlationId,
                    $startedAt,
                );
            }

            // A fixture decode yields a number we may check, but the answer
            // stays flagged as fixture-derived for the rest of the flow.
            $normalised['number'] = (string) $barcodeRead['number'];
            $normalised['product'] = $barcodeRead['product'];
        }

        try {
            $answer = match ($normalised['product']) {
                TicketIdentityService::PRODUCT_GLO_L6 => $this->verifyGloL6($normalised, $drawRef, $barcodeRead),
                TicketIdentityService::PRODUCT_GLO_N3 => $this->verifyGloN3($normalised, $barcodeRead),
                TicketIdentityService::PRODUCT_OPERATOR => $this->verifyOperator($normalised),
                default => $this->answer(self::UNAVAILABLE, $kind, TicketIdentityService::PRODUCT_UNKNOWN, reason: 'PRODUCT_NOT_RESOLVED', barcode: $barcodeRead),
            };
        } catch (GloDealerException $e) {
            // The GLO services raise this for input the deeper layer rejects.
            $answer = $this->answer(self::INVALID, $kind, $normalised['product'], reason: 'REJECTED_BY_PROVIDER', barcode: $barcodeRead);
        } catch (Throwable $e) {
            // Never leak an exception message to an anonymous caller.
            Log::warning('prize_verification.failed', [
                'kind' => $normalised['kind'],
                'product' => $normalised['product'],
                'correlation_id' => $correlationId,
                'exception' => $e::class,
            ]);

            $answer = $this->answer(self::UNAVAILABLE, $kind, $normalised['product'], reason: 'PROVIDER_UNAVAILABLE', barcode: $barcodeRead);
        }

        return $this->finish($answer, $normalised, $correlationId, $startedAt);
    }

    /**
     * GLO six-digit ticket.
     *
     * @param  array<string, mixed>  $normalised
     * @param  array<string, mixed>|null  $barcodeRead
     * @return array<string, mixed>
     */
    private function verifyGloL6(array $normalised, ?string $drawRef, ?array $barcodeRead): array
    {
        $number = (string) $normalised['number'];

        // Six digits are required by the result service; a 2/3-digit value can
        // only be N3 and never reaches here.
        if (preg_match('/^\d{6}$/', $number) !== 1) {
            return $this->answer(self::INVALID, (string) $normalised['kind'], TicketIdentityService::PRODUCT_GLO_L6, reason: 'MALFORMED_NUMBER', barcode: $barcodeRead);
        }

        $check = $this->publicResults->checkSixDigit($number, $drawRef);

        $drawId = isset($check['draw_id']) ? (int) $check['draw_id'] : null;
        $won = (bool) ($check['won'] ?? false);
        /** @var list<array<string, mixed>> $matches */
        $matches = is_array($check['matches'] ?? null) ? $check['matches'] : [];

        // Locate the platform's own projection of this ticket, if any. This is
        // what makes freeze / claim / authenticity answerable at all; without
        // it we can still answer the RESULT question.
        $ticket = $drawId !== null
            ? GloTicket::query()
                ->where('draw_id', $drawId)
                ->where('product', 'l6')
                ->where('ticket_number', $number)
                ->first()
            : null;

        $authenticity = $this->authenticity->forGloTicket($ticket);

        $status = $won ? self::WINNING : self::FOUND_NOT_WINNING;

        if (($check['source_state'] ?? null) === 'not_configured' && $matches === []) {
            // No published result to compare against: do not imply a loss.
            $status = self::UNAVAILABLE;
        }

        if ($ticket !== null) {
            $status = $this->applyHoldAndClaimState($ticket, $status);
        }

        return $this->answer(
            $status,
            (string) $normalised['kind'],
            TicketIdentityService::PRODUCT_GLO_L6,
            queryEcho: $number,
            draw: [
                'id' => $drawId,
                'number' => isset($check['draw_number']) ? (string) $check['draw_number'] : null,
                'date' => isset($check['draw_date']) && $check['draw_date'] !== null ? (string) $check['draw_date'] : null,
            ],
            prize: [
                'category' => $this->firstCategory($matches),
                'amount' => $won ? (string) ($check['total_prize'] ?? '0.00') : null,
                'matches' => $this->publicMatches($matches),
            ],
            authenticity: $authenticity,
            barcode: $barcodeRead,
            fixture: (bool) ($barcodeRead['fixture'] ?? false),
        );
    }

    /**
     * GLO three-digit (or two-digit) product.
     *
     * The N3 checker is draw-scoped and there is no public "latest N3 draw"
     * resolver, so a bare number cannot be checked without a reference. That
     * is reported as UNAVAILABLE rather than answered with a guess.
     *
     * @param  array<string, mixed>  $normalised
     * @param  array<string, mixed>|null  $barcodeRead
     * @return array<string, mixed>
     */
    private function verifyGloN3(array $normalised, ?array $barcodeRead): array
    {
        $number = (string) $normalised['number'];
        $drawId = $normalised['draw_id'] !== null ? (int) $normalised['draw_id'] : null;

        if ($drawId === null) {
            return $this->answer(
                self::UNAVAILABLE,
                (string) $normalised['kind'],
                TicketIdentityService::PRODUCT_GLO_N3,
                queryEcho: $number,
                reason: 'DRAW_REFERENCE_REQUIRED',
                barcode: $barcodeRead,
            );
        }

        $check = $this->n3Checker->check($drawId, $number);

        $won = (bool) ($check['won'] ?? false);
        /** @var list<array<string, mixed>> $matches */
        $matches = is_array($check['matches'] ?? null) ? $check['matches'] : [];

        $ticket = GloTicket::query()
            ->where('draw_id', $drawId)
            ->where('product', 'n3')
            ->where('ticket_number', $number)
            ->first();

        $status = $won ? self::WINNING : self::FOUND_NOT_WINNING;

        if ($ticket !== null) {
            $status = $this->applyHoldAndClaimState($ticket, $status);
        }

        return $this->answer(
            $status,
            (string) $normalised['kind'],
            TicketIdentityService::PRODUCT_GLO_N3,
            queryEcho: $number,
            draw: ['id' => $drawId, 'number' => null, 'date' => null],
            prize: [
                'category' => $this->firstCategory($matches),
                'amount' => $won && isset($check['total_prize']) ? (string) $check['total_prize'] : null,
                'matches' => $this->publicMatches($matches),
            ],
            authenticity: $this->authenticity->forGloTicket($ticket),
            barcode: $barcodeRead,
            fixture: (bool) ($barcodeRead['fixture'] ?? false),
        );
    }

    /**
     * This platform's own betting ticket.
     *
     * Only existence, lifecycle state and authenticity are public. Stakes,
     * selections, payouts and the owner are NOT public and are not read.
     *
     * @param  array<string, mixed>  $normalised
     * @return array<string, mixed>
     */
    private function verifyOperator(array $normalised): array
    {
        $reference = (string) $normalised['canonical'];

        $ticket = Ticket::query()
            ->where('ticket_number', $reference)
            ->first();

        if ($ticket === null) {
            return $this->answer(
                self::NOT_FOUND,
                (string) $normalised['kind'],
                TicketIdentityService::PRODUCT_OPERATOR,
                queryEcho: $reference,
                authenticity: $this->authenticity->forOperatorTicket(null),
            );
        }

        $authenticity = $this->authenticity->forOperatorTicket($ticket);

        $status = $authenticity['state'] === TicketAuthenticityService::REVOKED
            ? self::FOUND_NOT_WINNING
            : self::FOUND_NOT_WINNING;

        return $this->answer(
            $status,
            (string) $normalised['kind'],
            TicketIdentityService::PRODUCT_OPERATOR,
            queryEcho: $reference,
            draw: ['id' => (int) $ticket->draw_id, 'number' => null, 'date' => null],
            authenticity: $authenticity,
            // An operator ticket is this platform's own record. It is never an
            // official GLO ticket and is labelled accordingly in the view.
            reason: 'OPERATOR_TICKET_RECORD',
        );
    }

    /**
     * Freeze / payment-hold / paid state, in public vocabulary only.
     *
     * Delegates the freeze decision to the existing public status service so
     * there is exactly one implementation of "is this ticket held".
     */
    private function applyHoldAndClaimState(GloTicket $ticket, string $status): string
    {
        $reference = (string) $ticket->ticket_reference;

        if ($reference !== '') {
            $public = $this->publicTicketStatus->verify($reference);
            $publicStatus = (string) ($public['status'] ?? '');

            if ($publicStatus === GloPublicStatus::FrozenWinningPaymentHeld->value
                || $publicStatus === GloPublicStatus::Frozen->value) {
                // Safe wording: the public is told payment is held, never why.
                return self::PAYMENT_HOLD;
            }
        }

        $claimStatus = GloPrizeClaim::query()
            ->where('ticket_id', $ticket->getKey())
            ->orderByDesc('id')
            ->value('status');

        $claimValue = $claimStatus instanceof GloClaimStatus
            ? $claimStatus->value
            : (is_string($claimStatus) ? $claimStatus : null);

        if ($claimValue === GloClaimStatus::Paid->value) {
            return self::PAID;
        }

        if ($claimValue === GloClaimStatus::Hold->value) {
            return self::PAYMENT_HOLD;
        }

        return $status;
    }

    /**
     * Prize matches, reduced to public fields only.
     *
     * @param  list<array<string, mixed>>  $matches
     * @return list<array<string, mixed>>
     */
    private function publicMatches(array $matches): array
    {
        $public = [];

        foreach (array_slice($matches, 0, 10) as $match) {
            $public[] = [
                'tier' => isset($match['tier']) ? (string) $match['tier'] : null,
                'label' => isset($match['label']) ? (string) $match['label'] : null,
                'amount' => isset($match['amount']) ? (string) $match['amount'] : null,
            ];
        }

        return $public;
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     */
    private function firstCategory(array $matches): ?string
    {
        foreach ($matches as $match) {
            if (isset($match['tier'])) {
                return (string) $match['tier'];
            }
        }

        return null;
    }

    private function modeEnabled(string $kind): bool
    {
        return (bool) $this->config->get('ticket_verification.modes.'.strtolower($kind).'.enabled', false);
    }

    /**
     * Assemble the public answer. Every key is written explicitly.
     *
     * @param  array{id: int|null, number: string|null, date: string|null}|null  $draw
     * @param  array{category: string|null, amount: string|null, matches: list<array<string, mixed>>}|null  $prize
     * @param  array<string, mixed>|null  $authenticity
     * @param  array<string, mixed>|null  $barcode
     * @return array<string, mixed>
     */
    private function answer(
        string $status,
        string $kind,
        string $product,
        ?string $queryEcho = null,
        ?array $draw = null,
        ?array $prize = null,
        ?array $authenticity = null,
        ?array $barcode = null,
        bool $fixture = false,
        ?string $reason = null,
    ): array {
        $allowed = (array) $this->config->get('ticket_verification.public_statuses', []);

        if ($allowed !== [] && ! in_array($status, $allowed, true)) {
            $status = self::UNAVAILABLE;
        }

        return [
            'status' => $status,
            'status_is_public_vocabulary' => true,
            'kind' => $kind,
            'product' => $product,
            'query_echo' => $queryEcho,
            'draw' => $draw ?? ['id' => null, 'number' => null, 'date' => null],
            'prize' => $prize ?? ['category' => null, 'amount' => null, 'matches' => []],
            'authenticity' => $authenticity ?? [
                'state' => TicketAuthenticityService::NOT_VERIFIED,
                'paper_authenticity_claimed' => false,
            ],
            'barcode' => $barcode,
            // No authorised official GLO verification integration exists, so
            // this is false everywhere. The page says "GLO-compatible".
            'official_source' => false,
            'fixture' => $fixture,
            'reason' => $reason,
            'evidence_uuid' => null,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Record evidence, shape the timing, and return.
     *
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>|null  $normalised
     * @return array<string, mixed>
     */
    private function finish(array $answer, ?array $normalised, ?string $correlationId, float $startedAt): array
    {
        $evidence = $this->recordEvidence($answer, $normalised, $correlationId);

        if ($evidence !== null) {
            $answer['evidence_uuid'] = $evidence;
        }

        $this->shapeTiming($startedAt);

        return $answer;
    }

    /**
     * One immutable row per verification, carrying a HASH of the query only.
     *
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>|null  $normalised
     */
    private function recordEvidence(array $answer, ?array $normalised, ?string $correlationId): ?string
    {
        if ((bool) $this->config->get('ticket_verification.evidence.enabled', true) === false) {
            return null;
        }

        $canonical = $normalised !== null ? (string) ($normalised['canonical'] ?? '') : '';

        try {
            $record = LotteryTicketVerification::query()->firstOrCreate(
                [
                    'query_fingerprint' => $this->identity->fingerprint($canonical),
                    'correlation_id' => $correlationId,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'verification_kind' => (string) $answer['kind'],
                    'product' => (string) $answer['product'],
                    'public_status' => (string) $answer['status'],
                    'authenticity_state' => isset($answer['authenticity']['state'])
                        ? (string) $answer['authenticity']['state']
                        : null,
                    'barcode_state' => isset($answer['barcode']['state'])
                        ? (string) $answer['barcode']['state']
                        : null,
                    'fixture_used' => (bool) $answer['fixture'],
                    'policy_version' => (string) $this->config->get('ticket_verification.policy_version', '1'),
                    'metadata' => ['reason' => $answer['reason']],
                    'verified_at' => now(),
                ],
            );

            return (string) $record->uuid;
        } catch (Throwable $e) {
            // Evidence must never break the answer. A duplicate key from a
            // concurrent identical request is the expected benign case.
            Log::info('prize_verification.evidence_skipped', ['exception' => $e::class]);

            return null;
        }
    }

    /**
     * Pad cheap paths towards the cost of expensive ones.
     *
     * This narrows, and does not eliminate, the timing channel that
     * distinguishes "rejected by pattern" from "looked up and not found".
     * It is documented as mitigation, not as a constant-time guarantee.
     */
    private function shapeTiming(float $startedAt): void
    {
        if ((bool) $this->config->get('ticket_verification.timing.enabled', true) === false) {
            return;
        }

        $floorMs = (int) $this->config->get('ticket_verification.timing.floor_milliseconds', 120);
        $maxMs = (int) $this->config->get('ticket_verification.timing.max_sleep_milliseconds', 400);

        if ($floorMs <= 0) {
            return;
        }

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $remainingMs = (int) floor($floorMs - $elapsedMs);

        if ($remainingMs <= 0) {
            return;
        }

        usleep(min($remainingMs, $maxMs) * 1000);
    }
}
