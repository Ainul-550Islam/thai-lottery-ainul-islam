<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AuditAction;
use App\Enums\UserStatus;
use App\Exceptions\RoleAdministrationException;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\RoleResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\RoleAdministrationService;
use App\Support\Admin\AdminPermission;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The role manager's server-side guarantees.
 *
 * WHY THE TESTS DRIVE THE SERVICE AND NOT THE FORM
 *
 * A Filament checkbox list is a suggestion. The dangerous calls arrive from somewhere
 * else: a stale tab, a forged Livewire payload, a future console command. Every rule that
 * matters therefore lives in RoleAdministrationService, and this suite calls it directly,
 * so the refusals are proven at the layer that actually enforces them.
 *
 * THE FOUR RULES UNDER TEST
 *
 *   1. Protected roles (super-admin, admin, auditor) are super-admin-only.
 *   2. Nobody may grant a permission they do not hold themselves.
 *   3. Nobody may remove from a role they hold the permission that lets them manage roles.
 *   4. A role in use cannot be deleted.
 *
 * Plus the regression that would silently break the whole panel: syncing granular
 * permissions must NOT touch the coarse phrases the existing screens authorise against.
 */
final class RoleAdministrationTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    private RoleAdministrationService $service;

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

        $this->service = app(RoleAdministrationService::class);
    }

    private function operator(string $role): User
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->assignRole($role);
        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    /**
     * An operator who may manage roles but only within their own authority.
     */
    private function roleManager(): User
    {
        $user = $this->operator('read-only-analyst');

        $role = Role::findByName('read-only-analyst', 'web');
        $role->givePermissionTo(Permission::findOrCreate(AdminPermission::ROLES_MANAGE, 'web'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * Assert that an action is refused AND why.
     *
     * RoleAdministrationException carries its identifier in a string property rather than
     * PHPUnit's int exception code, because a refusal is named, not numbered. Checking the
     * name matters: "it threw something" would pass for a typo in the guard just as easily
     * as for the guard doing its job.
     */
    private function assertRefused(string $expectedCode, callable $action): void
    {
        try {
            $action();
        } catch (RoleAdministrationException $e) {
            $this->assertSame($expectedCode, $e->refusalCode(), 'refused for the wrong reason: '.$e->getMessage());

            return;
        }

        $this->fail('Expected refusal ['.$expectedCode.'] was not thrown.');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_super_admin_can_create_a_role_with_granular_grants_and_it_is_audited(): void
    {
        $actor = $this->operator('super-admin');

        $role = $this->service->create('night-desk', [
            AdminPermission::DEPOSITS_VIEW,
            AdminPermission::DEPOSITS_APPROVE,
        ], $actor);

        $this->assertSame('night-desk', $role->name);
        $this->assertTrue($role->hasPermissionTo(AdminPermission::DEPOSITS_APPROVE));
        $this->assertTrue($role->hasPermissionTo(\App\Support\Admin\AdminAccess::VIEW_DASHBOARD), 'a new role must be able to reach the panel');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $actor->getKey(),
            'action' => AuditAction::Create->value,
            'description' => 'Created administrative role [night-desk]',
        ]);
    }

    public function test_a_role_manager_cannot_create_a_role_carrying_a_permission_they_lack(): void
    {
        $this->roleManager();

        $this->assertRefused(
            RoleAdministrationException::CODE_ESCALATION,
            fn () => $this->service->create('shadow-finance', [AdminPermission::WITHDRAWALS_EXECUTE]),
        );
    }

    public function test_a_role_name_must_be_a_slug(): void
    {
        $this->operator('super-admin');

        $this->assertRefused(
            RoleAdministrationException::CODE_MALFORMED,
            fn () => $this->service->create('Night Desk!', []),
        );
    }

    public function test_a_reserved_role_name_cannot_be_created(): void
    {
        $this->operator('super-admin');

        $this->assertRefused(
            RoleAdministrationException::CODE_MALFORMED,
            fn () => $this->service->create('admin', []),
        );
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_granular_sync_never_touches_the_coarse_phrases(): void
    {
        $actor = $this->operator('super-admin');

        $role = Role::findByName('deposit-manager', 'web');

        $this->assertTrue($role->hasPermissionTo('view dashboard'), 'precondition: coarse grant from the seeder');

        $this->service->syncGranularPermissions($role, [AdminPermission::DEPOSITS_VIEW], $actor);

        $role->refresh()->load('permissions');

        $this->assertTrue(
            $role->hasPermissionTo('view dashboard'),
            'syncing granular grants must not strip the coarse vocabulary the screens use',
        );
        $this->assertFalse($role->hasPermissionTo(AdminPermission::DEPOSITS_APPROVE), 'the revoked grant is gone');
        $this->assertTrue($role->hasPermissionTo(AdminPermission::DEPOSITS_VIEW));
    }

    public function test_a_grant_change_is_audited_with_before_and_after(): void
    {
        $actor = $this->operator('super-admin');

        $role = Role::findByName('deposit-manager', 'web');

        // deposit-manager does NOT hold audit_logs.view: granting it is a real change, so
        // it must appear in both the before/after snapshot and the diff.
        $this->service->syncGranularPermissions($role, [
            AdminPermission::DEPOSITS_VIEW,
            AdminPermission::DEPOSITS_APPROVE,
            AdminPermission::AUDIT_LOGS_VIEW,
        ], $actor);

        $log = AuditLog::query()
            ->where('action', AuditAction::ConfigChange)
            ->where('auditable_type', Role::class)
            ->where('auditable_id', $role->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertContains(AdminPermission::AUDIT_LOGS_VIEW, (array) ($log->new_values['granular_permissions'] ?? []));
        $this->assertContains(AdminPermission::AUDIT_LOGS_VIEW, (array) ($log->metadata['granted'] ?? []));
    }

    public function test_the_admin_role_is_protected_from_a_non_super_admin(): void
    {
        $manager = $this->roleManager();

        $this->assertTrue($this->service->mayEdit(Role::findByName('read-only-analyst', 'web'), $manager));
        $this->assertFalse($this->service->mayEdit(Role::findByName('admin', 'web'), $manager));

        $this->assertRefused(
            RoleAdministrationException::CODE_PROTECTED,
            fn () => $this->service->syncGranularPermissions(Role::findByName('admin', 'web'), [], $manager),
        );
    }

    public function test_super_admin_may_edit_a_protected_role(): void
    {
        $actor = $this->operator('super-admin');

        $role = Role::findByName('auditor', 'web');

        $this->service->syncGranularPermissions($role, [
            AdminPermission::USERS_VIEW,
            AdminPermission::REPORTS_VIEW,
        ], $actor);

        $this->assertTrue($role->fresh()->hasPermissionTo(AdminPermission::REPORTS_VIEW));
    }

    public function test_an_operator_cannot_strip_their_own_ability_to_manage_roles(): void
    {
        $manager = $this->roleManager();

        $role = Role::findByName('read-only-analyst', 'web');

        // Removing roles.manage from a role the actor holds is the self-lockout.
        $this->assertRefused(
            RoleAdministrationException::CODE_SELF_LOCKOUT,
            fn () => $this->service->syncGranularPermissions($role, [AdminPermission::USERS_VIEW], $manager),
        );
    }

    public function test_renaming_a_protected_role_is_refused_for_a_non_super_admin(): void
    {
        $manager = $this->roleManager();

        $this->assertRefused(
            RoleAdministrationException::CODE_PROTECTED,
            fn () => $this->service->rename(Role::findByName('auditor', 'web'), 'auditor-2', $manager),
        );
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $actor = $this->operator('super-admin');

        $holder = User::factory()->create(['status' => UserStatus::Active]);
        $holder->assignRole('deposit-manager');

        $this->assertRefused(
            RoleAdministrationException::CODE_IN_USE,
            fn () => $this->service->delete(Role::findByName('deposit-manager', 'web'), $actor),
        );
    }

    public function test_an_unused_role_can_be_deleted_and_the_deletion_is_audited(): void
    {
        $actor = $this->operator('super-admin');

        $role = $this->service->create('temporary-desk', [AdminPermission::REPORTS_VIEW], $actor);

        $this->service->delete($role, $actor);

        $this->assertDatabaseMissing('roles', ['name' => 'temporary-desk']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::Delete->value,
            'description' => 'Deleted administrative role [temporary-desk]',
        ]);
    }

    public function test_a_protected_role_is_never_deletable_even_by_super_admin(): void
    {
        $actor = $this->operator('super-admin');

        $this->assertFalse($this->service->mayDelete(Role::findByName('admin', 'web'), $actor));
    }

    // ------------------------------------------------------------------
    // The screens
    // ------------------------------------------------------------------

    public function test_the_role_screen_is_read_only_without_roles_manage(): void
    {
        $analyst = $this->operator('read-only-analyst');

        $this->actingAs($analyst);

        $this->assertTrue(RoleResource::canViewAny(), 'roles.view is enough to look');
        $this->assertFalse(RoleResource::canCreate());
        $this->assertFalse(RoleResource::canDeleteAny());

        $this->assertFalse(PermissionResource::canCreate(), 'the permission catalogue is never editable');
        $this->assertFalse(PermissionResource::canDeleteAny());
    }

    public function test_the_role_screens_actually_render_for_a_super_admin(): void
    {
        $this->operator('super-admin');

        $role = \Spatie\Permission\Models\Role::findByName('deposit-manager', 'web');

        $this->get('/admin/roles')->assertSuccessful()->assertSee('Roles');
        $this->get('/admin/roles/create')->assertSuccessful();
        $this->get('/admin/roles/'.$role->getKey().'/edit')->assertSuccessful();
        $this->get('/admin/permissions')->assertSuccessful();

        // The KYC officer may not even reach the URL: authorisation is server-side, not a
        // hidden menu item.
        $this->actingAs($this->operator('kyc-officer'));

        $this->get('/admin/roles')->assertForbidden();
    }

    public function test_the_role_screen_is_closed_to_an_operator_without_roles_view(): void
    {
        $officer = $this->operator('kyc-officer');

        $this->actingAs($officer);

        $this->assertFalse(RoleResource::canViewAny());
        $this->assertFalse(PermissionResource::canViewAny());
    }
}
