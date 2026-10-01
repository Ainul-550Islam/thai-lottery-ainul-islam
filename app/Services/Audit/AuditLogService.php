<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Universal Audit Logging & Redaction Service.
 *
 * Ensures all security, financial, and compliance events are recorded
 * with immutable timestamps and automatic sanitization of sensitive data
 * (passwords, tokens, CVVs, API secrets, private keys).
 */
class AuditLogService
{
    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'secret',
        'app_secret',
        'webhook_secret',
        'api_key',
        'token',
        'id_token',
        'access_token',
        'refresh_token',
        'pin',
        'cvv',
        'card_number',
        'private_key',
    ];

    /**
     * Record an audit event with automatic redaction.
     */
    public function log(
        ?int $userId,
        AuditAction|string $action,
        RiskLevel|string $riskLevel,
        Model|string|null $auditable,
        string $description,
        array $metadata = [],
        array $oldValues = [],
        array $newValues = [],
    ): AuditLog {
        $actionEnum = is_string($action) ? (AuditAction::tryFrom($action) ?? AuditAction::Update) : $action;
        $riskEnum = is_string($riskLevel) ? (RiskLevel::tryFrom($riskLevel) ?? RiskLevel::Medium) : $riskLevel;

        $auditableType = $auditable instanceof Model ? get_class($auditable) : (is_string($auditable) ? $auditable : null);
        $auditableId = $auditable instanceof Model ? (int) $auditable->getKey() : null;

        $log = new AuditLog();
        $log->fill([
            'user_id' => $userId,
            'action' => $actionEnum,
            'risk_level' => $riskEnum,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'description' => $description,
            'old_values' => $this->redact($oldValues),
            'new_values' => $this->redact($newValues),
            'metadata' => $this->redact($metadata),
        ]);
        $log->save();

        return $log;
    }

    /**
     * Recursively redact sensitive keys.
     */
    public function redact(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, self::REDACTED_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->redact($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
