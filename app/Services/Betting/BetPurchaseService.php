<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\BetPurchaseResult;
use App\Exceptions\BetPurchaseConcurrencyException;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\BetPurchaseIdempotencyException;
use App\Exceptions\BetPurchaseValidationException;
use App\Models\Bet;
use App\Services\Finance\Money;
use Illuminate\Support\Facades\DB;

/**
 * The one public entry point for buying a bet.
 */
final class BetPurchaseService implements BetAmendmentReplacement
{
    public function __construct(
        private readonly BetPurchaseValidator $validator,
        private readonly BetPurchaseTransactionService $transaction,
        private readonly BetPurchaseIdempotencyService $idempotency,
    ) {}

    /**
     * Buy a bet.
     *
     * @throws BetPurchaseValidationException
     * @throws BetPurchaseIdempotencyException
     * @throws BetPurchaseConcurrencyException
     * @throws BetPurchaseException
     */
    public function purchase(BetPurchaseData $data): BetPurchaseResult
    {
        $this->assertTopLevelTransaction();
        Money::assertExactArithmeticIsAvailable();
        $this->idempotency->assertSchemaSupportsIdempotency();

        $idempotencyKey = $this->idempotency->betKeyFor($data);
        $this->assertNotAmendmentReplacementKey($idempotencyKey, $data->idempotencyKey);

        return $this->executePurchase($data, $idempotencyKey, null);
    }

    /**
     * Buy the replacement bet for an authorized amendment leg.
     */
    public function purchaseAmendment(BetPurchaseData $data, BetAmendmentPermit $permit): BetPurchaseResult
    {
        $this->assertTopLevelTransaction();
        Money::assertExactArithmeticIsAvailable();
        $this->idempotency->assertSchemaSupportsIdempotency();

        $idempotencyKey = $this->idempotency->betKeyFor($data);
        $owner = DB::table('bet_amendment_ownerships')->where('replacement_bet_key', $idempotencyKey)->first();

        if (! $owner || ! $permit->authorizes($owner)) {
            app(BetAmendmentOwnership::class)->refuse();
        }

        return $this->executePurchase($data, $idempotencyKey, $permit);
    }

    /**
     * Buy a bet from a raw request payload.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BetPurchaseValidationException
     * @throws BetPurchaseIdempotencyException
     * @throws BetPurchaseConcurrencyException
     * @throws BetPurchaseException
     */
    public function purchaseFromRequest(int $userId, array $payload): BetPurchaseResult
    {
        if (array_key_exists('permit', $payload)) {
            app(BetAmendmentOwnership::class)->refuse();
        }

        return $this->purchase(BetPurchaseData::fromRequestArray($userId, $payload));
    }

    /**
     * Whether a purchase already exists for a request, without attempting one.
     */
    public function existingPurchaseFor(BetPurchaseData $data): ?BetPurchaseResult
    {
        $this->assertTopLevelTransaction();
        $idempotencyKey = $this->idempotency->betKeyFor($data);
        $this->assertNotAmendmentReplacementKey($idempotencyKey, $data->idempotencyKey);

        $bet = $this->idempotency->resolve($idempotencyKey, $data);

        return $bet instanceof Bet ? $this->idempotency->replayResultFor($bet) : null;
    }

    /**
     * The derived idempotency key for a request.
     */
    public function idempotencyKeyFor(BetPurchaseData $data): string
    {
        return $this->idempotency->betKeyFor($data);
    }

    private function executePurchase(
        BetPurchaseData $data,
        string $idempotencyKey,
        ?BetAmendmentPermit $permit,
    ): BetPurchaseResult {
        $replay = $this->idempotency->resolve($idempotencyKey, $data);

        if ($replay instanceof Bet) {
            if ($permit !== null) {
                app(BetAmendmentOwnership::class)->refuse();
            }

            return $this->idempotency->replayResultFor($replay);
        }

        $context = $this->validator->validate($data);

        try {
            return $this->transaction->execute($context, $permit);
        } catch (BetPurchaseConcurrencyException $exception) {
            if (! $exception->isIdempotentRace() || $permit !== null) {
                throw $exception;
            }

            return $this->replayAfterLostRace($idempotencyKey, $data, $exception);
        }
    }

    private function replayAfterLostRace(
        string $idempotencyKey,
        BetPurchaseData $data,
        BetPurchaseConcurrencyException $exception,
    ): BetPurchaseResult {
        $winner = $this->idempotency->resolve($idempotencyKey, $data);

        if (! $winner instanceof Bet) {
            throw $exception;
        }

        return $this->idempotency->replayResultFor($winner);
    }

    private function assertNotAmendmentReplacementKey(string $betKey, string $rawClientKey): void
    {
        if (DB::table('bet_amendment_ownerships')
            ->where('replacement_bet_key', $betKey)
            ->orWhere('replacement_client_key', trim($rawClientKey))
            ->exists()) {
            app(BetAmendmentOwnership::class)->refuse();
        }
    }

    private function assertTopLevelTransaction(): void
    {
        if (DB::transactionLevel() > 0) {
            throw BetPurchaseException::invariantViolated(
                'a bet purchase must own its own database transaction, but one is already open. '
                .'Nesting it would let a caller commit a purchase that failed.',
                ['transaction_level' => DB::transactionLevel()],
            );
        }
    }
}
