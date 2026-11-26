<?php

declare(strict_types=1);

namespace Tests\Support\LotteryGames;

use App\Contracts\LotteryGames\FreeBetVoucherProvider;
use App\Exceptions\LotteryGameException;

/**
 * A stand-in for the Batch-1 free-bet voucher lane, which does not exist on this
 * deployment.
 *
 * WHY A FAKE IS THE ONLY WAY TO TEST THIS SEAM
 * App\Services\LotteryGames\FreeBetFundingAdapter resolves its provider from
 * config('lottery_games.funding.free_bet_provider') and refuses with
 * game_funding_unavailable when that names nothing usable - which is the shipped
 * state. The refusal is tested directly. Everything ELSE the lane promises about a
 * voucher (validated once, consumed once per idempotency key, given back on failure)
 * can only be observed through a provider, so this class implements the contract the
 * Batch-1 lane will implement and RECORDS every call the lane makes.
 *
 * WHAT IT DOES NOT DO, DELIBERATELY
 * It moves no money and creates no wallet credit. A real provider would make the
 * player whole; doing that here would let a test assert a balance change that the
 * digit-game lane never caused, which is exactly the confusion Rule §0.6 exists to
 * prevent. Tests using this fake assert what THIS batch is responsible for: that it
 * asked the provider the right questions, once, in the right order.
 *
 * DOUBLE-SPEND IS A HARD FAILURE
 * A real voucher can only be spent once. consume() therefore refuses a second spend of
 * the same voucher under a DIFFERENT idempotency key, and replays the original result
 * under the same key. That is how the suite detects a lane that consumes on every
 * retry instead of only on the first attempt.
 */
final class FakeFreeBetVoucherProvider implements FreeBetVoucherProvider
{
    /**
     * The most recently constructed instance, so a test can inspect the calls the
     * container-resolved provider received without holding the reference itself.
     */
    public static ?self $latest = null;

    /** @var list<array{user_id: int, voucher_id: string}> */
    public array $validations = [];

    /** @var list<array{user_id: int, voucher_id: string, idempotency_key: string}> */
    public array $consumptions = [];

    /** @var list<array{user_id: int, voucher_id: string, idempotency_key: string}> */
    public array $reversals = [];

    /** @var array<string, array{voucher_id: string, funded_amount: string, currency: string}> */
    private array $spent = [];

    public string $value = '10.00';

    public string $currency = 'THB';

    public ?string $expiresAt = null;

    /**
     * When set, validateUsable() refuses with this LotteryGameException. Used to prove
     * that a provider-level refusal leaves the platform with no bet and no reservation.
     */
    public ?LotteryGameException $validationFailure = null;

    public function __construct()
    {
        self::$latest = $this;
    }

    /**
     * @return array{voucher_id: string, value: string, currency: string, expires_at: string|null}
     */
    public function validateUsable(int $userId, string $voucherId): array
    {
        $this->validations[] = ['user_id' => $userId, 'voucher_id' => $voucherId];

        if ($this->validationFailure instanceof LotteryGameException) {
            throw $this->validationFailure;
        }

        return [
            'voucher_id' => $voucherId,
            'value' => $this->value,
            'currency' => $this->currency,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * @return array{voucher_id: string, funded_amount: string, currency: string}
     */
    public function consume(int $userId, string $voucherId, string $idempotencyKey): array
    {
        // A replay of the SAME request returns the original spend instead of spending
        // again: a retried HTTP request must not burn two vouchers.
        if (isset($this->spent[$voucherId.'|'.$idempotencyKey])) {
            return $this->spent[$voucherId.'|'.$idempotencyKey];
        }

        // A different request against an already spent voucher is a double spend.
        foreach (array_keys($this->spent) as $key) {
            if (str_starts_with($key, $voucherId.'|')) {
                throw LotteryGameException::fundingUnavailable('This voucher has already been spent.', [
                    'voucher_id' => $voucherId,
                ]);
            }
        }

        $this->consumptions[] = [
            'user_id' => $userId,
            'voucher_id' => $voucherId,
            'idempotency_key' => $idempotencyKey,
        ];

        $outcome = [
            'voucher_id' => $voucherId,
            'funded_amount' => $this->value,
            'currency' => $this->currency,
        ];

        $this->spent[$voucherId.'|'.$idempotencyKey] = $outcome;

        return $outcome;
    }

    public function reverse(int $userId, string $voucherId, string $idempotencyKey): void
    {
        $this->reversals[] = [
            'user_id' => $userId,
            'voucher_id' => $voucherId,
            'idempotency_key' => $idempotencyKey,
        ];

        unset($this->spent[$voucherId.'|'.$idempotencyKey]);
    }

    public function consumeCount(): int
    {
        return count($this->consumptions);
    }

    public function reverseCount(): int
    {
        return count($this->reversals);
    }
}
