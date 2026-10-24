<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Universal Audit Logging & Redaction Service.
 *
 * Ensures all security, financial, and compliance events are recorded
 * with immutable timestamps, high-visibility failure telemetry, and
 * automatic sanitization of sensitive secrets and PII.
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
        'webhook_signature',
        'api_key',
        'token',
        'id_token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'session_id',
        'otp',
        'totp_secret',
        'recovery_code',
        'recovery_codes',
        'pin',
        'cvv',
        'card_number',
        'private_key',
        'national_id',
        'document_number',
        'ssn',
        'bank_account_number',
        'iban',
    ];

    /**
     * Record an audit event with automatic redaction and critical persistence failure visibility.
     */
    public function log(
        ?int $userId,
        AuditAction|string $action,
        RiskLevel|string $riskLevel,
        ?Model $auditable,
        string $description,
        array $metadata = [],
        array $oldValues = [],
        array $newValues = [],
    ): AuditLog {
        $actionEnum = is_string($action) ? (AuditAction::tryFrom($action) ?? AuditAction::Update) : $action;
        $riskEnum = is_string($riskLevel) ? (RiskLevel::tryFrom($riskLevel) ?? RiskLevel::Medium) : $riskLevel;

        $subjectKey = $auditable instanceof Model ? $auditable->getKey() : null;
        $hasNumericId = is_int($subjectKey)
            ? $subjectKey > 0
            : (is_string($subjectKey) && ctype_digit($subjectKey) && (int) $subjectKey > 0);
        $auditableType = $hasNumericId && $auditable instanceof Model ? get_class($auditable) : null;
        $auditableId = $hasNumericId ? (int) $subjectKey : null;

        if (! $hasNumericId && $auditable instanceof Model && $subjectKey !== null) {
            $metadata = array_merge([
                'subject_type' => get_class($auditable),
                'subject_id' => (string) $subjectKey,
            ], $metadata);
        }

        $log = new AuditLog;
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

        try {
            $log->save();
        } catch (Throwable $e) {
            Log::critical('security.audit.persistence_failed', [
                'user_id' => $userId,
                'action' => $actionEnum->value,
                'risk_level' => $riskEnum->value,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

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
