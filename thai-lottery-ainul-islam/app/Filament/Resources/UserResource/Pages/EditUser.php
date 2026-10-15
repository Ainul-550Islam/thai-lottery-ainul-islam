<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AdminPasswordChangeService;
use App\Services\Auth\PasswordSessionBoundary;
use App\Support\Admin\AdminAccess;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Profile corrections and explicitly authorized password replacement.
 *
 * WHY mutateFormDataBeforeFill EXISTS HERE
 * Filament fills a form from the model's attributes, and `password` is an attribute — the
 * bcrypt hash would otherwise land in the password input and be submitted as a new
 * plaintext password to the operator service. Stripping it before
 * fill is what makes "leave blank to keep the current password" true rather than an
 * aspiration. The matching half is UserResource's `->dehydrated(filled($state))`, which
 * removes the key from the payload when it is blank, so saving a phone number cannot
 * reset a password.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No status, roles, verification timestamps or wallet fields in this form.
 *   Status and role decisions remain separate actions on the view screen.
 * - No role/status/verification policy changes. Password writes now use the shared
 *   revocation primitives through an operator-authorized transaction; profile-only
 *   edits retain the existing model update behavior.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected bool $passwordReauthenticationRequired = false;

    /**
     * @return array<int, Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password']);

        return $data;
    }

    /**
     * Belt and braces: a blank password never reaches the model even if the dehydration
     * rule is ever relaxed. An empty string would otherwise be hashed into a valid
     * credential nobody knows.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! filled($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! filled($data['password'] ?? null)) {
            unset($data['password']);

            return parent::handleRecordUpdate($record, $data);
        }
        $operator = AdminAccess::operator();
        if (! $operator instanceof User || ! $record instanceof User) {
            throw new AuthorizationException('A panel operator and user target are required.');
        }
        $boundary = app(PasswordSessionBoundary::class);
        // Refuse enclosing HTTP transactions before mutation; no browser logout pre-commit.
        $boundary->prepare(request(), $record);
        $password = $data['password'];
        unset($data['password']);
        $updated = app(AdminPasswordChangeService::class)->change($operator, $record, $password, $data);
        $record->setRawAttributes($updated->getAttributes(), true);
        $this->passwordReauthenticationRequired = $operator->is($record);
        $boundary->finish(request(), $record);

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        if ($this->passwordReauthenticationRequired) {
            return Filament::getLoginUrl();
        }

        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
