<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use Tests\Support\LotteryGames\FakeCashbackService;
use Tests\Support\LotteryGames\FakeFreeBetVoucherProvider;
use Tests\Support\LotteryGames\MisshapenCashbackService;
use Tests\TestCase;

/**
 * lottery-games:check-funding-hooks is the deployment gate for the two Batch-1 services
 * the lane can only reach through configuration.
 *
 * It checks SHAPE and never calls either service, so every case below is a configuration
 * change followed by an exit code.
 */
final class CheckFundingHooksCommandTest extends TestCase
{
    private function configure(?string $freeBet, ?string $cashback): void
    {
        config()->set('lottery_games.funding.free_bet_provider', $freeBet);
        config()->set('lottery_games.funding.cashback_service', $cashback);
    }

    public function test_it_fails_while_nothing_is_configured(): void
    {
        $this->configure(null, null);

        $this->artisan('lottery-games:check-funding-hooks')
            ->expectsOutputToContain('free_bet_provider: not configured')
            ->expectsOutputToContain('cashback_service: not configured')
            ->assertExitCode(1);
    }

    public function test_allow_unset_downgrades_an_unconfigured_hook_to_a_warning(): void
    {
        $this->configure(null, null);

        $this->artisan('lottery-games:check-funding-hooks', ['--allow-unset' => true])
            ->assertExitCode(0);
    }

    public function test_it_passes_when_both_hooks_resolve_with_the_right_shape(): void
    {
        $this->configure(FakeFreeBetVoucherProvider::class, FakeCashbackService::class);

        $this->artisan('lottery-games:check-funding-hooks')
            ->expectsOutputToContain('funding hooks: ready')
            ->assertExitCode(0);
    }

    public function test_a_class_that_does_not_exist_always_fails_even_with_allow_unset(): void
    {
        $this->configure('App\\Services\\DoesNotExist\\Vouchers', null);

        $this->artisan('lottery-games:check-funding-hooks', ['--allow-unset' => true])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);
    }

    public function test_a_provider_that_is_not_the_voucher_contract_fails(): void
    {
        $this->configure(FakeCashbackService::class, null);

        $this->artisan('lottery-games:check-funding-hooks', ['--allow-unset' => true])
            ->expectsOutputToContain('does not implement')
            ->assertExitCode(1);
    }

    public function test_a_cashback_service_with_the_wrong_reverse_signature_fails(): void
    {
        $this->configure(null, MisshapenCashbackService::class);

        $this->artisan('lottery-games:check-funding-hooks', ['--allow-unset' => true])
            ->expectsOutputToContain('the cancellation listener passes exactly three')
            ->assertExitCode(1);
    }
}
