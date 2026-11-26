<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\Contracts\LotteryGames\FreeBetVoucherProvider;
use App\Exceptions\LotteryGameException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The seam that spends a Batch-1 free-bet voucher without owning it.
 *
 * WHY AN ADAPTER INSTEAD OF A CALL
 * The voucher lifecycle — issuance, expiry, consumption, reversal — belongs to
 * Batch 1's wallet lane. This batch may SPEND a voucher and must give it back when a
 * placement fails, and it must keep working identically on a deployment where that
 * lane does not exist. On THIS deployment it does not exist: config
 * 'lottery_games.funding.free_bet_provider' is NULL, there is no
 * FreeBetVoucherService in app/, and therefore every free-bet placement is refused
 * with game_funding_unavailable.
 *
 * THAT REFUSAL IS THE FEATURE
 * The alternative — accepting funding_source=free_bet and moving no money — would
 * hand a player a bet nobody paid for and book the liability to the operator. Failing
 * closed is the only honest behaviour when the pot that should fund the stake cannot
 * be named.
 *
 * WHAT THE ADAPTER GUARANTEES WHEN A PROVIDER IS CONFIGURED
 *   validateUsable()  read-only, never consumes, so a quote cannot spend a voucher.
 *   consume()         passed the placement's idempotency key, so a retried request
 *                     cannot spend the same voucher twice.
 *   reverse()         called only after the exposure has been released (see
 *                     GameBetPlacementService::unwind()), so a failed reversal never
 *                     leaves liability reserved for a bet that does not exist.
 *
 * THE PROVIDER IS RESOLVED PER CALL, NOT AT CONSTRUCTION
 * An operator can point configuration at the real service later without a deploy, and
 * a test can bind a fake. Constructing it eagerly would make the lane depend on that
 * lane's presence at boot.
 */
final class FreeBetFundingAdapter
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * The configured provider, or null when this deployment has none.
     *
     * A configured class that does not implement the contract is also null: a
     * provider that cannot honour the contract cannot fund a bet, and silently
     * calling into it would be worse than refusing.
     */
    public function provider(): ?FreeBetVoucherProvider
    {
        $class = $this->config->get('lottery_games.funding.free_bet_provider');

        if (! is_string($class) || trim($class) === '' || ! class_exists($class)) {
            return null;
        }

        $instance = app($class);

        return $instance instanceof FreeBetVoucherProvider ? $instance : null;
    }

    /**
     * Whether free-bet funding is possible at all on this deployment.
     */
    public function isAvailable(): bool
    {
        return $this->provider() instanceof FreeBetVoucherProvider;
    }

    /**
     * Read-only usability check for the quote and placement paths.
     *
     * @return array{voucher_id: string, value: string, currency: string, expires_at: string|null}
     *
     * @throws LotteryGameException game_funding_unavailable
     */
    public function validateUsable(int $userId, string $voucherId): array
    {
        $provider = $this->requireProvider();

        return $provider->validateUsable($userId, $voucherId);
    }

    /**
     * Spend the voucher once, against the placement's idempotency key.
     *
     * @return array{voucher_id: string, funded_amount: string, currency: string}
     *
     * @throws LotteryGameException game_funding_unavailable
     */
    public function consume(int $userId, string $voucherId, string $idempotencyKey): array
    {
        if (trim($idempotencyKey) === '') {
            // Without a key the provider cannot make consumption idempotent, and a
            // retried request could spend the voucher twice.
            throw LotteryGameException::fundingUnavailable(
                'A free-bet placement must carry an idempotency key.',
                ['voucher_id' => $voucherId],
            );
        }

        $provider = $this->requireProvider();

        return $provider->consume($userId, $voucherId, $idempotencyKey);
    }

    /**
     * Give the spend back when the placement it funded did not happen.
     *
     * Called AFTER the exposure release: if this fails, the operator's liability cap
     * is already correct and only the voucher needs reconciling.
     *
     * @throws LotteryGameException game_funding_unavailable
     */
    public function reverse(int $userId, string $voucherId, string $idempotencyKey): void
    {
        $provider = $this->requireProvider();

        $provider->reverse($userId, $voucherId, $idempotencyKey);
    }

    /**
     * Whether a voucher covers the requested stake, and if not, why.
     *
     * A free bet funds a stake; it does not fund a stake AND a balance movement. So
     * the requested stake must not exceed the voucher's value, and the check is
     * reported rather than clamped: silently lowering a player's stake would create a
     * bet they did not ask for.
     *
     * @param  array{value?: string|int|float, funded_amount?: string|int|float}  $voucher
     */
    public function coversStake(array $voucher, string $stake): ?string
    {
        $value = $voucher['value'] ?? $voucher['funded_amount'] ?? null;

        if ($value === null || ! is_numeric((string) $value)) {
            return 'voucher_value_unknown';
        }

        if (bccomp($stake, '0', 2) <= 0) {
            return 'stake_not_positive';
        }

        return bccomp($stake, (string) $value, 2) > 0 ? 'voucher_value_exceeded' : null;
    }

    /**
     * The provider, or a fail-closed refusal naming the configuration key.
     *
     * @throws LotteryGameException game_funding_unavailable
     */
    private function requireProvider(): FreeBetVoucherProvider
    {
        $provider = $this->provider();

        if ($provider instanceof FreeBetVoucherProvider) {
            return $provider;
        }

        throw LotteryGameException::fundingUnavailable(
            'Free-bet funding is unavailable: config("lottery_games.funding.free_bet_provider") '
            .'names no usable implementation on this deployment.',
            [
                'config_key' => 'lottery_games.funding.free_bet_provider',
                'missing' => ['funding.free_bet_provider'],
            ],
        );
    }
}
