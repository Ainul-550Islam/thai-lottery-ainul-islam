<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Filament\Resources\DepositResource;
use App\Filament\Resources\RoleResource;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The granular permission layer, and the bridge that keeps today's roles working.
 *
 * WHY THIS SUITE EXISTS
 *
 * Adding a second permission vocabulary is the kind of change that fails silently in both
 * directions: too weak and every specialised operator is locked out of the screen they
 * were hired for; too strong and 'manage payouts' quietly grants 'withdrawals.execute' to
 * a support agent. The three properties below are what make the layer trustworthy:
 *
 *   1. THE CATALOGUE AND THE CONSTANTS AGREE — every constant in AdminPermission is in
 *      config/admin.php and vice versa. A drift here is a permission that can never be
 *      granted (typo) or a checkbox that grants nothing.
 *   2. THE BRIDGE IS ONE-DIRECTIONAL AND SWITCHABLE — while the fallback is on, the coarse
 *      phrase still authorises; the moment `admin.granular_rbac.enforce` is switched on,
 *      only the granular grant counts. That is proven here, not assumed.
 *   3. LEAST PRIVILEGE IS REAL — a seeded KYC officer can open the panel and review
 *      documents, and cannot touch the deposit queue, because the deposit screen still
 *      asks the coarse question and the KYC officer does not hold it.
 */
final class GranularRbacTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = \DB::connection()->getDatabaseName();

        $this->assertContains(
            basename((string) $database),
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminRbacSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function operator(string $role, UserStatus $status = UserStatus::Active): User
    {
        $user = User::factory()->create(['status' => $status]);
        $user->assignRole($role);

        return $user->fresh();
    }

    // ------------------------------------------------------------------
    // The catalogue is the whitelist
    // ------------------------------------------------------------------

    public function test_the_constant_catalogue_and_the_config_catalogue_are_the_same_set(): void
    {
        $fromConfig = [];

        foreach (AdminPermission::groups() as $permissions) {
            foreach (array_keys($permissions) as $name) {
                $fromConfig[] = $name;
            }
        }

        sort($fromConfig);

        $fromConstants = AdminPermission::all();
        sort($fromConstants);

        $this->assertSame(
            $fromConfig,
            $fromConstants,
            'A permission in config/admin.php with no constant (or the reverse) can never be granted safely.',
        );
    }

    public function test_every_granular_permission_maps_to_a_seeded_coarse_phrase_or_explicitly_to_none(): void
    {
        foreach (AdminPermission::all() as $permission) {
            $legacy = AdminPermission::legacyEquivalent($permission);

            if ($legacy === null) {
                continue;
            }

            $this->assertContains(
                $legacy,
                (array) config('permission.permissions', []),
                sprintf('[%s] falls back to [%s], which is not in the coarse catalogue.', $permission, $legacy),
            );
        }
    }

    public function test_the_role_matrix_only_names_catalogued_permissions(): void
    {
        foreach (AdminPermission::roleMatrix() as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertTrue(
                    AdminPermission::exists($permission),
                    sprintf('The matrix grants [%s] to [%s], but it is not in the catalogue.', $permission, $role),
                );
            }
        }
    }

    public function test_the_seeder_creates_every_declared_role_and_grants_panel_entry(): void
    {
        foreach (array_keys((array) config('admin.roles', [])) as $roleName) {
            $role = Role::findByName((string) $roleName, 'web');

            $this->assertTrue(
                $role->hasPermissionTo(AdminAccess::VIEW_DASHBOARD),
                sprintf('[%s] cannot reach the panel: it lacks "view dashboard".', $roleName),
            );
        }
    }

    // ------------------------------------------------------------------
    // The bridge
    // ------------------------------------------------------------------

    public function test_the_legacy_fallback_still_authorises_the_seeded_admin(): void
    {
        $admin = $this->operator('admin');

        $this->assertTrue(AdminAccess::permits(AdminPermission::USERS_SUSPEND, $admin));
        $this->assertTrue(AdminAccess::permits(AdminPermission::DEPOSITS_APPROVE, $admin));
    }

    public function test_the_fallback_can_be_switched_off_without_touching_code(): void
    {
        $admin = $this->operator('admin');

        // admin's granular matrix has no withdrawals.execute; the coarse phrase it would
        // fall back to is 'manage payouts', which admin does hold.
        $this->assertTrue(AdminAccess::permits(AdminPermission::WITHDRAWALS_EXECUTE, $admin));

        config()->set('admin.granular_rbac.enforce', true);

        $this->assertFalse(
            AdminAccess::permits(AdminPermission::WITHDRAWALS_EXECUTE, $admin),
            'With enforcement on, only a granular grant may authorise a granular check.',
        );

        // And a granular grant still works under enforcement.
        $this->assertTrue(AdminAccess::permits(AdminPermission::DEPOSITS_APPROVE, $admin));
    }

    public function test_an_unknown_permission_is_never_honoured_even_for_super_admin(): void
    {
        $superAdmin = $this->operator('super-admin');

        $this->assertTrue(AdminAccess::permits(AdminPermission::SETTINGS_MANAGE, $superAdmin));
        $this->assertFalse(AdminAccess::permits('users.destroy-everything', $superAdmin));
    }

    public function test_a_suspended_operator_holds_nothing(): void
    {
        $suspended = $this->operator('super-admin', UserStatus::Suspended);

        $this->assertFalse(AdminAccess::permits(AdminPermission::USERS_VIEW, $suspended));
        $this->assertFalse(AdminAccess::canAccessPanel($suspended));
    }

    // ------------------------------------------------------------------
    // Least privilege, measured
    // ------------------------------------------------------------------

    public function test_the_kyc_officer_can_open_the_panel_and_review_documents_but_not_touch_money(): void
    {
        $officer = $this->operator('kyc-officer');

        $this->assertTrue(AdminAccess::canAccessPanel($officer));
        $this->assertTrue(AdminAccess::permits(AdminPermission::KYC_APPROVE, $officer));
        $this->assertFalse(AdminAccess::permits(AdminPermission::WITHDRAWALS_APPROVE, $officer));
        $this->assertFalse(AdminAccess::permits(AdminPermission::WALLETS_ADJUST, $officer));
        $this->assertFalse(AdminAccess::permits(AdminPermission::SETTINGS_MANAGE, $officer));

        // The existing deposit screen asks the coarse question; a KYC officer does not hold
        // it, so least privilege holds at the screen boundary too.
        $this->actingAs($officer);

        $this->assertFalse(DepositResource::canViewAny());
    }

    public function test_the_read_only_analyst_may_read_the_panel_and_write_nothing(): void
    {
        $analyst = $this->operator('read-only-analyst');
        $this->actingAs($analyst);

        $this->assertTrue(AdminAccess::canAccessPanel($analyst));
        $this->assertTrue(RoleResource::canViewAny());
        $this->assertFalse(RoleResource::canCreate(), 'a read-only analyst must not compose roles');
        $this->assertFalse(AdminAccess::current(AdminAccess::MANAGE_PAYOUTS));
    }

    // ------------------------------------------------------------------
    // Anti-escalation
    // ------------------------------------------------------------------

    public function test_an_operator_cannot_grant_a_permission_they_do_not_hold(): void
    {
        $officer = $this->operator('kyc-officer');

        $this->assertTrue(AdminAccess::mayGrant(AdminPermission::KYC_REVIEW, $officer));
        $this->assertFalse(AdminAccess::mayGrant(AdminPermission::WITHDRAWALS_EXECUTE, $officer));
        $this->assertFalse(AdminAccess::mayGrant(AdminPermission::SETTINGS_MANAGE, $officer));
    }

    public function test_the_grantable_catalogue_shrinks_to_the_operators_own_authority(): void
    {
        $officer = $this->operator('kyc-officer');
        $superAdmin = $this->operator('super-admin');

        $officerGrants = AdminAccess::grantable($officer);
        $superGrants = AdminAccess::grantable($superAdmin);

        $this->assertContains(AdminPermission::KYC_VIEW, $officerGrants);
        $this->assertNotContains(AdminPermission::WITHDRAWALS_EXECUTE, $officerGrants);

        sort($superGrants);
        $catalogue = AdminPermission::all();
        sort($catalogue);

        $this->assertSame($catalogue, $superGrants, 'super-admin may grant the whole catalogue');
    }

    public function test_a_guest_holds_no_granular_permission(): void
    {
        auth()->logout();

        foreach (AdminPermission::all() as $permission) {
            $this->assertFalse(AdminAccess::permits($permission), 'a guest must not hold '.$permission);
        }

        $this->assertFalse(AdminAccess::mayGrant(AdminPermission::USERS_VIEW));
        $this->assertSame([], AdminAccess::grantable());
    }
}
