<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Exceptions\SystemSettingException;
use App\Filament\Pages\SystemSettingsPage;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Admin\SystemSettingService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Database\Seeders\AdminRbacSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The settings store: a whitelist, not a config writer.
 *
 * WHAT THIS SUITE IS DEFENDING
 *
 * The audit's objection to "system settings in the admin panel" was never that operators do not
 * need to tune a draw interval without a deployment. It was that the obvious implementation —
 * write `.env`, or write `config/*.php` — hands code execution to whoever holds one permission,
 * and that a table of arbitrary key/value pairs is the same gift with a nicer UI. So the tests
 * below are mostly about what CANNOT happen:
 *
 *   - an unknown key cannot be written, even by a super-admin with a forged payload;
 *   - a secret cannot be stored, because a settings row is readable by anyone who can read the DB;
 *   - a value outside the declared bounds is refused, and nothing is written;
 *   - a money pair cannot become incoherent whichever side is edited first;
 *   - reading does not imply writing, and a viewer's save writes nothing.
 *
 * The one thing it must also prove is that the store is WORTH HAVING: a saved value actually
 * reaches `config()`, and every write leaves an audit line with the previous value.
 */
final class SystemSettingsTest extends TestCase
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

        $this->seed(RolePermissionSeeder::class);
        $this->seed(AdminRbacSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function operator(string $role, ?string ...$extraPermissions): User
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $user->assignRole($role);

        foreach ($extraPermissions as $permission) {
            if ($permission !== null) {
                $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    // --------------------------------------------------------------- access

    public function test_the_page_is_closed_to_a_player_and_open_to_a_settings_reader(): void
    {
        $this->operator('player');
        $this->assertFalse(SystemSettingsPage::canAccess());
        $this->get('/admin/system-settings')->assertForbidden();

        $this->operator('admin');
        $this->assertTrue(SystemSettingsPage::canAccess());
        $this->get('/admin/system-settings')->assertSuccessful();

        // The admin role reads settings; it does not hold the write permission. A read-only
        // settings screen is the whole point of separating the two.
        $this->assertFalse(SystemSettingsPage::canManage());
    }

    public function test_a_reader_cannot_save_and_no_row_is_written(): void
    {
        $this->operator('admin');

        Livewire::test(SystemSettingsPage::class)
            ->set('data.lottery__draw__interval_minutes', 30)
            ->call('save')
            ->assertOk();

        $this->assertSame(0, SystemSetting::query()->count(), 'a viewer must not be able to write an override');
        $this->assertSame(60, (int) config('lottery.draw.interval_minutes'), 'the deployed default must still be in force');
    }

    // ------------------------------------------------------------ the store

    public function test_the_form_renders_the_catalogue_labels_and_groups(): void
    {
        $this->operator('super-admin');

        Livewire::test(SystemSettingsPage::class)
            ->assertOk()
            ->assertSee('Lottery automation')
            ->assertSee('Draw interval (minutes)')
            ->assertSee('System settings')
            ->assertSee('Minimum deposit');
    }

    public function test_a_saved_value_is_stored_typed_and_reaches_config(): void
    {
        $operator = $this->operator('super-admin');

        app(SystemSettingService::class)->set('lottery.draw.interval_minutes', 45, $operator);

        $row = SystemSetting::query()->where('key', 'lottery.draw.interval_minutes')->firstOrFail();

        $this->assertSame('45', $row->value);
        $this->assertSame('int', $row->value_type);
        $this->assertSame($operator->getKey(), (int) $row->updated_by);

        // The boot-time merge is what makes the store worth having: every existing reader of
        // config() sees the operator's value without being rewritten.
        app(SystemSettingService::class)->applyToConfig();
        $this->assertSame(45, config('lottery.draw.interval_minutes'));

        $this->assertSame('45', (string) app(SystemSettingService::class)->current('lottery.draw.interval_minutes'));
    }

    public function test_a_boolean_override_round_trips_as_a_bool(): void
    {
        $operator = $this->operator('super-admin');

        app(SystemSettingService::class)->set('lottery.automation.enabled', false, $operator);

        $this->assertSame('0', SystemSetting::query()->where('key', 'lottery.automation.enabled')->value('value'));
        $this->assertFalse(app(SystemSettingService::class)->current('lottery.automation.enabled'));

        app(SystemSettingService::class)->applyToConfig();
        $this->assertFalse(config('lottery.automation.enabled'));
    }

    public function test_money_is_stored_as_a_decimal_string_not_a_float(): void
    {
        $operator = $this->operator('super-admin');

        app(SystemSettingService::class)->set('finance.deposit.min', '5.5', $operator);

        $this->assertSame(
            '5.50',
            SystemSetting::query()->where('key', 'finance.deposit.min')->value('value'),
            'money must be normalised as a decimal string; 5.5 and 5.50 are the same amount and must not be two values',
        );
    }

    // -------------------------------------------------------------- refusals

    public function test_an_unknown_key_is_refused_by_name(): void
    {
        $operator = $this->operator('super-admin');

        try {
            app(SystemSettingService::class)->set('app.key', 'x', $operator);
            $this->fail('an out-of-catalogue key was written; the store is not a whitelist');
        } catch (SystemSettingException $e) {
            $this->assertSame(SystemSettingException::CODE_UNKNOWN_KEY, $e->refusalCode());
            $this->assertStringContainsString('app.key', $e->getMessage());
        }

        $this->assertSame(0, SystemSetting::query()->count());
    }

    public function test_a_secret_entry_cannot_be_written_even_if_it_enters_the_catalogue(): void
    {
        config(['admin.settings_catalogue.mail.password' => [
            'label' => 'Mail password',
            'group' => 'Secrets',
            'type' => 'string',
            'secret' => true,
            'description' => 'Never stored here.',
        ]]);

        $operator = $this->operator('super-admin');

        $this->expectException(SystemSettingException::class);

        try {
            app(SystemSettingService::class)->set('mail.password', 'hunter2', $operator);
        } finally {
            $this->assertSame(0, SystemSetting::query()->count(), 'a secret must never reach the settings table');
        }
    }

    public function test_a_value_outside_the_declared_bounds_is_refused_and_nothing_is_written(): void
    {
        $operator = $this->operator('super-admin');

        foreach ([4, 1441, 'sixty'] as $bad) {
            try {
                app(SystemSettingService::class)->set('lottery.draw.interval_minutes', $bad, $operator);
                $this->fail('an out-of-bounds value was accepted: '.var_export($bad, true));
            } catch (SystemSettingException $e) {
                $this->assertContains($e->refusalCode(), [
                    SystemSettingException::CODE_OUT_OF_BOUNDS,
                    SystemSettingException::CODE_MALFORMED,
                ]);
            }
        }

        $this->assertSame(0, SystemSetting::query()->count());
    }

    public function test_a_minimum_above_its_maximum_is_refused_whichever_side_is_edited_first(): void
    {
        $operator = $this->operator('super-admin');
        $service = app(SystemSettingService::class);

        // Raise the maximum downward until it would fall below the deployed minimum (1.00): that
        // is caught as an incoherent pair, not as a bounds violation.
        try {
            $service->set('finance.deposit.max', '0.50', $operator);
            $this->fail('a maximum below the minimum was accepted');
        } catch (SystemSettingException $e) {
            $this->assertContains($e->refusalCode(), [
                SystemSettingException::CODE_OUT_OF_BOUNDS,
                SystemSettingException::CODE_PAIR_INCOHERENT,
            ]);
        }

        $service->set('finance.deposit.min', '500.00', $operator);

        try {
            $service->set('finance.deposit.max', '400.00', $operator);
            $this->fail('a maximum below a stored minimum was accepted');
        } catch (SystemSettingException $e) {
            $this->assertSame(SystemSettingException::CODE_PAIR_INCOHERENT, $e->refusalCode());
            $this->assertStringContainsString('finance.deposit.min', $e->getMessage());
            $this->assertStringContainsString('500.00', $e->getMessage());
        }

        $this->assertSame(
            '500.00',
            SystemSetting::query()->where('key', 'finance.deposit.min')->value('value'),
            'the accepted minimum must survive a refused edit to its pair',
        );
    }

    // --------------------------------------------------------------- audit

    public function test_every_write_is_audited_with_old_and_new_values_at_high_risk(): void
    {
        $operator = $this->operator('super-admin');

        app(SystemSettingService::class)->set('lottery.closing.minutes_before_draw', 10, $operator);

        $audit = AuditLog::query()->where('user_id', $operator->getKey())->latest('id')->firstOrFail();

        $this->assertSame('config_change', (string) $audit->action->value);
        $this->assertSame('high', (string) $audit->risk_level->value);
        $this->assertStringContainsString('lottery.closing.minutes_before_draw', (string) $audit->description);

        $old = (array) ($audit->old_values ?? []);
        $new = (array) ($audit->new_values ?? []);

        $this->assertSame('5', (string) ($old['value'] ?? ''), 'the audit must record what the value WAS');
        $this->assertSame('10', (string) ($new['value'] ?? ''));
    }

    public function test_a_reset_returns_the_deployed_default_and_is_audited(): void
    {
        $operator = $this->operator('super-admin');
        $service = app(SystemSettingService::class);

        $service->set('lottery.automation.batch_size', 25, $operator);
        $service->reset('lottery.automation.batch_size', $operator);

        $this->assertSame(0, SystemSetting::query()->where('key', 'lottery.automation.batch_size')->count());
        $this->assertFalse($service->isOverridden('lottery.automation.batch_size'));
        $this->assertSame(50, (int) $service->current('lottery.automation.batch_size'));

        $audit = AuditLog::query()->where('user_id', $operator->getKey())->latest('id')->firstOrFail();
        $this->assertSame('config_change', (string) $audit->action->value);
        $this->assertStringContainsString('reset', strtolower((string) $audit->description));
    }

    // -------------------------------------------------------------- the page

    public function test_the_page_saves_only_what_changed(): void
    {
        $operator = $this->operator('super-admin', AdminPermission::SETTINGS_MANAGE);

        Livewire::test(SystemSettingsPage::class)
            ->set('data.lottery__draw__interval_minutes', 45)
            ->call('save')
            ->assertOk();

        $this->assertSame(1, SystemSetting::query()->count(), 'only the edited key may be written');

        // A second save with the same value must not create a second row or a second change.
        Livewire::test(SystemSettingsPage::class)->call('save')->assertOk();

        $this->assertSame(1, SystemSetting::query()->count());
        $this->assertSame(
            1,
            AuditLog::query()->where('description', 'like', '%lottery.draw.interval_minutes%')->count(),
            'an unchanged save must not claim a change',
        );
    }

    public function test_an_inert_row_for_a_key_that_left_the_catalogue_is_ignored(): void
    {
        $operator = $this->operator('super-admin');

        // A row written by an older deployment whose key has since been removed from the
        // catalogue. Applying it would let dead code steer live configuration.
        SystemSetting::query()->create([
            'key' => 'retired.legacy.setting',
            'value' => '500',
            'value_type' => 'int',
            'updated_by' => $operator->getKey(),
        ]);

        $this->assertSame([], app(SystemSettingService::class)->overrides());
        $this->assertNull(config('retired.legacy.setting'));

        // And it is not silently deleted either: a human can still see it in the table.
        $this->assertSame(1, SystemSetting::query()->count());
    }

    public function test_the_write_permission_is_not_implied_by_reading(): void
    {
        $this->operator('admin');

        $this->assertTrue(AdminAccess::permitsStrict(AdminPermission::SETTINGS_VIEW));
        $this->assertFalse(
            AdminAccess::permitsStrict(AdminPermission::SETTINGS_MANAGE),
            'reading settings must never carry the power to change them',
        );
    }
}
