<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PermissionResource\Pages;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminPermission;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;

/**
 * The permission catalogue, readable and immutable.
 *
 * WHY THERE IS NO FORM
 *
 * A permission is a name that code asks about. Creating one through a form produces a
 * row nobody authorises against — the definition of a decorative grant — and deleting
 * one silently turns a working screen off. So this resource is a reference: it answers
 * "what does 'deposits.approve' actually allow, which group owns it, and which roles
 * carry it?", and every change to the catalogue happens in config/admin.php with a
 * seeder run, reviewed like any other code change.
 *
 * WHAT IT SHOWS
 *
 *   - the machine name and its human label,
 *   - the owning group (Users, Deposits, Compliance, …),
 *   - how many roles carry it today,
 *   - and whether it has a coarse legacy equivalent, which is the fact an operator
 *     needs when deciding whether the transition bridge can be switched off.
 */
class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Permissions';

    protected static ?string $modelLabel = 'permission';

    protected static ?string $pluralModelLabel = 'permissions';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return AdminAccess::permits(AdminPermission::PERMISSIONS_VIEW);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Permission && static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('roles'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Permission')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable()
                    ->description(fn (Permission $record): string => AdminPermission::label($record->name)),

                Tables\Columns\TextColumn::make('group')
                    ->label('Group')
                    ->state(fn (Permission $record): string => AdminPermission::groupFor($record->name))
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('roles_count')
                    ->label('Roles')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('legacy_equivalent')
                    ->label('Coarse equivalent')
                    ->state(fn (Permission $record): string => AdminPermission::legacyEquivalent($record->name) ?? '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('guard_name')
                    ->label('Guard')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->label('Group')
                    ->options(fn (): array => collect(array_keys(AdminPermission::groups()))
                        ->mapWithKeys(fn (string $group): array => [$group => $group])
                        ->all())
                    ->query(function ($query, array $data) {
                        $group = $data['value'] ?? null;

                        if (! is_string($group) || $group === '') {
                            return $query;
                        }

                        $names = array_keys(AdminPermission::groups()[$group] ?? []);

                        return $names === [] ? $query->whereRaw('1 = 0') : $query->whereIn('name', $names);
                    }),

                Tables\Filters\TernaryFilter::make('granted')
                    ->label('Carried by a role')
                    ->placeholder('Any')
                    ->trueLabel('Granted')
                    ->falseLabel('Ungranted')
                    ->queries(
                        true: fn ($query) => $query->has('roles'),
                        false: fn ($query) => $query->doesntHave('roles'),
                    ),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('No permissions')
            ->emptyStateDescription('Seed the granular catalogue: php artisan db:seed --class=AdminRbacSeeder');
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
            'index' => Pages\ListPermissions::route('/'),
        ];
    }
}
