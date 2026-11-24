<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Exceptions\RoleAdministrationException;
use App\Filament\Resources\RoleResource;
use App\Services\Admin\RoleAdministrationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Editing a role: a rename, a grant set, or both — each through the service.
 *
 * WHY THE FORM IS FILLED FROM THE SERVICE AND NOT FROM THE RELATIONSHIP
 *
 * `granular_permissions` is not a column. Filling it from `$record->permissions` would
 * put the coarse phrases into a checkbox list whose options are granular names, and
 * Filament would then submit an unchanged set that includes them — the service would
 * refuse, or worse, a future edit would revoke the wrong rows. The service answers
 * with the granular subset only, and that is what the form shows.
 */
class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        $data['granular_permissions'] = $record instanceof Role
            ? app(RoleAdministrationService::class)->granularPermissionsOf($record)
            : [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Role) {
            return $record;
        }

        $service = app(RoleAdministrationService::class);

        /** @var list<string> $permissions */
        $permissions = array_values((array) ($data['granular_permissions'] ?? []));

        try {
            if (isset($data['name']) && (string) $data['name'] !== $record->name) {
                $service->rename($record, (string) $data['name']);
                $record->refresh();
            }

            $service->syncGranularPermissions($record, $permissions);
        } catch (RoleAdministrationException $e) {
            Notification::make()
                ->title('Role not changed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }

        return $record->refresh();
    }

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => RoleResource::canDelete($this->getRecord()))
                ->requiresConfirmation(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Role updated and audited';
    }
}
