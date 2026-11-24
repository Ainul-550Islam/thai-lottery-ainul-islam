<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Filament\Pages\FinancialReconciliationPage;
use App\Filament\Pages\GloPrizeClaimPage;
use App\Filament\Pages\GloTicketFreezePage;
use App\Filament\Pages\KycReviewPage;
use App\Filament\Resources\AgentResource;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\BetResource;
use App\Filament\Resources\DepositResource;
use App\Filament\Resources\DepositSlipVerificationResource;
use App\Filament\Resources\DrawResource;
use App\Filament\Resources\FinancialTransactionResource;
use App\Filament\Resources\LedgerAccountResource;
use App\Filament\Resources\NumberLimitResource;
use App\Filament\Resources\TicketResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WalletResource;
use App\Filament\Resources\WithdrawalResource;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The screen migration: every panel surface now asks the GRANULAR question.
 *
 * WHY THIS SUITE EXISTS
 *
 * `admin.granular_rbac.enforce` is the switch that makes a granular grant the only thing that
 * counts. It could not be turned on while screens still asked for coarse phrases — flipping it
 * would simply have emptied the panel, because the coarse phrases are what those screens read.
 *
 * So the migration had to happen first, screen by screen, and this file is the evidence that it
 * did. It proves three separate things, and the third is the one that matters:
 *
 *   1. THE BRIDGE STILL WORKS. A deployment that has not run `AdminRbacSeeder` (or a role
 *      composed only of coarse phrases) keeps its access while `enforce` is off. Nothing broke.
 *   2. THE GRANULAR GRANTS ARE SUFFICIENT. With `enforce` ON, the seeded roles — which carry the
 *      granular matrix — reach every migrated screen.
 *   3. THE COARSE PHRASE STOPS COUNTING. With `enforce` ON, 'manage users' no longer opens the
 *      user screen, and 'manage wallet' — a phrase every PLAYER holds — no longer opens the
 *      wallet list. That is the leak the granular layer exists to close.
 *
 * WHAT THIS FILE DOES NOT CLAIM
 *
 * The switch ships OFF. Turning it on in production is an operator's decision (env
 * `ADMIN_GRANULAR_RBAC_ENFORCE`) that also requires `php artisan db:seed --class=AdminRbacSeeder`
 * to have run — a deployment order, not a code change. What is proven here is that when it is
 * turned on, the panel still works for the roles it is supposed to serve, and the coarse
 * vocabulary stops granting anything.
 */
final class GranularScreenMigrationTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = DB::connection()->getDatabaseName();

        $this->assertContains(
            basename((string) $database),
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedBoth(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminRbacSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function enforce(bool $on): void
    {
        config(['admin.granular_rbac.enforce' => $on]);
    }

    /**
     * A panel operator who holds only COARSE phrases.
     *
     * The role is 'auditor' — a real panel role with no granular grants in this fixture — because
     * being an operator at all is now a precondition of every granular check. Stripping the role
     * instead would test a different question ("is a non-operator refused?"), which is what
     * test_a_player_holding_coarse_phrases_never_reaches_an_operator_screen covers.
     */
    private function userWithCoarseOnly(string ...$permissions): User
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->assignRole('auditor');

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    /**
     * Every screen the migration touched, as a callable returning "may this operator open it".
     *
     * @return array<string, callable(): bool>
     */
    private function migratedScreens(): array
    {
        return [
            'users' => static fn (): bool => UserResource::canViewAny(),
            'deposits' => static fn (): bool => DepositResource::canViewAny(),
            'withdrawals' => static fn (): bool => WithdrawalResource::canViewAny(),
            'deposit slips' => static fn (): bool => DepositSlipVerificationResource::canViewAny(),
            'wallets' => static fn (): bool => WalletResource::canViewAny(),
            'ledger accounts' => static fn (): bool => LedgerAccountResource::canViewAny(),
            'financial transactions' => static fn (): bool => FinancialTransactionResource::canViewAny(),
            'audit trail' => static fn (): bool => AuditLogResource::canViewAny(),
            'draws' => static fn (): bool => DrawResource::canViewAny(),
            'bets' => static fn (): bool => BetResource::canViewAny(),
            'tickets' => static fn (): bool => TicketResource::canViewAny(),
            'number limits' => static fn (): bool => NumberLimitResource::canViewAny(),
            'agents' => static fn (): bool => AgentResource::canViewAny(),
            'kyc review page' => static fn (): bool => KycReviewPage::canAccess(),
            'reconciliation page' => static fn (): bool => FinancialReconciliationPage::canAccess(),
            'glo claims page' => static fn (): bool => GloPrizeClaimPage::canAccess(),
            'glo freezes page' => static fn (): bool => GloTicketFreezePage::canAccess(),
        ];
    }

    // ------------------------------------------------------------- the bridge

    public function test_a_coarse_only_operator_keeps_working_while_the_bridge_is_on(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->enforce(false);

        $this->userWithCoarseOnly('view dashboard', 'manage users', 'manage payouts');

        $this->assertTrue(UserResource::canViewAny(), 'the coarse phrase must still open the user screen while the bridge is on');
        $this->assertTrue(DepositResource::canViewAny());
        $this->assertTrue(WithdrawalResource::canViewAny());
    }

    // ------------------------------------------------- the granular grants suffice

    public function test_the_seeded_admin_role_reaches_every_migrated_screen_with_enforcement_on(): void
    {
        $this->seedBoth();
        $this->enforce(true);

        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->assignRole('admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user->fresh());

        $denied = [];

        foreach ($this->migratedScreens() as $label => $check) {
            if ($check() !== true) {
                $denied[] = $label;
            }
        }

        $this->assertSame(
            [],
            $denied,
            'With enforcement on, the seeded admin role must still reach these screens: '.implode(', ', $denied),
        );
    }

    // ------------------------------------------------- the coarse phrase stops counting

    public function test_with_enforcement_on_a_coarse_phrase_alone_grants_nothing(): void
    {
        // Only the coarse seeder runs here: AdminRbacSeeder would grant the auditor role its
        // granular matrix, and the question being asked is precisely what happens WITHOUT a
        // granular grant.
        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->enforce(true);

        $this->userWithCoarseOnly('view dashboard', 'manage users', 'manage payouts', 'manage wallet');

        $this->assertFalse(
            UserResource::canViewAny(),
            "'manage users' may not open the user screen once granular enforcement is on",
        );
        $this->assertFalse(DepositResource::canViewAny());
        $this->assertFalse(WithdrawalResource::canViewAny());

        // A granular grant is then the only thing that works.
        $operator = $this->userWithCoarseOnly('view dashboard');
        $operator->givePermissionTo(Permission::findOrCreate(AdminPermission::USERS_VIEW, 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($operator->fresh());

        $this->assertTrue(UserResource::canViewAny());
    }

    public function test_a_player_holding_coarse_phrases_never_reaches_an_operator_screen(): void
    {
        $this->seedBoth();

        // 'manage wallet' and 'view transaction history' are phrases every PLAYER carries, for
        // their own wallet screen. They must never be a route into the operator panel.
        $player = User::factory()->create(['status' => UserStatus::Active]);
        $player->assignRole('player');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($player->fresh());

        $this->assertFalse(AdminAccess::canAccessPanel($player->fresh()));

        foreach ([false, true] as $enforcement) {
            $this->enforce($enforcement);

            $this->assertFalse(WalletResource::canViewAny(), 'enforce='.var_export($enforcement, true));
            $this->assertFalse(DepositResource::canViewAny(), 'enforce='.var_export($enforcement, true));
            $this->assertFalse(FinancialTransactionResource::canViewAny(), 'enforce='.var_export($enforcement, true));
        }
    }

    // ------------------------------------------------------- GLO duty separation

    public function test_glo_duties_stay_separate_under_enforcement(): void
    {
        $this->seedBoth();
        $this->enforce(true);

        // 'admin' carries the claim/freeze/dealer duties but NOT payment execution or public
        // status publication — the two the RolePermissionSeeder withholds from it.
        $admin = User::factory()->create(['status' => UserStatus::Active]);
        $admin->assignRole('admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($admin->fresh());

        $this->assertTrue(GloPrizeClaimPage::canAccess(), 'the claim desk must be reachable');
        $this->assertTrue(AdminAccess::permits(AdminPermission::GLO_CLAIMS_MANAGE));
        $this->assertFalse(
            AdminAccess::permits(AdminPermission::GLO_CLAIMS_PAY),
            'working a claim must not imply executing its payment',
        );
        $this->assertFalse(
            AdminAccess::permits(AdminPermission::GLO_STATUS_PUBLISH),
            'publishing the public status is a separate duty',
        );
    }

    // --------------------------------------------------- the catalogue coherence

    public function test_every_granular_permission_the_screens_ask_for_exists_in_the_catalogue(): void
    {
        $this->seedBoth();

        $catalogue = AdminPermission::all();
        $missing = [];

        // Read every `AdminPermission::X` constant the panel actually references and check it
        // resolves to a catalogued name. A screen asking for an uncatalogued permission is
        // denied forever (permits() refuses unknown names), which is safe but invisible —
        // so it is asserted here instead of being discovered by an operator.
        $constants = (new \ReflectionClass(AdminPermission::class))->getConstants();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Filament')));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/AdminPermission::([A-Z_]+)/', $source, $matches) !== false) {
                foreach (array_unique($matches[1]) as $constant) {
                    $value = $constants[$constant] ?? null;

                    if (! is_string($value) || ! in_array($value, $catalogue, true)) {
                        $missing[] = $constant.' (asked for by '.$file->getFilename().')';
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'uncatalogued permission references');
    }
}
