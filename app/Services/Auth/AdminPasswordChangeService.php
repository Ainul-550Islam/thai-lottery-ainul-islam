<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Changes another account's password on an operator's behalf, from the admin panel.
 *
 * GUARDS
 *  - The operator must hold MANAGE_USERS, checked here and not only by the page.
 *  - The password must be at least 8 characters, matching the form rule, and is
 *    never written to the audit trail.
 *  - Only name and email change through this path. Status, roles and every other
 *    attribute are dropped, because those are decisions with their own audit lines.
 *  - User::casts() hashes the password on assignment. Hashing here as well would
 *    hash the hash and lock the account out, so the value is assigned plain.
 *  - The target row is locked, the change and its audit row commit together, and
 *    the credential generation is advanced when the schema records one. A schema
 *    without that column keeps hash-only session behaviour.
 */
final class AdminPasswordChangeService
{
    private const MIN_LENGTH = 8;

    /** @var list<string> */
    private const EDITABLE = ['name', 'email'];

    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function change(User $operator, User $target, string $password, array $attributes = []): User
    {
        if (! AdminAccess::allows($operator, AdminAccess::MANAGE_USERS)) {
            throw new AuthorizationException('You may not change this account\'s password.');
        }

        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw ValidationException::withMessages([
                'password' => sprintf('The password must be at least %d characters.', self::MIN_LENGTH),
            ]);
        }

        $editable = array_intersect_key($attributes, array_flip(self::EDITABLE));

        return DB::transaction(function () use ($operator, $target, $password, $editable): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $before = ['password_changed' => false];

            $locked->fill($editable);
            $locked->password = $password;

            $hasEpoch = Schema::hasColumn($locked->getTable(), 'credential_epoch');

            if ($hasEpoch) {
                $locked->credential_epoch = (int) $locked->credential_epoch + 1;
            }

            $locked->save();

            $this->audit->log(
                (int) $operator->getKey(),
                AuditAction::Update,
                RiskLevel::High,
                $locked,
                'Operator changed the password for '.$locked->username.'.',
                [
                    'operation' => 'admin_password_change',
                    'changed_fields' => array_values(array_merge(array_keys($editable), ['password'])),
                    'credential_generation_advanced' => $hasEpoch,
                ],
                $before,
                ['password_changed' => true],
            );

            return $locked->refresh();
        });
    }
}
