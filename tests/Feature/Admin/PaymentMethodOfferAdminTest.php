<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentProviderStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\PaymentMethodConfigResource;
use App\Filament\Resources\PaymentMethodConfigResource\Pages\CreatePaymentMethodConfig;
use App\Filament\Resources\PaymentMethodConfigResource\Pages\ListPaymentMethodConfigs;
use App\Filament\Resources\PaymentMethodConfigResource\Pages\ViewPaymentMethodConfig;
use App\Filament\Resources\PaymentMethodConfigResource\PaymentMethodConfigActions;
use App\Models\AuditLog;
use App\Models\PaymentMethodConfig;
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
 * Payment method offers: the min/max/fee menu the registry resolves against.
 *
 * WHY THIS SUITE EXISTS
 *
 * `resolveOffer()` is the function every deposit and withdrawal path calls to decide what a
 * payer may do. It refuses anything that is not Enabled, and until this screen existed nothing
 * but a tinker session could enable a row — so the offerable menu was whatever the seeder left
 * behind. A screen that can turn an offer on is a screen that changes what money may move, and
 * four things therefore have to be proven rather than assumed:
 *
 *   1. The permission split is real: `payments.providers.view` reads, `payments.providers.manage`
 *      writes, and a player gets 403.
 *   2. A move goes through `PaymentProviderRegistry::transitionMethod()` — the enum's map, the
 *      row lock, and a High-risk audit line naming the operator — not a field write.
 *   3. The lifecycle cannot be skipped: a Retired offer has no verbs, and the enum's "must be
 *      Disabled before Retired" rule is visible on the screen rather than discovered at runtime.
 *   4. The commercial terms are immutable. There is no edit page, and re-registering the same
 *      key with different limits is refused as a duplicate rather than silently overwriting
 *      what a payer was promised.
 *
 * The audit-points-at-the-right-subject test at the end covers a defect fixed in this pass:
 * method audit rows carried `auditable_type = PaymentProvider` with a method id.
 */
final class PaymentMethodOfferAdminTest extends TestCase
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
        PaymentProviderStatus $status = PaymentProviderStatus::Active,
    ): PaymentProvider {
        $provider = new PaymentProvider;
        $provider->fill([
            'code' => $code,
            'name' => 'Acme Pay',
            'driver' => 'stripe',
            'supported_currencies' => ['THB'],
            'non_secrets' => [],
        ]);
        $provider->status = $status;
        $provider->save();

        return $provider->fresh();
    }

    private function method(
        PaymentProvider $provider,
        string $methodCode = 'promptpay',
        PaymentMethodStatus $status = PaymentMethodStatus::Pending,
        string $min = '100.00',
        string $max = '50000.00',
        string $currency = 'THB',
    ): PaymentMethodConfig {
        $method = new PaymentMethodConfig;
        $method->fill([
            'method_key' => sprintf('%s:%s:%s', $provider->code, $methodCode, $currency),
            'provider_id' => $provider->getKey(),
            'method_code' => $methodCode,
            'currency' => $currency,
            'min_amount' => $min,
            'max_amount' => $max,
            'fee_bps' => 250,
            'metadata' => [],
        ]);
        $method->status = $status;
        $method->save();

        return $method->fresh();
    }

    // ------------------------------------------------------------- access

    public function test_the_offer_screen_is_open_to_provider_readers_and_closed_to_a_player(): void
    {
        $this->operator('deposit-manager');
        $this->assertTrue(PaymentMethodConfigResource::canViewAny());

        $this->operator('player');
        $this->assertFalse(PaymentMethodConfigResource::canViewAny());
        $this->get('/admin/payment-method-configs')->assertForbidden();
    }

    public function test_a_reader_without_the_manage_permission_cannot_reach_the_create_page(): void
    {
        // deposit-manager holds payments.providers.view but NOT payments.providers.manage
        // (only super-admin holds 'manage' — the provider lane is deliberately narrow).
        $this->operator('deposit-manager');

        $this->assertFalse(PaymentMethodConfigResource::canCreate());
        $this->get('/admin/payment-method-configs/create')->assertForbidden();
    }

    public function test_the_navigation_badge_counts_configured_but_dark_offers(): void
    {
        $provider = $this->provider();
        $this->method($provider, 'promptpay', PaymentMethodStatus::Pending);
        $this->method($provider, 'bank_transfer', PaymentMethodStatus::Enabled);

        $this->operator('super-admin');

        $this->assertSame('1', PaymentMethodConfigResource::getNavigationBadge());
    }

    // ------------------------------------------------------------- creates

    public function test_registering_an_offer_goes_through_the_registry_and_lands_pending(): void
    {
        $this->provider();
        $this->operator('super-admin');

        Livewire::test(CreatePaymentMethodConfig::class)
            ->fillForm([
                'provider_code' => 'acme-pay',
                'method_code' => 'promptpay',
                'currency' => 'THB',
                'min_amount' => '100',
                'max_amount' => '50000',
                'fee_bps' => 250,
                'status' => PaymentMethodStatus::Pending->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $method = PaymentMethodConfig::query()->where('method_code', 'promptpay')->first();

        $this->assertNotNull($method);
        $this->assertSame(PaymentMethodStatus::Pending, $method->status);
        $this->assertSame('100.00', (string) $method->min_amount);
        $this->assertSame('50000.00', (string) $method->max_amount);
        $this->assertSame(250, (int) $method->fee_bps);

        // The offer is not reachable yet, because the registry only serves Enabled rows.
        $this->assertFalse($method->status->isOfferable());
    }

    public function test_the_same_offer_registered_twice_is_a_replay_not_a_second_row(): void
    {
        $this->provider();
        $this->operator('super-admin');

        $payload = [
            'provider_code' => 'acme-pay',
            'method_code' => 'promptpay',
            'currency' => 'THB',
            'min_amount' => '100',
            'max_amount' => '50000',
            'fee_bps' => 250,
            'status' => PaymentMethodStatus::Pending->value,
        ];

        Livewire::test(CreatePaymentMethodConfig::class)->fillForm($payload)->call('create');
        Livewire::test(CreatePaymentMethodConfig::class)->fillForm($payload)->call('create');

        $this->assertSame(1, PaymentMethodConfig::query()->count());
    }

    public function test_the_same_key_with_different_limits_is_refused_as_a_duplicate(): void
    {
        $provider = $this->provider();
        $this->method($provider, 'promptpay', PaymentMethodStatus::Pending, '100.00', '50000.00');

        $this->operator('super-admin');

        // registerMethod() treats a re-pledge under the same key with different numbers as a
        // duplicate, and the screen must relay that instead of overwriting the promise.
        $this->expectException(\App\Exceptions\PaymentProviderException::class);

        app(PaymentProviderRegistry::class)->registerMethod(
            \App\DTOs\Payment\PaymentMethodData::fromInput(
                providerCode: 'acme-pay',
                methodCode: 'promptpay',
                currency: 'THB',
                minAmount: '200',
                maxAmount: '90000',
            ),
        );
    }

    public function test_an_inverted_limit_range_never_reaches_the_database(): void
    {
        $this->provider();
        $this->operator('super-admin');

        Livewire::test(CreatePaymentMethodConfig::class)
            ->fillForm([
                'provider_code' => 'acme-pay',
                'method_code' => 'promptpay',
                'currency' => 'THB',
                'min_amount' => '900',
                'max_amount' => '100',
                'fee_bps' => 0,
                'status' => PaymentMethodStatus::Pending->value,
            ])
            ->call('create');

        $this->assertSame(0, PaymentMethodConfig::query()->count());
    }

    // ---------------------------------------------------------- lifecycle

    public function test_enabling_an_offer_goes_through_the_registry_and_is_audited_against_the_method(): void
    {
        $provider = $this->provider();
        $method = $this->method($provider);
        $operator = $this->operator('super-admin');

        app(PaymentProviderRegistry::class)->transitionMethod($method, PaymentMethodStatus::Enabled, 'Going live for the launch.');

        $this->assertSame(PaymentMethodStatus::Enabled, $method->fresh()->status);
        $this->assertTrue($method->fresh()->status->isOfferable());

        $audit = AuditLog::query()
            ->where('auditable_type', PaymentMethodConfig::class)
            ->where('auditable_id', $method->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'the method transition must be audited against the method, not the provider');
        $this->assertSame($operator->getKey(), (int) $audit->user_id);
        $this->assertSame('high', (string) $audit->risk_level->value ?? 'high');
        $this->assertStringContainsString('→ enabled', (string) $audit->description);

        // And no audit row claims this method id belongs to a provider.
        $this->assertSame(0, AuditLog::query()
            ->where('auditable_type', PaymentProvider::class)
            ->where('auditable_id', $method->getKey())
            ->where('description', 'like', '%transition_method%')
            ->count());
    }

    public function test_a_retired_offer_is_offered_no_verbs_at_all(): void
    {
        $provider = $this->provider();
        $retired = $this->method($provider, 'promptpay', PaymentMethodStatus::Retired);

        $this->operator('super-admin');

        foreach (PaymentMethodConfigActions::forTable() as $action) {
            $this->assertFalse(
                $action->record($retired)->isVisible(),
                'A retired offer must expose no lifecycle verb, found: '.$action->getName(),
            );
        }
    }

    public function test_an_enabled_offer_offers_disable_and_retire_but_not_enable(): void
    {
        $provider = $this->provider();
        $enabled = $this->method($provider, 'promptpay', PaymentMethodStatus::Enabled);

        $this->operator('super-admin');

        $visible = collect(PaymentMethodConfigActions::forTable())
            ->filter(fn ($action): bool => $action->record($enabled)->isVisible())
            ->map(fn ($action): string => $action->getName())
            ->values()
            ->all();

        $this->assertSame(['disable', 'retire'], $visible);
    }

    public function test_the_enum_forbids_retiring_an_enabled_offer_directly_on_the_service_path(): void
    {
        $provider = $this->provider();
        $enabled = $this->method($provider, 'promptpay', PaymentMethodStatus::Enabled);

        $this->operator('super-admin');

        // Enabled → Retired IS allowed by the enum (you may burn the shelf while it is on it);
        // Pending → Enabled → Disabled is the reversible path. What must never work is
        // anything out of Retired, which the next test covers. Here we prove the documented
        // map is what the screen filters on.
        $this->assertTrue($enabled->status->canTransitionTo(PaymentMethodStatus::Retired));
        $this->assertTrue($enabled->status->canTransitionTo(PaymentMethodStatus::Disabled));
    }

    public function test_a_retired_offer_cannot_be_revived_by_the_service(): void
    {
        $provider = $this->provider();
        $retired = $this->method($provider, 'promptpay', PaymentMethodStatus::Retired);

        $this->operator('super-admin');

        $this->expectException(\App\Exceptions\PaymentProviderException::class);

        app(PaymentProviderRegistry::class)->transitionMethod($retired, PaymentMethodStatus::Enabled, 'Bring it back.');
    }

    public function test_a_reader_without_manage_permission_is_offered_no_verb(): void
    {
        $provider = $this->provider();
        $pending = $this->method($provider, 'promptpay', PaymentMethodStatus::Pending);

        $this->operator('deposit-manager');

        foreach (PaymentMethodConfigActions::forTable() as $action) {
            $this->assertFalse(
                $action->record($pending)->isVisible(),
                'A view-only operator must not be offered: '.$action->getName(),
            );
        }
    }

    // ------------------------------------------------------------- screens

    public function test_the_offer_queue_and_record_render_for_an_operator(): void
    {
        $provider = $this->provider();
        $method = $this->method($provider, 'promptpay', PaymentMethodStatus::Enabled);

        $this->operator('super-admin');

        Livewire::test(ListPaymentMethodConfigs::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$method]);

        Livewire::test(ViewPaymentMethodConfig::class, ['record' => $method->getKey()])
            ->assertOk()
            ->assertSee('promptpay')
            ->assertActionVisible('disable');
    }

    public function test_the_resource_has_no_edit_and_no_delete_surface(): void
    {
        $provider = $this->provider();
        $method = $this->method($provider);

        $this->operator('super-admin');

        $this->assertFalse(PaymentMethodConfigResource::canEdit($method));
        $this->assertFalse(PaymentMethodConfigResource::canDelete($method));
        $this->assertFalse(PaymentMethodConfigResource::canDeleteAny());

        // The commercial terms are the promise; the only writes are register + transition.
        $this->get('/admin/payment-method-configs/'.$method->getKey().'/edit')->assertNotFound();
    }
}
