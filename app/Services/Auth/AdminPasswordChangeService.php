<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\Security\SecurityEventData;
use App\Enums\SecurityEventType;
use App\Enums\SecurityRiskLevel;
use App\Models\User;
use App\Services\Security\SecurityEventService;
use App\Services\Security\TotpActionProofInvalidationService;
use App\Services\Security\UserSessionSecurityService;
use App\Support\Admin\AdminAccess;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/** Audited admin password replacement with transactional credential/session revocation. */
final class AdminPasswordChangeService
{
    public function __construct(
        private readonly UserSessionSecurityService $sessions,
        private readonly TotpActionProofInvalidationService $proofs,
        private readonly SecurityEventService $events,
    ) {}

    /**
     * @param array<string, mixed> $profileChanges Only the UserResource profile fields are accepted.
     */
    public function change(User $operator, User $target, string $password, array $profileChanges = []): User
    {
        if (! $operator->exists || $operator->getKey() === null || ! AdminAccess::allows($operator, AdminAccess::MANAGE_USERS)) {
            throw new RuntimeException('An authorized user manager is required for a password replacement.');
        }
        if (! $target->exists || $target->getKey() === null) {
            throw new RuntimeException('A persisted password target is required.');
        }
        if (strlen($password) < 8 || strlen($password) > 255) {
            throw new InvalidArgumentException('The replacement password must contain between 8 and 255 bytes.');
        }
        if (! $operator->getConnection() instanceof ConnectionInterface
            || ! $target->getConnection() instanceof ConnectionInterface
            || $operator->getConnection() !== DB::connection()
            || $target->getConnection() !== DB::connection()) {
            throw new RuntimeException('Admin password changes require the default database connection.');
        }

        $allowedProfileFields = ['name', 'username', 'email', 'phone'];
        $safeChanges = array_intersect_key($profileChanges, array_flip($allowedProfileFields));
        foreach ($safeChanges as $key => $value) {
            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException('Profile changes must be strings or null.');
            }
            if (is_string($value) && strlen($value) > 255) {
                throw new InvalidArgumentException('Profile changes exceed the supported length.');
            }
        }

        return DB::transaction(function () use ($operator, $target, $password, $safeChanges): User {
            /** @var User|null $locked */
            $locked = User::query()->useWritePdo()->lockForUpdate()->find($target->getKey());
            if (! $locked instanceof User) {
                throw new RuntimeException('The password target no longer exists.');
            }

            $locked->fill($safeChanges);
            $locked->password = $password; // User's hashed cast hashes exactly once.
            $locked->remember_token = null;
            if (! $locked->save()) {
                throw new RuntimeException('The admin password replacement was not persisted.');
            }

            $this->proofs->invalidate($locked, TotpActionProofInvalidationService::PASSWORD_CHANGED);
            $this->sessions->revokeAllSessionsFor((int) $locked->getKey(), 'administrator password replacement', 'admin:'.$operator->getKey());
            if ((bool) config('auth_security.password_reset.revoke_sessions', true) && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $locked->getKey())->delete();
            }
            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::PasswordChanged,
                'user_id' => (int) $locked->getKey(),
                'risk_level' => SecurityRiskLevel::Medium,
                'payload' => [
                    'actor_user_id' => (int) $operator->getKey(),
                    'change_source' => 'admin_panel',
                    'sessions_revoked' => true,
                ],
            ]));

            return $locked->refresh();
        });
    }
}
