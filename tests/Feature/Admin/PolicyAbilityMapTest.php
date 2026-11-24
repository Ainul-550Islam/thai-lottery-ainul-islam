<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\BetStatus;
use App\Enums\UserStatus;
use App\Models\Bet;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Policies\BasePolicy;
use App\Policies\BetPolicy;
use App\Policies\LedgerPolicy;
use App\Policies\WalletPolicy;
use App\Support\Admin\AdminPermission;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The dot-ability bridge between the model policies and the permission catalogue.
 *
 * WHY THIS SUITE EXISTS
 *
 * Fifteen policies asked for abilities like 'wallet.viewAny'. Nothing was ever seeded under
 * that name, so the only policy checks that passed were super-admin ones (through the Gate
 * bypass). Fixing that means two promises, and this file is both:
 *
 *   1. COMPLETENESS — every policy method maps to a decision. A new policy method with no
 *      entry in config('admin.policy_abilities') is a failing test, not a silent
 *      deny-forever. The reverse is checked too, so the map cannot rot into a list of
 *      abilities nothing asks about.
 *   2. BEHAVIOUR — the decision is the RIGHT one: an operator holding the owning permission
 *      passes, a player does not, a suspended operator does not, ownership still works
 *      without any permission at all, and the 'never' abilities stay refused even for a
 *      super-admin who reaches the policy directly.
 *
 * WHAT IS DELIBERATELY NOT CHANGED
 *
 * AuthServiceProvider's Gate::before still short-circuits super-admin before any policy
 * runs — that is a pre-existing, deliberate rule and this suite pins it rather than
 * pretending to override it. The 'never' assertions therefore call the policy method
 * directly; at the Gate level a super-admin is still bypassed, exactly as before.
 */
final class PolicyAbilityMapTest extends TestCase
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
    // 1. Completeness
    // ------------------------------------------------------------------

    /**
     * Every "<prefix>.<method>" a policy can ask about, discovered by reflection.
     *
     * @return array<string, string> ability => policy class
     */
    private function policyAbilities(): array
    {
        $abilities = [];

        foreach (glob(app_path('Policies/*.php')) ?: [] as $file) {
            $class = 'App\\Policies\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(BasePolicy::class)) {
                continue;
            }

            $property = $reflection->getProperty('permissionPrefix');
            $property->setAccessible(true);
            $prefix = (string) $property->getValue($reflection->newInstance());

            if ($prefix === '') {
                continue; // the policy implements its own rule
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue; // inherited from BasePolicy, not an ability of this policy
                }

                $abilities[$prefix.'.'.$method->getName()] = $class;
            }
        }

        ksort($abilities);

        return $abilities;
    }

    public function test_the_scan_actually_finds_policy_abilities(): void
    {
        $abilities = $this->policyAbilities();

        $this->assertGreaterThan(20, count($abilities), 'the reflection scan must find the prefixed policies');
        $this->assertArrayHasKey('wallet.viewAny', $abilities);
        $this->assertArrayHasKey('draw.close', $abilities);
    }

    public function test_every_policy_ability_is_mapped_to_a_decision(): void
    {
        $map = (array) config('admin.policy_abilities', []);

        $unmapped = array_values(array_diff(array_keys($this->policyAbilities()), array_keys($map)));

        $this->assertSame(
            [],
            $unmapped,
            "these policy abilities have no decision in config('admin.policy_abilities'), so they deny everyone forever:\n  - "
            .implode("\n  - ", $unmapped),
        );
    }

    public function test_the_map_contains_no_ability_no_policy_asks_about(): void
    {
        $map = (array) config('admin.policy_abilities', []);

        $stale = array_values(array_diff(array_keys($map), array_keys($this->policyAbilities())));

        $this->assertSame(
            [],
            $stale,
            "these map entries reference abilities no policy declares (dead authorisation):\n  - "
            .implode("\n  - ", $stale),
        );
    }

    public function test_every_mapped_value_is_either_never_or_a_catalogued_permission(): void
    {
        foreach ((array) config('admin.policy_abilities', []) as $ability => $target) {
            if ($target === BasePolicy::NEVER) {
                continue;
            }

            $this->assertIsString($target, $ability.' must map to a permission name or "never"');
            $this->assertTrue(
                AdminPermission::exists($target),
                sprintf('[%s] maps to [%s], which is not in the granular catalogue.', $ability, $target),
            );
        }
    }

    public function test_the_write_abilities_with_no_policy_shortcut_are_marked_never(): void
    {
        // The list is asserted explicitly because these are the entries a reviewer must
        // agree with: each one is a write that belongs to a domain service.
        $map = (array) config('admin.policy_abilities', []);

        foreach ([
            'wallet.delete',
            'draw.delete',
            'ledger.create',
            'ledger.update',
            'ledger.delete',
            'ledger_account.create',
            'ledger_account.update',
            'ledger_account.delete',
            'payment.create',
            'payment.update',
            'payment.delete',
            'privacy-request.forceDelete',
        ] as $ability) {
            $this->assertSame(
                BasePolicy::NEVER,
                $map[$ability] ?? null,
                $ability.' must stay "never": that write belongs to a domain service, not a policy shortcut.',
            );
        }
    }

    // ------------------------------------------------------------------
    // 2. Behaviour at the Gate (the way the application asks)
    // ------------------------------------------------------------------

    public function test_an_operator_holding_the_owning_permission_passes_the_policy(): void
    {
        $admin = $this->operator('admin');

        $this->assertTrue(
            Gate::forUser($admin)->allows('viewAny', Wallet::class),
            'admin holds wallets.view, so wallet.viewAny must now pass',
        );
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', Bet::class));

        $analyst = $this->operator('read-only-analyst');

        $this->assertTrue(Gate::forUser($analyst)->allows('viewAny', Bet::class), 'bets.view is a read grant');
        $this->assertFalse(
            Gate::forUser($analyst)->allows('create', Bet::class),
            'bets.manage is not held by a read-only analyst',
        );
    }

    public function test_a_player_still_holds_no_operator_ability(): void
    {
        $player = $this->operator('player');

        $this->assertFalse(Gate::forUser($player)->allows('viewAny', Wallet::class));
        $this->assertFalse(Gate::forUser($player)->allows('viewAny', Bet::class));
        $this->assertFalse(Gate::forUser($player)->allows('create', Bet::class));
    }

    public function test_a_suspended_operator_passes_nothing(): void
    {
        $suspended = $this->operator('admin', UserStatus::Suspended);

        $this->assertFalse(Gate::forUser($suspended)->allows('viewAny', Wallet::class));
        $this->assertFalse(Gate::forUser($suspended)->allows('viewAny', Bet::class));
    }

    public function test_ownership_still_works_without_any_permission(): void
    {
        $player = $this->operator('player');

        $ownWallet = new Wallet;
        $ownWallet->forceFill(['user_id' => $player->getKey()]);

        $otherWallet = new Wallet;
        $otherWallet->forceFill(['user_id' => User::factory()->create()->getKey()]);

        $this->assertTrue(
            Gate::forUser($player)->allows('view', $ownWallet),
            'a player must still read their own wallet without holding an operator permission',
        );
        $this->assertFalse(
            Gate::forUser($player)->allows('view', $otherWallet),
            'ownership must not leak across players',
        );
    }

    public function test_the_super_admin_bypass_is_unchanged(): void
    {
        $super = $this->operator('super-admin');

        $this->assertTrue(Gate::forUser($super)->allows('viewAny', Wallet::class));
        $this->assertTrue(Gate::forUser($super)->allows('create', Bet::class));
    }

    public function test_a_kyc_officer_passes_no_financial_policy_even_though_the_panel_admits_them(): void
    {
        $officer = $this->operator('kyc-officer');

        $this->assertFalse(Gate::forUser($officer)->allows('viewAny', Wallet::class));
        $this->assertFalse(Gate::forUser($officer)->allows('viewAny', Bet::class));
    }

    // ------------------------------------------------------------------
    // 3. The 'never' abilities, at the policy level
    // ------------------------------------------------------------------

    public function test_a_never_ability_is_refused_by_the_policy_even_for_a_super_admin(): void
    {
        $super = $this->operator('super-admin');

        // Called on the policy directly: Gate::before would short-circuit a super-admin
        // before any policy runs, and that pre-existing rule is not being changed here.
        $this->assertFalse(
            (new LedgerPolicy)->create($super),
            'writing a ledger entry by hand is not a policy-granted power',
        );
        $this->assertFalse((new LedgerPolicy)->delete($super, new LedgerEntry));
        $this->assertFalse(
            (new WalletPolicy)->delete($super, new Wallet),
            'wallets are closed, never deleted',
        );
    }

    public function test_an_explicit_dot_grant_is_still_honoured_first(): void
    {
        // Back-compatibility: a deployment that DID seed the dot vocabulary keeps working.
        $analyst = $this->operator('read-only-analyst');

        \Spatie\Permission\Models\Permission::findOrCreate('bet.view', 'web');
        $analyst->givePermissionTo('bet.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(
            (new BetPolicy)->view($analyst->fresh(), new Bet),
            'an explicitly granted dot ability must not be ignored by the bridge',
        );
    }

    public function test_a_bet_status_fixture_is_a_real_enum_case(): void
    {
        // Keeps the import honest: the suite must not drift into guessing state strings.
        $this->assertNotEmpty(BetStatus::cases());
        $this->assertTrue(class_exists(LedgerEntry::class));
    }
}
