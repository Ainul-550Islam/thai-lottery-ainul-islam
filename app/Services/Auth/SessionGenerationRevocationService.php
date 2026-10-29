<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\SecuritySession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends every session an account has, everywhere, deterministically.
 *
 * THE MECHANISM
 * The account carries a monotonic `credential_epoch`. Every login stamps the
 * epoch onto its session, and the auth.session middleware retires a session
 * whose stamp is older than the account's current value. Revoking is
 * therefore ONE atomic increment on the users row — performed here, under a
 * row lock — plus deletion of the three credential stores (database
 * sessions, API access credentials, security sessions). The increment is the
 * guarantee; the deletions are hygiene, so nothing depends on finding every
 * session row to be safe.
 *
 * ONE PLACE, EVERY CALLER
 * The panel's explicit "revoke sessions" button, the automatic revocation
 * that follows suspend / ban / deactivate, and the password writers all come
 * through here, so the paths cannot drift apart: same epoch increment, same
 * deletions, same audit row (operator, reason code, generation delta,
 * removal counts).
 *
 * The password is NOT changed here and the account's status is not touched:
 * revocation is about sessions, and conflating it with either has hidden
 * real decisions behind a green notification before.
 */
final class SessionGenerationRevocationService
{
    public const REASON_OPERATOR_ACTION = 'operator_action';

    public const REASON_STATUS_CHANGE = 'status_change';

    public const REASON_PASSWORD_CHANGE = 'password_change';

    /**
     * Revoke every session of $target and return what was removed.
     *
     * @return array{
     *     counts: array{database_sessions: int, api_access_count: int, security_sessions: int},
     *     previous_epoch: int,
     *     credential_epoch: int
     * }
     */
    public function revoke(User $operator, User $target, string $cause = self::REASON_OPERATOR_ACTION): array
    {
        return DB::transaction(function () use ($operator, $target, $cause): array {
            $locked = User::query()->lockForUpdate()->find((int) $target->getKey());

            if (! $locked instanceof User) {
                throw new \RuntimeException('The account to revoke sessions for no longer exists.');
            }

            $previous = (int) ($locked->credential_epoch ?? 0);
            $epoch = $previous + 1;

            $locked->credential_epoch = $epoch;
            // Remember-me cookies bypass an old session row, so ending all
            // sessions also invalidates the account's persistent recaller.
            $locked->remember_token = null;
            if (! $locked->save()) {
                throw new \RuntimeException('Credential-generation revocation could not be persisted.');
            }

            $databaseSessions = DB::table('sessions')
                ->where('user_id', (int) $locked->getKey())
                ->delete();

            $apiCredentials = DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->where('tokenable_id', (int) $locked->getKey())
                ->delete();

            $securitySessions = SecuritySession::query()
                ->where('user_id', (int) $locked->getKey())
                ->delete();

            $counts = [
                'database_sessions' => (int) $databaseSessions,
                'api_access_count' => (int) $apiCredentials,
                'security_sessions' => (int) $securitySessions,
            ];

            AuditLog::query()->create([
                'user_id' => (int) $operator->getKey(),
                'action' => AuditAction::Update,
                'risk_level' => RiskLevel::High,
                'auditable_type' => User::class,
                'auditable_id' => (int) $locked->getKey(),
                'description' => sprintf('Session generation revoked (%s).', $cause),
                'old_values' => ['credential_epoch' => $previous],
                'new_values' => ['credential_epoch' => $epoch],
                'metadata' => [
                    'source' => 'session-generation-revocation',
                    'cause' => $cause,
                    'counts' => $counts,
                ],
            ]);

            $target->credential_epoch = $epoch;

            return [
                'counts' => $counts,
                'previous_epoch' => $previous,
                'credential_epoch' => $epoch,
            ];
        });
    }
}
