<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\UserStatus;
use App\Models\IpAccessRule;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/** Trusted server callers only. No HTTP/admin endpoint or permission grant here. */
final class IpAccessRuleManager
{
    public const MANAGE_PERMISSION = 'manage ip access rules';

    public function __construct(private readonly AuditLogService $audit, private readonly IpRangeMatcher $matcher, private readonly ?IpRuleCapacityGuard $capacity = null) {}

    /** Preflight only; every mutation repeats authorization inside its own transaction. */
    public function assertAuthorized(User $actor): void
    {
        DB::transaction(fn () => $this->authorize($actor));
    }

    public function create(User $actor, string $range, string $reasonCode, ?int $expiresAt = null, ?int $startsAt = null, ?string $idempotencyKey = null): IpAccessRule
    {
        return DB::transaction(function () use ($actor, $range, $reasonCode, $expiresAt, $startsAt, $idempotencyKey): IpAccessRule {
            $operator = $this->authorize($actor);
            $this->validateReason($reasonCode);
            $normalized = $this->matcher->normalizeRange($range);
            if ($normalized === null) {
                throw ValidationException::withMessages(['ip_rule' => 'Invalid IP rule range or lifetime.']);
            }
            // Fingerprint requested values, not the moving default server start time.
            $fingerprint = hash('sha256', json_encode([$normalized, $reasonCode, $expiresAt, $startsAt], JSON_THROW_ON_ERROR));
            if ($idempotencyKey !== null) {
                if (! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $idempotencyKey)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Use a 16–128 character opaque alphanumeric, hyphen or underscore key.']);
                }
                // The actor row is already locked. Avoid empty-range gap locks before
                // the global capacity mutex; uniqueness is the final conflict backstop.
                $prior = DB::table('ip_rule_create_requests')->useWritePdo()->where('actor_id', $operator->getKey())
                    ->where('key_hash', hash('sha256', $idempotencyKey))->first();
                if ($prior !== null) {
                    if (! hash_equals($prior->fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This key was already used with different create input.']);
                    }

                    // Current lifecycle is returned: a replay never resurrects a revoked/expired rule.
                    $replayed = IpAccessRule::query()->lockForUpdate()->findOrFail($prior->rule_id);
                    $replayed->validatedRange();

                    return $replayed;
                }
            }
            $now = Carbon::now()->getTimestamp();
            $start = $startsAt ?? $now;
            if ($normalized === null || $start < $now || $start > 253402300799
                || ($expiresAt !== null && ($expiresAt <= $start || $expiresAt > 253402300799))) {
                throw ValidationException::withMessages(['ip_rule' => 'Invalid IP rule range or lifetime.']);
            }
            ($this->capacity ?? new IpRuleCapacityGuard)->assertCreateCapacity($now);
            $rule = new IpAccessRule;
            $rule->forceFill([
                'ip_range' => $normalized, 'reason_code' => $reasonCode,
                'starts_at_epoch' => $start, 'expires_at_epoch' => $expiresAt,
                'created_by' => $operator->getKey(),
            ]);
            $rule->save();
            $this->audit->log(
                userId: (int) $operator->getKey(), action: AuditAction::Create,
                riskLevel: RiskLevel::High, auditable: $rule,
                description: 'IP access rule created.',
                metadata: ['schema_version' => 1, 'operation' => 'create', 'reason_code' => $reasonCode],
                newValues: $this->snapshot($rule),
            );

            if ($idempotencyKey !== null) {
                DB::table('ip_rule_create_requests')->insert([
                    'actor_id' => $operator->getKey(), 'key_hash' => hash('sha256', $idempotencyKey),
                    'fingerprint' => $fingerprint, 'rule_id' => $rule->getKey(), 'created_at' => Carbon::now(),
                ]);
            }

            return $rule;
        });
    }

    public function revoke(User $actor, int $ruleId, string $reasonCode): IpAccessRule
    {
        return DB::transaction(function () use ($actor, $ruleId, $reasonCode): IpAccessRule {
            $operator = $this->authorize($actor);
            $this->validateReason($reasonCode);
            $rule = IpAccessRule::query()->lockForUpdate()->findOrFail($ruleId);
            if ($rule->revoked_at_epoch !== null) {
                // Sequential retry is a no-op: preserve original revocation/audit.
                return $rule;
            }
            $before = $this->snapshot($rule);
            $rule->revoked_at_epoch = Carbon::now()->getTimestamp();
            $rule->save();
            $this->audit->log(
                userId: (int) $operator->getKey(), action: AuditAction::Update,
                riskLevel: RiskLevel::High, auditable: $rule,
                description: 'IP access rule revoked.',
                metadata: ['schema_version' => 1, 'operation' => 'revoke', 'reason_code' => $reasonCode],
                oldValues: $before, newValues: $this->snapshot($rule),
            );

            return $rule;
        });
    }

    private function authorize(User $actor): User
    {
        if (! $actor->exists || ! $actor->getKey()) {
            throw new AuthorizationException;
        }
        // Never trust caller-supplied/stale status or eager-loaded roles.
        $operator = User::query()->lockForUpdate()->find($actor->getKey());
        if (! $operator instanceof User || $operator->status !== UserStatus::Active) {
            throw new AuthorizationException;
        }
        $allowed = $operator->hasRole('super-admin', 'web');
        if (! $allowed && $operator->hasRole('admin', 'web')) {
            try {
                $allowed = $operator->hasPermissionTo(self::MANAGE_PERMISSION, 'web');
            } catch (PermissionDoesNotExist) {
                $allowed = false;
            }
        }
        if (! $allowed) {
            throw new AuthorizationException;
        }

        return $operator;
    }

    private function validateReason(string $reasonCode): void
    {
        if (! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $reasonCode)) {
            throw ValidationException::withMessages(['ip_rule' => 'Invalid IP rule reason code.']);
        }
    }

    private function snapshot(IpAccessRule $rule): array
    {
        // Target range is authorized audit data, NOT the operator's network IP.
        return [
            'ip_range' => $rule->ip_range, 'reason_code' => $rule->reason_code,
            'starts_at_epoch' => $rule->starts_at_epoch, 'expires_at_epoch' => $rule->expires_at_epoch,
            'revoked_at_epoch' => $rule->revoked_at_epoch, 'created_by' => $rule->created_by,
        ];
    }
}
