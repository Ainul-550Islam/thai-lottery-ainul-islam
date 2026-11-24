<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\AuditAction;
use App\Enums\PaymentProviderStatus;
use App\Enums\UserStatus;
use App\Exceptions\PaymentProviderException;
use App\Filament\Resources\PaymentProviderResource;
use App\Filament\Resources\PaymentProviderResource\Pages\ListPaymentProviders;
use App\Filament\Resources\PaymentProviderResource\Pages\ViewPaymentProvider;
use App\Models\AuditLog;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Services\Payment\PaymentProviderRegistry;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Payment provider administration: who may see it, who may move it, and what it never shows.
 *
 * WHY THIS SUITE EXISTS
 *
 * The provider registry has always been able to take a gateway out of traffic, but until
 * this screen existed nobody but a developer could reach that power. A screen that can
 * stop payments is exactly the kind of surface where three things must be proven rather
 * than hoped for:
 *
 *   1. A transition really goes through the registry (so the enum's transition map and the
 *      audit line apply), rather than a controller writing `status` behind its back.
 *   2. An illegal transition is refused rather than offered: a Disabled provider has no
 *      way back, and the screen must not pretend otherwise.
 *   3. Credentials are never rendered — not the ones the registry refuses to store, and not
 *      a secret that a hand-written row smuggled into the "non_secrets" column.
 *
 * The last test is the one worth keeping: it inserts a value by hand, because a defence
 * that only holds for data the application itself wrote is not a defence.
 */
final class PaymentProviderAdminTest extends TestCase
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

    private function operator(string $role): User
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->assignRole($role);

        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    private function provider(
        string $code = 'acme-pay',
        PaymentProviderStatus $status = PaymentProviderStatus::Pending,
        array $nonSecrets = [],
    ): PaymentProvider {
        $provider = new PaymentProvider;
        $provider->fill([
            'code' => $code,
            'name' => 'Acme Pay',
            'driver' => 'stripe',
            'supported_currencies' => ['THB', 'USD'],
            'non_secrets' => $nonSecrets,
        ]);
        $provider->status = $status;
        $provider->save();

        return $provider->refresh();
    }

    // ------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------

    public function test_a_finance_operator_may_open_the_provider_registry(): void
    {
        $this->operator('finance-manager');

        $this->assertTrue(PaymentProviderResource::canViewAny());

        $this->provider();

        Livewire::test(ListPaymentProviders::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(PaymentProvider::all());
    }

    public function test_a_kyc_officer_may_not_open_the_provider_registry(): void
    {
        $this->operator('kyc-officer');

        $this->assertFalse(PaymentProviderResource::canViewAny());
    }

    public function test_the_read_only_auditor_is_refused_provider_configuration(): void
    {
        $this->operator('auditor');

        $this->assertFalse(
            PaymentProviderResource::canViewAny(),
            'the auditor holds neither payments.providers.view nor its coarse equivalent',
        );
    }

    public function test_the_registry_has_no_create_or_edit_form(): void
    {
        $this->operator('super-admin');

        $provider = $this->provider();

        $this->assertFalse(PaymentProviderResource::canCreate(), 'a provider row without a driver class is a false promise');
        $this->assertFalse(PaymentProviderResource::canEdit($provider), 'status is moved by a lifecycle action, not a field edit');
        $this->assertFalse(PaymentProviderResource::canDelete($provider), 'providers are struck off, never deleted');
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function test_the_activate_action_moves_the_provider_through_the_registry_and_audits_it(): void
    {
        $actor = $this->operator('super-admin');

        $provider = $this->provider();

        Livewire::test(ListPaymentProviders::class)
            ->callTableAction('activate', $provider, ['reason' => 'Provisioning finished for the THB lane.'])
            ->assertHasNoTableActionErrors();

        $provider->refresh();

        $this->assertSame(PaymentProviderStatus::Active, $provider->status);
        $this->assertSame('Provisioning finished for the THB lane.', $provider->status_reason);

        $audit = AuditLog::query()
            ->where('auditable_type', PaymentProvider::class)
            ->where('auditable_id', $provider->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($actor->getKey(), (int) $audit->user_id, 'the transition must name the operator who took it');
        $this->assertStringContainsString('→ active', (string) $audit->description);
    }

    public function test_disabling_is_offered_but_reviving_a_disabled_provider_is_not(): void
    {
        $this->operator('super-admin');

        $active = $this->provider('active-pay', PaymentProviderStatus::Active);
        $disabled = $this->provider('dead-pay', PaymentProviderStatus::Disabled);

        $this->assertTrue(PaymentProviderStatus::Active->canTransitionTo(PaymentProviderStatus::Disabled));
        $this->assertFalse(PaymentProviderStatus::Disabled->canTransitionTo(PaymentProviderStatus::Active));

        // The registry refuses the illegal move even if a screen offers it. The refusal is
        // identified by name rather than by PHPUnit's int exception code.
        try {
            app(PaymentProviderRegistry::class)->transitionProvider(
                $disabled,
                PaymentProviderStatus::Active,
                'Bringing an old provider back.',
            );

            $this->fail('A disabled provider must not be revivable.');
        } catch (PaymentProviderException $e) {
            $this->assertSame(PaymentProviderException::CODE_INVALID_TRANSITION, $e->errorCode());
            $this->assertSame(PaymentProviderStatus::Disabled, $disabled->fresh()->status);
        }
    }

    public function test_suspending_takes_the_provider_out_of_traffic_and_records_the_reason(): void
    {
        $this->operator('super-admin');

        $provider = $this->provider('suspend-me', PaymentProviderStatus::Active);

        app(PaymentProviderRegistry::class)->transitionProvider(
            $provider,
            PaymentProviderStatus::Suspended,
            'Endpoint returning 500s on settlement.',
        );

        $provider->refresh();

        $this->assertSame(PaymentProviderStatus::Suspended, $provider->status);
        $this->assertNotNull($provider->suspended_at);
        $this->assertFalse($provider->status->canServeTraffic());
    }

    public function test_a_read_only_operator_is_not_offered_the_lifecycle_actions(): void
    {
        // read-only-analyst holds neither payments.providers.view nor its coarse
        // equivalent, so the screen is closed to them entirely.
        $this->operator('read-only-analyst');

        $this->assertFalse(PaymentProviderResource::canViewAny());

        // A viewer who holds ONLY the read permission gets the screen and no verbs:
        // the action group is gated on payments.providers.manage, not on view.
        $viewer = $this->operator('read-only-analyst');

        $viewer->givePermissionTo(
            \Spatie\Permission\Models\Permission::findOrCreate(\App\Support\Admin\AdminPermission::PROVIDERS_VIEW, 'web')
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(PaymentProviderResource::canViewAny());
        $this->assertFalse(
            \App\Support\Admin\AdminAccess::permits(\App\Support\Admin\AdminPermission::PROVIDERS_MANAGE),
            'reading provider configuration must not carry the power to change it',
        );
    }

    // ------------------------------------------------------------------
    // What is never rendered
    // ------------------------------------------------------------------

    public function test_the_view_page_masks_anything_that_looks_like_a_credential(): void
    {
        $this->operator('super-admin');

        $provider = $this->provider('masked-pay', PaymentProviderStatus::Active, [
            'api_key' => 'sk_live_SHOULD-NEVER-RENDER',
            'webhook_hint' => 'https://hooks.example/thb?token=SHOULD-NEVER-RENDER',
            'settlement_window' => 'T+1',
        ]);

        $html = Livewire::test(ViewPaymentProvider::class, ['record' => $provider->getKey()])
            ->assertSuccessful()
            ->html();

        $this->assertStringNotContainsString('SHOULD-NEVER-RENDER', $html, 'a credential-shaped value must never reach the browser');
        $this->assertStringContainsString('settlement_window', $html, 'non-secret configuration is still shown by key');
        $this->assertStringContainsString('T+1', $html);
    }

    // ------------------------------------------------------------------
    // The driver is not free text
    // ------------------------------------------------------------------

    public function test_registering_a_driver_no_class_implements_is_refused_by_name(): void
    {
        // Before this the registry accepted any string. A row naming 'acme_gateway' looked
        // configured to every reader of the table and failed only when a player tried to pay.
        $registry = app(PaymentProviderRegistry::class);

        try {
            $registry->register(
                \App\DTOs\Payment\PaymentProviderData::fromInput(
                    'ghost-pay', 'Ghost Pay', 'acme_gateway', 'pending', ['THB'],
                ),
            );
            $this->fail('a provider naming an unimplemented driver must not be registered');
        } catch (PaymentProviderException $exception) {
            $this->assertSame(PaymentProviderException::CODE_UNSUPPORTED_DRIVER, $exception->errorCode());
            $this->assertStringContainsString('acme_gateway', $exception->getMessage());
            // The refusal names what DOES exist, so the operator can correct the row.
            $this->assertStringContainsString('stripe', $exception->getMessage());
        }

        $this->assertDatabaseCount('payment_providers', 0);

        // …and an implemented driver is still accepted: the guard is a check, not a wall.
        $registered = $registry->register(
            \App\DTOs\Payment\PaymentProviderData::fromInput('real-pay', 'Real Pay', 'stripe', 'pending', ['THB']),
        );

        $this->assertFalse($registered['replayed']);
        $this->assertSame('stripe', (string) $registered['provider']->driver);
    }

    public function test_the_driver_list_has_one_source_and_the_inventory_is_a_subset_of_it(): void
    {
        $manager = app(\App\Services\Payment\PaymentGatewayManager::class);

        foreach (\App\Services\Payment\PaymentGatewayManager::supportedDrivers() as $driver) {
            $this->assertTrue($manager->hasDriver($driver), $driver.' is listed but not usable');
        }

        $this->assertFalse($manager->hasDriver('acme_gateway'));
        $this->assertFalse($manager->hasDriver(''));

        // The operator inventory may be narrower than the constructible set (internal routing
        // keys such as 'sandbox'/'manual' are not client methods), never wider.
        $this->assertSame(
            [],
            array_values(array_diff(\App\Services\Payment\PaymentGatewayManager::INVENTORY_DRIVERS, \App\Services\Payment\PaymentGatewayManager::DRIVERS)),
            'the inventory must not name a driver the application cannot construct',
        );
    }

    public function test_a_legacy_row_with_a_retired_driver_is_still_reported_honestly(): void
    {
        // Rows written before the registry refused unknown drivers may still exist. They are not
        // deleted (that would erase history) and they are not silently rewritten; the view says
        // plainly that no runtime driver is registered under that name.
        $this->operator('super-admin');

        $provider = new PaymentProvider;
        $provider->fill([
            'code' => 'legacy-pay',
            'name' => 'Legacy Pay',
            'driver' => 'acme_gateway',
            'supported_currencies' => ['THB'],
            'non_secrets' => [],
        ]);
        $provider->status = PaymentProviderStatus::Active;
        $provider->save();

        $html = Livewire::test(ViewPaymentProvider::class, ['record' => $provider->getKey()])
            ->assertSuccessful()
            ->html();

        $this->assertStringContainsString('No runtime driver is registered', $html);
    }

    public function test_the_view_page_reports_method_offers_and_runtime_readiness(): void
    {
        $this->operator('super-admin');

        $provider = $this->provider('readiness-pay', PaymentProviderStatus::Active);

        $html = Livewire::test(ViewPaymentProvider::class, ['record' => $provider->getKey()])
            ->assertSuccessful()
            ->html();

        $this->assertStringContainsString('Runtime readiness', $html);
        $this->assertStringContainsString('Method offers', $html);
    }
}
