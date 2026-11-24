<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Exceptions\RoleAdministrationException;
use App\Filament\Resources\RoleResource;
use App\Services\Admin\RoleAdministrationService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Creating a role goes through RoleAdministrationService, never through the model.
 *
 * A create is the moment least privilege is easiest to lose: the form offers only what
 * the actor may grant, but a forged Livewire payload offers everything. The service is
 * what actually refuses an escalation, writes the audit line and flushes the permission
 * cache, so a refusal here is reported as a notification rather than a 500.
 */
class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<string> $permissions */
        $permissions = array_values((array) ($data['granular_permissions'] ?? []));

        try {
            return app(RoleAdministrationService::class)->create((string) $data['name'], $permissions);
        } catch (RoleAdministrationException $e) {
            Notification::make()
                ->title('Role not created')
                ->body($e->getMessage())
                ->danger()
                ->send();

            // `halt()` is declared `: void` and throws; throwing the exception it throws is what
            // tells the analyser (and the next reader) that the method cannot fall through.
            throw new Halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return RoleResource::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Role created and audited';
    }

    /**
     * Filament types the record as Model; the service always returns a Role.
     */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Role) {
            Notification::make()
                ->title('Role ready')
                ->body(sprintf('[%s] now carries %d granular permission(s).', $record->name, $record->permissions()->count()))
                ->success()
                ->send();
        }
    }
}
