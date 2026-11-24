<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Services\Admin\RoleAdministrationService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Role management — the screen the panel had no answer for.
 *
 * WHY THIS RESOURCE EXISTS
 *
 * Every other resource in this panel authorises against a role the operator was
 * given by a developer running a seeder. That meant the only way to make somebody a
 * deposit manager was a tinker session, and the only roles that could exist were the
 * five the seeder knew about. This screen makes role composition a supervised,
 * audited administrative act.
 *
 * WHAT IT MANAGES — AND WHAT IT MUST NOT TOUCH
 *
 * It manages the GRANULAR permission set only (config/admin.php). The coarse phrases
 * ('manage payouts', 'view audit logs', …) are shown READ-ONLY, because they are what
 * the existing screens authorise against and a checkbox here could silently strip
 * them. Replacing a role's whole permission set via syncPermissions() would do exactly
 * that, which is why every write goes through RoleAdministrationService, which
 * attaches and detaches granular rows and leaves the rest alone.
 *
 * THREE GUARDS, ALL SERVER-SIDE
 *
 *   1. The permission list only offers what the ACTOR may grant (AdminAccess::mayGrant),
 *      and the service re-checks it — a forged Livewire request cannot smuggle
 *      'withdrawals.execute' into a role.
 *   2. Protected roles (super-admin, admin, auditor) are editable by super-admin only,
 *      because the panel's entry rule is written against those names.
 *   3. An operator cannot delete a role that is assigned, nor one they hold themselves.
 *
 * The UI hides what it cannot do, but hiding is never the check: every rule above is
 * enforced in the service, and the tests exercise the service directly.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Roles';

    protected static ?string $modelLabel = 'role';

    protected static ?string $pluralModelLabel = 'roles';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return AdminAccess::permits(AdminPermission::ROLES_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Role && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return AdminAccess::permits(AdminPermission::ROLES_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Role
            && app(RoleAdministrationService::class)->mayEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof Role
            && app(RoleAdministrationService::class)->mayDelete($record);
    }

    public static function canDeleteAny(): bool
    {
        // Deleting roles in bulk is never safe: each one needs its own in-use check.
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identity')
                ->description('The name is the panel\'s identifier for this role. Protected names are reserved.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Role name')
                        ->required()
                        ->minLength(3)
                        ->maxLength(50)
                        ->regex('/^[a-z][a-z0-9-]*$/')
                        ->helperText('Lower-case letters, digits and hyphens, e.g. "deposit-manager".')
                        ->disabled(fn (?Role $record): bool => static::isProtected($record)
                            && ! AdminAccess::isSuperAdmin())
                        ->dehydrated(),
                ]),

            Forms\Components\Section::make('Granular permissions')
                ->description('Only the permissions you hold yourself are offered here. A grant you cannot make is refused by the service even if this form is forged.')
                ->schema([
                    Forms\Components\CheckboxList::make('granular_permissions')
                        ->label('Permissions')
                        ->options(fn (): array => static::grantableOptions())
                        ->columns(2)
                        ->searchable()
                        ->bulkToggleable()
                        ->helperText('Each label is prefixed with the surface that owns the decision. Coarse legacy phrases are shown read-only below.'),
                ]),

            Forms\Components\Section::make('Legacy (coarse) permissions — read only')
                ->description('These are the coarse phrases the existing screens authorise against. They are not editable here on purpose: stripping one would break a working screen.')
                ->schema([
                    Forms\Components\Placeholder::make('coarse_permissions')
                        ->label('')
                        ->content(fn (?Role $record): string => $record instanceof Role
                            ? (implode(', ', app(RoleAdministrationService::class)->coarsePermissionsOf($record)) ?: '—')
                            : '—'),
                ])
                ->visible(fn (?Role $record): bool => $record instanceof Role),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['users', 'permissions']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Role')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn (string $state): string => AdminPermission::roleLabel($state))
                    ->description(fn (Role $record): string => $record->name),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Accounts')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->badge()
                    ->sortable(),

                Tables\Columns\IconColumn::make('protected')
                    ->label('Protected')
                    ->state(fn (Role $record): bool => AdminPermission::isProtectedRole($record->name))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open')
                    ->trueColor('warning')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Changed')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription('Deleting a role cannot be undone. Only unused, unprotected roles can be deleted.'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No roles')
            ->emptyStateDescription('Seed the RBAC catalogue or create a role to begin.');
    }

    /**
     * Filament keys each page by its route name and stores the routed Page object — not a
     * class-string. The old annotation here said the opposite, which is why the analyser
     * reported every one of these resources.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * The catalogue as a FLAT option map, filtered to what this operator may hand out.
     *
     * WHY THE LABEL CARRIES THE GROUP
     *
     * Filament 3's CheckboxList renders options() as a flat name => label map; handing it
     * nested arrays makes it call isOptionDisabled() with an array where it expects the
     * label, which is a 500 on the create page. That failure was caught by
     * RoleAdministrationTest::test_the_role_screens_actually_render_for_a_super_admin.
     * The group is therefore folded into the label — "Deposits · Approve a deposit" — so the
     * operator still sees which surface owns each decision without a component that does not
     * support grouping in this version.
     *
     * The permission NAME is what the form submits; the label is only ever displayed.
     *
     * @return array<string, string>
     */
    public static function grantableOptions(): array
    {
        $grantable = AdminAccess::grantable();

        $options = [];

        foreach (AdminPermission::groups() as $group => $permissions) {
            foreach ($permissions as $name => $label) {
                if (in_array($name, $grantable, true)) {
                    $options[$name] = sprintf('%s · %s', $group, $label);
                }
            }
        }

        return $options;
    }

    public static function isProtected(?Role $record): bool
    {
        return $record instanceof Role && AdminPermission::isProtectedRole($record->name);
    }
}
