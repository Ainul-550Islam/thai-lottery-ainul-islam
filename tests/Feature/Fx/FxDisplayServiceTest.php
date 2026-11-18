<?php

declare(strict_types=1);

namespace Tests\Feature\Fx;

use App\Models\FxRateSnapshot;
use App\Services\Fx\FxDisplayService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * FX is DISPLAY ONLY, and it is off by default.
 *
 * The assertions that matter here are the negative ones: with the null driver there
 * is no rate, a stale rate is withheld, a zero rate is treated as corrupt data, and
 * an unknown pair is null rather than 1:1. Every one of those is a case where a
 * plausible-looking number would be worse than no number at all.
 *
 * The default configuration is asserted explicitly at the end, because the promise
 * "no outbound network call by default" is a property of config/player_experience.php
 * and nothing else enforces it.
 */
final class FxDisplayServiceTest extends TestCase
{
    use DatabaseTruncation;

    private function enableManualDriver(int $maxAgeMinutes = 1440): void
    {
        config([
            'player_experience.fx.driver' => 'manual',
            'player_experience.wallet.fx_display_enabled' => true,
            'player_experience.wallet.fx_max_age_minutes' => $maxAgeMinutes,
            'player_experience.wallet.fx_default_base' => 'THB',
            'player_experience.wallet.fx_display_quote' => 'USD',
        ]);

        // The provider is a singleton resolved at boot; rebuild it so the config
        // change above actually selects the manual driver for this test.
        $this->app->forgetInstance(\App\Services\Fx\FxRateProvider::class);
    }

    private function snapshot(string $rate, $capturedAt, $expiresAt = null): FxRateSnapshot
    {
        return FxRateSnapshot::query()->create([
            'base' => 'THB',
            'quote' => 'USD',
            'rate' => $rate,
            'source' => 'manual_admin',
            'captured_at' => $capturedAt,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_with_the_null_driver_there_is_no_rate(): void
    {
        config(['player_experience.fx.driver' => 'null']);
        $this->app->forgetInstance(\App\Services\Fx\FxRateProvider::class);

        $this->assertNull(app(FxDisplayService::class)->display('100.00', 'THB', 'USD'));
    }

    public function test_a_fresh_manual_rate_is_displayed_with_an_exact_amount(): void
    {
        $this->enableManualDriver();
        $this->snapshot('0.028000000000', now()->subHour());

        $display = app(FxDisplayService::class)->display('100.00', 'THB', 'USD');

        $this->assertNotNull($display);
        $this->assertSame('THB', $display['base']);
        $this->assertSame('USD', $display['quote']);
        $this->assertTrue($display['approximate'], 'A display conversion is always labelled approximate.');
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $display['amount']);
        // 100 / 0.028 = 3571.428571... bcdiv TRUNCATES to 3571.42. Truncation is
        // deliberate: a display figure is never rounded UP into a number the member
        // could mistake for a promise about what they will receive.
        $this->assertSame('3571.42', $display['amount']);
    }

    public function test_a_stale_rate_is_withheld(): void
    {
        $this->enableManualDriver(maxAgeMinutes: 60);
        $this->snapshot('0.028000000000', now()->subDays(3));

        $this->assertNull(
            app(FxDisplayService::class)->display('100.00', 'THB', 'USD'),
            'A stale rate must be withheld, not shown without a warning.',
        );
    }

    public function test_an_expired_rate_is_withheld_even_when_it_is_new(): void
    {
        $this->enableManualDriver(maxAgeMinutes: 60000);
        $this->snapshot('0.028000000000', now()->subMinute(), now()->subMinute());

        $this->assertNull(app(FxDisplayService::class)->display('100.00', 'THB', 'USD'));
    }

    public function test_an_unknown_pair_is_null_not_one_to_one(): void
    {
        $this->enableManualDriver();

        $this->assertNull(
            app(FxDisplayService::class)->display('100.00', 'THB', 'XYZ'),
            'An unknown pair must be null. Returning 1.0 would silently convert money.',
        );
    }

    public function test_a_zero_rate_is_treated_as_corrupt_data(): void
    {
        $this->enableManualDriver();
        $this->snapshot('0.000000000000', now()->subMinute());

        $this->assertNull(
            app(FxDisplayService::class)->display('100.00', 'THB', 'USD'),
            'A zero rate would divide by zero or produce an infinite amount; it is refused.',
        );
    }

    public function test_the_same_currency_is_never_converted(): void
    {
        $this->enableManualDriver();

        $this->assertNull(app(FxDisplayService::class)->display('100.00', 'THB', 'THB'));
    }

    public function test_the_newest_snapshot_wins(): void
    {
        $this->enableManualDriver(maxAgeMinutes: 2880);
        $this->snapshot('0.020000000000', now()->subHours(20));
        $this->snapshot('0.031000000000', now()->subHour());

        $display = app(FxDisplayService::class)->display('100.00', 'THB', 'USD');

        $this->assertNotNull($display);
        $this->assertSame('0.031000000000', $display['rate']);
    }

    public function test_the_display_block_is_off_unless_enabled(): void
    {
        config(['player_experience.wallet.fx_display_enabled' => false]);

        $this->assertFalse(app(FxDisplayService::class)->enabled());
    }

    public function test_the_import_command_is_a_no_op_without_a_driver(): void
    {
        config(['player_experience.fx.driver' => 'null']);
        $this->app->forgetInstance(\App\Services\Fx\FxRateProvider::class);

        $before = FxRateSnapshot::query()->count();

        $this->artisan('fx:import')->assertSuccessful();

        $this->assertSame($before, FxRateSnapshot::query()->count());
    }

    public function test_the_default_configuration_makes_no_outbound_call(): void
    {
        // The default is the "no network" configuration; this test pins it, because a
        // default that reached out would be a dependency introduced by a config edit.
        $this->assertSame('null', (string) config('player_experience.fx.driver'));
        $this->assertFalse((bool) config('player_experience.wallet.fx_display_enabled'));
    }
}
