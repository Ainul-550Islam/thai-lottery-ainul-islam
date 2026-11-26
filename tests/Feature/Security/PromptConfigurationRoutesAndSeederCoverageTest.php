<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Resources\DepositResource\DepositDecisionActions;
use App\Filament\Resources\WalletResource\WalletControlActions;
use Database\Seeders\ProductionSimulationSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PromptConfigurationRoutesAndSeederCoverageTest extends TestCase
{
    public function test_admin_action_sets_contain_no_arbitrary_money_creation_action(): void
    {
        $walletActions = array_column(WalletControlActions::specs(), 'name');
        $depositActions = array_column(DepositDecisionActions::specs(), 'name');

        self::assertNotContains('adjustBalance', $walletActions);
        self::assertNotContains('complete', $depositActions);
    }

    public function test_production_simulation_seeder_guards_before_any_database_work(): void
    {
        $source = file_get_contents((new \ReflectionClass(ProductionSimulationSeeder::class))->getFileName());

        self::assertIsString($source);
        $productionGuard = strpos($source, "environment('production')");
        $transaction = strpos($source, 'DB::transaction');
        self::assertIsInt($productionGuard);
        self::assertIsInt($transaction);
        self::assertLessThan($transaction, $productionGuard);
        self::assertStringContainsString('ALLOW_PRODUCTION_SIMULATION_SEEDER', $source);
        self::assertStringNotContainsString("'password' => 'password'", $source);
    }

    public function test_route_and_configuration_registries_boot_successfully(): void
    {
        self::assertSame(0, Artisan::call('route:list', ['--json' => true]));
        self::assertNotSame('', Artisan::output());
        self::assertIsArray(config('payment'));
        self::assertIsArray(config('finance'));
        self::assertIsArray(config('fraud'));
    }
}
