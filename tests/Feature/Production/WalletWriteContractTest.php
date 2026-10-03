<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Enums\Currency;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RELEASE-BLOCKING wallet write contract.
 *
 * The production wallet blocker had one cause and two faces. Both services
 * passed `status`, the six money columns and `version` inside the attribute
 * array of Wallet::query()->firstOrCreate(...), but none of those columns are
 * in $fillable:
 *
 *   - outside production, preventSilentlyDiscardingAttributes() turned that
 *     into a MassAssignmentException;
 *   - inside production, the attributes were silently discarded, so `version`
 *     persisted at the old column default of 0 while all seven optimistic-lock
 *     writers in Finance\WalletService increment from an assumed 1-based token.
 *
 * WalletFactory never set `version` either, so the suite exercised a state
 * production could not produce -- which is why this survived review.
 *
 * These tests lock the contract in place from both directions: the invariants
 * must hold no matter which creation path is used, AND the mass-assignment
 * boundary must stay closed.
 */
#[Group('production-safety')]
final class WalletWriteContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_wallet_created_through_the_model_starts_at_version_one(): void
    {
        $user = User::factory()->create();

        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'type' => WalletType::Primary,
            'currency' => Currency::THB,
        ]);

        $this->assertSame(
            1,
            (int) $wallet->fresh()->version,
            'A new wallet must start at version 1. The optimistic-lock writers increment from a 1-based token.'
        );
    }

    #[Test]
    public function a_wallet_created_through_first_or_create_starts_at_version_one(): void
    {
        // This is the exact call shape both WalletServices use.
        $user = User::factory()->create();

        $wallet = Wallet::query()->firstOrCreate([
            'user_id' => $user->id,
            'type' => WalletType::Primary,
            'currency' => Currency::THB,
        ]);

        $this->assertSame(1, (int) $wallet->fresh()->version);
    }

    #[Test]
    public function the_factory_produces_the_same_version_as_production(): void
    {
        $wallet = Wallet::factory()->create();

        $this->assertSame(
            1,
            (int) $wallet->fresh()->version,
            'The factory must not produce a wallet state that production cannot produce.'
        );
    }

    #[Test]
    public function a_new_wallet_is_active_and_has_no_null_money_columns(): void
    {
        $user = User::factory()->create();

        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'type' => WalletType::Primary,
            'currency' => Currency::THB,
        ])->fresh();

        $this->assertSame(WalletStatus::Active, $wallet->status);

        foreach (Wallet::MONEY_COLUMNS as $column) {
            $this->assertNotNull(
                $wallet->{$column},
                "{$column} is null on a new wallet; bcmath throws on null and getAvailableBalance() would be fatal."
            );
            $this->assertSame('0.00', (string) $wallet->{$column});
        }
    }

    #[Test]
    public function available_balance_is_computable_immediately_after_creation(): void
    {
        $user = User::factory()->create();

        $wallet = Wallet::query()->create([
            'user_id' => $user->id,
            'type' => WalletType::Primary,
            'currency' => Currency::THB,
        ]);

        $this->assertSame('0.00', $wallet->getAvailableBalance());
    }

    #[Test]
    public function financial_columns_are_not_mass_assignable(): void
    {
        $fillable = (new Wallet)->getFillable();

        $forbidden = array_merge(Wallet::MONEY_COLUMNS, ['version', 'status', 'locked_at', 'locked_reason']);

        foreach ($forbidden as $column) {
            $this->assertNotContains(
                $column,
                $fillable,
                "{$column} is mass assignable. Financial state must be written only by the wallet service inside a locked transaction."
            );
        }
    }

    #[Test]
    public function the_model_is_not_globally_unguarded(): void
    {
        $this->assertNotSame(
            [],
            (new Wallet)->getGuarded(),
            'Wallet must not use $guarded = []. That would expose every financial column at once.'
        );
    }

    #[Test]
    public function the_wallets_table_defaults_version_to_one(): void
    {
        // Covers the non-Eloquent paths: raw inserts, bulk imports, restores.
        $user = User::factory()->create();

        DB::table('wallets')->insert([
            'user_id' => $user->id,
            'type' => WalletType::Primary->value,
            'status' => WalletStatus::Active->value,
            'currency' => Currency::THB->value,
            'balance' => '0.00',
            'locked_balance' => '0.00',
            'total_deposited' => '0.00',
            'total_withdrawn' => '0.00',
            'total_wagered' => '0.00',
            'total_won' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $version = DB::table('wallets')->where('user_id', $user->id)->value('version');

        $this->assertSame(
            1,
            (int) $version,
            'The wallets.version column default must be 1, so inserts that bypass the model still land on a valid token.'
        );
    }

    #[Test]
    public function no_wallet_in_the_database_can_sit_below_the_initial_version(): void
    {
        Wallet::factory()->count(3)->create();

        $below = DB::table('wallets')->where('version', '<', Wallet::INITIAL_VERSION)->count();

        $this->assertSame(0, $below, 'Wallets exist below the initial optimistic-lock version.');
    }
}
