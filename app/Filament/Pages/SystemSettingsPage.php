<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\SystemSettingException;
use App\Services\Admin\SystemSettingService;
use App\Services\Audit\AuditLogService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The settings desk: the operator-overridable half of the configuration, and nothing else.
 *
 * WHY IT IS NOT A `.env` EDITOR, AND WHY THAT IS A FEATURE
 *
 * A settings screen that writes `.env` is a privilege-escalation surface wearing a form: whoever
 * can save a value can point the application at another database or read every secret. The audit
 * refused to build it, and this page is the alternative that was actually missing — a store of
 * OVERRIDES whose keys are whitelisted in code (`config/admin.php → settings_catalogue`), with
 * types and bounds declared beside the label, and every write audited old→new at High risk.
 *
 * WHAT THIS PAGE CANNOT DO
 *
 * - It cannot invent a key. The form is generated from the catalogue, and the service refuses an
 *   unknown key by name even if a forged payload names one.
 * - It cannot write a secret. No credential is in the catalogue; the service also refuses any
 *   entry marked secret, so a careless future entry fails loudly instead of storing a token.
 * - It cannot store an incoherent money pair. A minimum above its maximum is refused with both
 *   values quoted, whichever field was edited first.
 * - It cannot show a value it cannot trust. A stored row that no longer validates (a bound was
 *   tightened, a type changed) is displayed as inert and is NOT applied to `config()`.
 *
 * PERMISSIONS: 'settings.view' to read, 'settings.manage' to write. Both are granular-only —
 * `permitsStrict()` — because a settings surface reachable through a coarse phrase a player holds
 * would be the worst possible trade.
 */
/**
 * @property Form $form the Filament form state holder installed by InteractsWithForms
 */
class SystemSettingsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'System settings';

    protected static ?string $title = 'System settings';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'system-settings';

    protected static string $view = 'filament.pages.system-settings-page';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return AdminAccess::permitsStrict(AdminPermission::SETTINGS_VIEW)
            || AdminAccess::permitsStrict(AdminPermission::SETTINGS_MANAGE);
    }

    public static function canManage(): bool
    {
        return AdminAccess::permitsStrict(AdminPermission::SETTINGS_MANAGE);
    }

    public function mount(): void
    {
        $this->fillForm();
    }

    public function form(Form $form): Form
    {
        $service = app(SystemSettingService::class);
        $sections = [];

        foreach ($service->grouped() as $group => $entries) {
            $fields = [];

            foreach ($entries as $key => $entry) {
                $name = self::stateName((string) $key);
                $label = (string) ($entry['label'] ?? $key);
                $description = (string) ($entry['description'] ?? '');
                $overridden = $service->isOverridden((string) $key);

                $helper = $overridden
                    ? 'OVERRIDDEN — a stored value is in force. '.$description
                    : 'Deployed default is in force. '.$description;

                $fields[] = match ((string) ($entry['type'] ?? 'string')) {
                    'bool' => Forms\Components\Toggle::make($name)
                        ->label($label)
                        ->helperText($helper),

                    'int' => Forms\Components\TextInput::make($name)
                        ->label($label)
                        ->numeric()
                        ->minValue($entry['min'] ?? null)
                        ->maxValue($entry['max'] ?? null)
                        ->helperText($helper),

                    'money' => Forms\Components\TextInput::make($name)
                        ->label($label)
                        ->helperText($helper),

                    default => Forms\Components\TextInput::make($name)
                        ->label($label)
                        ->helperText($helper),
                };
            }

            $sections[] = Forms\Components\Section::make($group)
                ->schema($fields)
                ->columns(2);
        }

        return $form
            ->schema($sections)
            ->statePath('data');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        if (! self::canManage()) {
            return [];
        }

        return [
            Action::make('resetAll')
                ->label('Revert every override')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Revert every stored override?')
                ->modalDescription('Each overridden key returns to its deployed default. The revert is audited per key, exactly like a change.')
                ->visible(fn (): bool => app(SystemSettingService::class)->overrides() !== [])
                ->action(function (): void {
                    $operator = Auth::user();

                    if ($operator === null) {
                        return;
                    }

                    $service = app(SystemSettingService::class);
                    $count = 0;

                    foreach (array_keys($service->overrides()) as $key) {
                        $service->reset((string) $key, $operator);
                        $count++;
                    }

                    $this->fillForm();

                    Notification::make()
                        ->success()
                        ->title($count.' override(s) reverted')
                        ->body('The deployed configuration is in force again.')
                        ->send();
                }),
        ];
    }

    /**
     * Save only what actually changed.
     *
     * A form that writes every field on every save would produce an audit line per setting per
     * click and make "who changed the withdrawal minimum" unanswerable. So the comparison is
     * against the effective value, and an unchanged field is left alone entirely.
     */
    public function save(): void
    {
        if (! self::canManage()) {
            Notification::make()
                ->danger()
                ->title('Not permitted')
                ->body('You may read the settings but not change them.')
                ->send();

            return;
        }

        $operator = Auth::user();

        if ($operator === null) {
            return;
        }

        $service = app(SystemSettingService::class);
        $state = (array) ($this->data ?? []);
        $changed = 0;
        $refusals = [];

        foreach ($service->catalogue() as $key => $entry) {
            $name = self::stateName((string) $key);
            $submitted = $state[$name] ?? null;
            $current = $service->current((string) $key);

            if (self::sameValue($submitted, $current, (string) ($entry['type'] ?? 'string'))) {
                continue;
            }

            try {
                $service->set((string) $key, $submitted, $operator);
                $changed++;
            } catch (SystemSettingException $e) {
                $refusals[] = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $refusals[] = (string) $key.': '.(string) $e->getMessage();
            }
        }

        $this->fillForm();

        if ($refusals !== []) {
            Notification::make()
                ->danger()
                ->title('Some settings were refused')
                ->body(implode(' ', array_slice($refusals, 0, 3)))
                ->persistent()
                ->send();
        }

        if ($changed > 0) {
            app(AuditLogService::class)->log(
                userId: (int) $operator->getKey(),
                action: AuditAction::ConfigChange,
                riskLevel: RiskLevel::High,
                auditable: null,
                description: sprintf('A settings save changed %d value(s) from the panel.', $changed),
                metadata: ['surface' => 'admin-panel', 'changed' => $changed],
            );

            Notification::make()
                ->success()
                ->title($changed.' setting(s) saved')
                ->body('Each change is audited with its previous value.')
                ->send();
        } elseif ($refusals === []) {
            Notification::make()
                ->info()
                ->title('Nothing to save')
                ->body('No value differs from what is already in force.')
                ->send();
        }
    }

    /**
     * One row per stored override: the key, its effective stored value, and the deployed default
     * it is standing in front of.
     *
     * @return list<array{key: string, value: string, default: string}>
     */
    public function overrides(): array
    {
        $service = app(SystemSettingService::class);
        $rows = [];

        // Built as a loop rather than a collection pipeline: the row shape is part of the return
        // type, and a `map()` over mixed values tells the reader (and the analyser) nothing.
        foreach ($service->overrides() as $key => $value) {
            $rows[] = [
                'key' => (string) $key,
                'value' => is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : '',
                'default' => is_scalar(config($key)) ? (is_bool(config($key)) ? (config($key) ? 'true' : 'false') : (string) config($key)) : '',
            ];
        }

        return $rows;
    }

    public function canManageSettings(): bool
    {
        return self::canManage();
    }

    protected function fillForm(): void
    {
        $service = app(SystemSettingService::class);
        $state = [];

        foreach ($service->catalogue() as $key => $entry) {
            $state[self::stateName((string) $key)] = $service->current((string) $key);
        }

        $this->form->fill($state);
    }

    /**
     * Config keys contain dots, which Filament reads as nesting. The form therefore uses a flat
     * name and maps back, so `lottery.draw.interval_minutes` becomes one state key, not four
     * levels of an array that no catalogue entry ever fills.
     */
    public static function stateName(string $key): string
    {
        return str_replace(['.', '-'], ['__', '_'], $key);
    }

    private static function sameValue(mixed $submitted, mixed $current, string $type): bool
    {
        return match ($type) {
            'bool' => (bool) $submitted === (bool) $current,
            'int' => is_numeric($submitted) && (int) $submitted === (int) $current,
            'money' => self::money($submitted) === self::money($current),
            default => (string) $submitted === (string) $current,
        };
    }

    private static function money(mixed $value): string
    {
        $string = trim((string) $value);

        if (preg_match('/^\d+(\.\d{1,2})?$/', $string) !== 1) {
            return $string;
        }

        [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '00');

        return ltrim($whole, '0') === '' ? '0.'.str_pad($fraction, 2, '0') : ltrim($whole, '0').'.'.str_pad($fraction, 2, '0');
    }
}
