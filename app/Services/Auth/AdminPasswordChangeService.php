<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\User;
use App\Rules\StrongPasswordRule;
use App\Services\Audit\AuditLogService;
use App\Services\Security\TotpActionProofInvalidationService;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Changes another account's password on an operator's behalf, from the admin panel.
 *
 * GUARDS
 *  - The operator must hold MANAGE_USERS, checked here and not only by the page.
 *  - The same centralized password policy as registration applies, and the
 *    password is never written to the audit trail.
 *  - Only validated profile fields change through this path. Status, roles and
 *    other attributes are dropped because those need separate decisions/audits.
 *  - User::casts() hashes the password on assignment. Hashing here as well would
 *    hash the hash and lock the account out, so the value is assigned plain.
 *  - The target row, credential generation, session/token revocation, durable
 *    TOTP proof revocation and audit rows share one transaction.
 */
final class AdminPasswordChangeService
{
    /** @var list<string> */
    private const EDITABLE = ['name', 'username', 'email', 'phone'];

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly SessionGenerationRevocationService $revocations,
        private readonly TotpActionProofInvalidationService $proofs,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function change(User $operator, User $target, string $password, array $attributes = []): User
    {
        if (! AdminAccess::allows($operator, AdminAccess::MANAGE_USERS)) {
            throw new AuthorizationException('You may not change this account\'s password.');
        }

        $editable = array_intersect_key($attributes, array_flip(self::EDITABLE));
        Validator::make(['password' => $password] + $editable, [
            'password' => ['required', 'string', new StrongPasswordRule],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'username' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($target->getKey())],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target->getKey())],
            'phone' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('users', 'phone')->ignore($target->getKey())],
        ])->validate();

        return DB::transaction(function () use ($operator, $target, $password, $editable): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $before = ['password_changed' => false];

            $locked->fill($editable);
            $locked->password = $password;
            $locked->remember_token = null;
            if (! $locked->save()) {
                throw new \RuntimeException('Operator password change persistence was refused.');
            }
            $this->revocations->revoke($operator, $locked, SessionGenerationRevocationService::REASON_PASSWORD_CHANGE);
            $this->proofs->invalidate($locked, TotpActionProofInvalidationService::PASSWORD_CHANGED);

            $this->audit->log(
                (int) $operator->getKey(),
                AuditAction::Update,
                RiskLevel::High,
                $locked,
                'Operator changed the password for '.$locked->username.'.',
                [
                    'operation' => 'admin_password_change',
                    'changed_fields' => array_values(array_merge(array_keys($editable), ['password'])),
                    'credential_generation_advanced' => true,
                ],
                $before,
                ['password_changed' => true],
            );

            return $locked->refresh();
        });
    }
}
