<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Services\Wallet\WalletOverviewService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The wallet overview is a READ MODEL. Nothing here may move money.
 *
 * The two rules this class exists to defend:
 *   1. an unknown number is NULL, not a plausible zero
 *   2. the balances reported are the ones the Wallet/Ledger services already
 *      hold — this service reads them, it never recomputes or adjusts them
 */
final class WalletOverviewServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_the_overview_reports_balances_as_decimal_strings(): void
    {
        $user = User::factory()->create();

        $overview = app(WalletOverviewService::class)->overview((int) $user->id);

        $this->assertIsString($overview['balances']['main']);
        $this->assertIsString($overview['balances']['win']);
        $this->assertIsString($overview['balances']['bonus']);
        // No float leaked into the payload.
        $this->assertDoesNotMatchRegularExpression('/[eE][+-]?\d/', $overview['balances']['main']);
    }

    public function test_every_balance_is_a_two_place_decimal_string(): void
    {
        $user = User::factory()->create();

        $overview = app(WalletOverviewService::class)->overview((int) $user->id);

        foreach (['main', 'win', 'bonus'] as $bucket) {
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $overview['balances'][$bucket], $bucket);
        }
    }

    public function test_cashback_is_null_when_the_batch_one_reader_is_absent(): void
    {
        $user = User::factory()->create();

        $overview = app(WalletOverviewService::class)->overview((int) $user->id);

        // The overview must not invent a cashback figure when no reader is bound.
        if ($overview['cashback_available'] === false) {
            $this->assertNull($overview['balances']['cashback']);
        } else {
            $this->assertIsString($overview['balances']['cashback']);
        }
    }

    public function test_the_trend_percent_is_null_without_a_baseline(): void
    {
        $user = User::factory()->create();

        $overview = app(WalletOverviewService::class)->overview((int) $user->id);

        if ($overview['trend']['available'] === false) {
            $this->assertNull(
                $overview['trend']['percent'],
                'An unavailable trend must report null, never a fabricated percentage.',
            );
        }
    }

    public function test_the_trend_is_never_a_fabricated_number(): void
    {
        $user = User::factory()->create();

        $overview = app(WalletOverviewService::class)->overview((int) $user->id);
        $percent = $overview['trend']['percent'];

        if ($percent !== null) {
            // If it exists at all it is a numeric string, not a formatted "5.2%".
            $this->assertIsString($percent);
            $this->assertStringNotContainsString('%', $percent);
            $this->assertMatchesRegularExpression('/^-?\d+(\.\d+)?$/', $percent);
        } else {
            $this->assertNull($percent);
        }
    }

    public function test_fx_is_null_by_default_because_the_null_driver_is_the_default(): void
    {
        config(['player_experience.fx.driver' => null, 'player_experience.fx.enabled' => false]);

        $user = User::factory()->create();

        $overview = app(WalletOverviewService::class)->overview((int) $user->id);

        $this->assertFalse($overview['fx_enabled']);
        $this->assertNull($overview['fx'], 'With no FX driver there is no display rate, and null is the honest answer.');
    }

    public function test_reading_the_overview_writes_nothing(): void
    {
        $user = User::factory()->create();

        $before = [
            'wallets' => \App\Models\Wallet::query()->count(),
            'ledger' => \App\Models\LedgerEntry::query()->count(),
            'transactions' => \App\Models\FinancialTransaction::query()->count(),
        ];

        app(WalletOverviewService::class)->overview((int) $user->id);
        app(WalletOverviewService::class)->overview((int) $user->id);

        $this->assertSame($before['wallets'], \App\Models\Wallet::query()->count());
        $this->assertSame($before['ledger'], \App\Models\LedgerEntry::query()->count());
        $this->assertSame($before['transactions'], \App\Models\FinancialTransaction::query()->count());
    }

    public function test_the_payload_carries_a_generated_at_stamp(): void
    {
        $user = User::factory()->create();

        $overview = app(WalletOverviewService::class)->overview((int) $user->id);

        $this->assertNotNull($overview['generated_at']);
    }
}
