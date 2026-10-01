<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only release, configuration, disaster-recovery, and security
 * operations projection.
 *
 * This controller reports evidence that is available to the application. It
 * does not run shell commands, migrations, restores, cache flushes, key
 * rotations, deployment actions, or database mutations from a browser route.
 * Missing deployment metadata is reported explicitly instead of becoming a
 * fabricated green check.
 */
final class ReleaseOperationsController extends Controller
{
    public function show(Request $request, string $surface, ?string $reference = null): View
    {
        $this->authorizeSurface($request, $surface);

        return view('admin.release-operations', [
            'surface' => $surface,
            'reference' => $reference,
            'projection' => $this->projection($surface, $reference),
        ]);
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function projection(string $surface, ?string $reference): array
    {
        return match ($surface) {
            'cutover' => $this->cutoverProjection(),
            'manifest' => $this->manifestProjection(),
            'configuration' => $this->configurationProjection(),
            'secrets' => $this->secretsProjection(),
            'migrations' => $this->migrationProjection(),
            'backups' => $this->backupProjection(),
            'restore' => $this->restoreProjection(),
            'disaster-recovery' => $this->disasterRecoveryProjection(),
            'high-availability' => $this->highAvailabilityProjection(),
            'incidents' => $this->incidentProjection($reference),
            'deployment-approval' => $this->deploymentApprovalProjection(),
            'deployments' => $this->deploymentHistoryProjection(),
            'rollback' => $this->rollbackProjection(),
            'feature-flags' => $this->featureFlagProjection(),
            'configuration-audit' => $this->configurationAuditProjection(),
            'sessions' => $this->notConfiguredProjection([
                'current_sessions', 'last_activity', 'ip_representation', 'device_metadata', 'session_age', 'session_state',
            ], 'Session inventory requires the canonical secure session store.'),
            'access-review' => $this->notConfiguredProjection([
                'operator_account_status', 'role', 'assigned_permissions', 'last_login', 'mfa_state', 'suspicious_access_state',
            ], 'Operator access review requires the canonical operator identity and permission projection.'),
            'privileged-access' => $this->notConfiguredProjection([
                'wallet_management', 'payout_management', 'reconciliation', 'glo_claims', 'freeze_review', 'draw_management', 'system_settings',
            ], 'Privileged access review requires policy-backed operator records.'),
            'permission-matrix' => $this->permissionMatrixProjection(),
            'service-accounts' => $this->notConfiguredProjection([
                'service_identifier', 'environment', 'status', 'purpose', 'last_used', 'rotation_state',
            ], 'Service-account inventory is not configured.'),
            'network-access' => $this->notConfiguredProjection([
                'allowlist', 'denylist', 'trusted_proxy', 'admin_network_restriction',
            ], 'Network access evidence requires deployment-level trusted-proxy and network configuration.'),
            'device-risk' => $this->notConfiguredProjection([
                'unusual_device_count', 'simultaneous_sessions', 'session_changes', 'failed_authentication_indicators', 'revocation_state',
            ], 'Documented device-risk signals are not configured for this projection.'),
            'mfa' => $this->notConfiguredProjection([
                'enabled', 'disabled', 'enrollment_required', 'recovery_state', 'last_verification',
            ], 'MFA operational records are not configured for this projection.'),
            'authentication-security' => $this->notConfiguredProjection([
                'login_attempts', 'failed_login_counts', 'password_reset_attempts', 'account_lock_events', 'captcha_failures', 'authentication_anomalies',
            ], 'Authentication security aggregation requires canonical security-event telemetry.'),
            'rate-limits' => $this->rateLimitProjection(),
            'captcha' => $this->captchaProjection(),
            'risk-rules' => $this->notConfiguredProjection([
                'duplicate_account_rules', 'payment_anomaly_rules', 'rapid_deposit_rules', 'rapid_withdrawal_rules', 'failed_transaction_rules',
            ], 'Only explicit configured risk rules may be displayed; no opaque score is fabricated.'),
            'suspicious-activity' => $this->notConfiguredProjection([
                'case_id', 'reason', 'source_event', 'account_reference', 'state', 'assigned_operator', 'created_at', 'resolved_at',
            ], 'Suspicious-activity cases require a canonical case store.'),
            'compliance-cases' => $this->notConfiguredProjection([
                'evidence_references', 'payment_references', 'related_bets', 'kyc_state', 'account_restrictions', 'operator_actions', 'resolution',
            ], 'Compliance case detail requires a policy-protected case projection.'),
            'sanctions' => $this->notConfiguredProjection([
                'provider', 'configured', 'last_verification', 'api_health', 'failure_state',
            ], 'Sanctions/watchlist state is not claimed without a configured provider.'),
            'kyc' => $this->notConfiguredProjection([
                'identity_verification_state', 'document_state', 'provider_reference', 'review_state', 'verification_at',
            ], 'KYC state requires the canonical identity-verification service.'),
            'kyc-provider' => $this->notConfiguredProjection([
                'provider', 'configured', 'api_health', 'credential_presence', 'last_callback', 'failure_state',
            ], 'KYC provider state is not claimed without configured provider evidence.'),
            'kyc-review' => $this->notConfiguredProjection([
                'review_reference', 'queue_state', 'assigned_reviewer', 'evidence_state', 'decision_state', 'decision_at',
            ], 'KYC review queues require a policy-protected review store.'),
            'age-verification' => $this->notConfiguredProjection([
                'minimum_age_policy', 'verification_state', 'underage_restriction_state', 'evidence_state',
            ], 'Age-verification state requires the canonical account and compliance services.'),
            'duplicate-accounts' => $this->notConfiguredProjection([
                'rule_state', 'linked_account_count', 'review_state', 'restriction_state', 'false_positive_review',
            ], 'Duplicate-account detection requires canonical risk signals; no match result is fabricated.'),
            'account-restrictions' => $this->notConfiguredProjection([
                'restriction_type', 'scope', 'reason', 'effective_at', 'expires_at', 'review_state',
            ], 'Account restrictions require an authorized canonical restriction ledger.'),
            'retention' => $this->notConfiguredProjection([
                'record_class', 'retention_period', 'legal_hold_state', 'deletion_state', 'last_review',
            ], 'Retention schedules and deletion evidence are not connected to this projection.'),
            'privacy' => $this->notConfiguredProjection([
                'consent_state', 'privacy_policy_version', 'data_processing_basis', 'sharing_state', 'withdrawal_state',
            ], 'Privacy and consent records require the canonical privacy service.'),
            'data-rights' => $this->notConfiguredProjection([
                'request_reference', 'request_type', 'identity_verification_state', 'fulfillment_state', 'due_at', 'completed_at',
            ], 'Data-rights request evidence requires a protected privacy request store.'),
            'legal-registries' => $this->notConfiguredProjection([
                'registry_name', 'jurisdiction', 'registration_state', 'renewal_at', 'evidence_reference',
            ], 'Legal registry evidence must come from the canonical compliance registry.'),
            'compliance-reporting' => $this->notConfiguredProjection([
                'report_type', 'period', 'submission_state', 'submission_reference', 'accepted_at',
            ], 'Compliance submissions are not claimed without submission evidence.'),
            'aml-monitoring' => $this->notConfiguredProjection([
                'rule_state', 'alert_count', 'case_count', 'review_state', 'reporting_state',
            ], 'AML monitoring evidence requires canonical alerts and case data.'),
            'regulatory-exports' => $this->notConfiguredProjection([
                'export_type', 'period', 'row_count', 'integrity_hash', 'delivery_state',
            ], 'Regulatory export evidence is not available without a canonical export registry.'),
            'compliance-audit' => $this->notConfiguredProjection([
                'control', 'owner', 'evidence_state', 'exception_state', 'last_review', 'next_review',
            ], 'Compliance control evidence requires an immutable audit source.'),
            default => [
                'state' => 'NOT_CONFIGURED',
                'rows' => [],
                'note' => 'The requested operational projection is not configured.',
            ],
        };
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function cutoverProjection(): array
    {
        $checks = [
            $this->artifactCheck('release_artifact', base_path('composer.lock')),
            $this->artifactCheck('asset_manifest', public_path('build/manifest.json')),
            $this->artifactCheck('dependency_lock', base_path('package-lock.json')),
            $this->artifactCheck('rust_lock', base_path('security/weekly-result-integrity/Cargo.lock')),
            [
                'label' => 'application_environment',
                'value' => (string) config('app.env', 'NOT_CONFIGURED'),
                'state' => config('app.env') !== null ? 'AVAILABLE' : 'NOT_CONFIGURED',
            ],
            [
                'label' => 'application_url',
                'value' => (string) (config('app.url') ?: 'NOT_CONFIGURED'),
                'state' => config('app.url') ? 'AVAILABLE' : 'NOT_CONFIGURED',
            ],
            $this->unverified('database_backup'),
            $this->unverified('database_migration'),
            $this->unverified('payment_provider_configuration'),
            $this->unverified('webhook_verification'),
            $this->unverified('wallet_ledger_reconciliation'),
            $this->unverified('kyc_private_storage'),
            $this->unverified('queue_workers'),
            $this->unverified('scheduler'),
            $this->unverified('rollback_readiness'),
        ];

        return [
            'state' => $this->aggregateState($checks),
            'rows' => $checks,
            'note' => 'A release gate is not marked complete unless the application has direct evidence for that gate.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function manifestProjection(): array
    {
        $rows = [
            $this->configuredMetadata('commit_sha', config('app.release_commit')),
            $this->configuredMetadata('build_id', config('app.release_build_id')),
            $this->configuredMetadata('application_version', config('app.version')),
            $this->configuredMetadata('migration_version', config('app.migration_version')),
            $this->artifactCheck('asset_build_fingerprint', public_path('build/manifest.json')),
            $this->artifactCheck('composer_lock_hash', base_path('composer.lock')),
            $this->artifactCheck('dependency_lock_hash', base_path('package-lock.json')),
            $this->artifactCheck('rust_binary_hash', base_path('security/weekly-result-integrity/target/release/weekly-result-integrity')),
            $this->configuredMetadata('environment_identifier', config('app.env')),
            [
                'label' => 'generated_at',
                'value' => now()->toIso8601String(),
                'state' => 'AVAILABLE',
            ],
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'Hashes are calculated only for files that exist in the application filesystem. Missing deploy metadata is not inferred.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function configurationProjection(): array
    {
        $rows = [
            $this->configuredMetadata('application_environment', config('app.env')),
            $this->configuredMetadata('application_url', config('app.url')),
            $this->configuredMetadata('database_driver', config('database.default')),
            $this->configuredMetadata('queue_driver', config('queue.default')),
            $this->configuredMetadata('cache_driver', config('cache.default')),
            $this->configuredMetadata('mail_driver', config('mail.default')),
            $this->configuredMetadata('payment_configuration', $this->hasConfiguredArray(config('payment')) ? 'configured' : null),
            $this->configuredMetadata('kyc_configuration', $this->hasConfiguredArray(config('account_verification')) ? 'configured' : null),
            $this->configuredMetadata('captcha_configuration', $this->hasConfiguredArray(config('captcha')) ? 'configured' : null),
            $this->configuredMetadata('storage_driver', config('filesystems.default')),
            $this->unverified('cdn_reverse_proxy'),
            $this->unverified('rust_engine_mode'),
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'Secret values and provider credentials are never returned by this projection.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function secretsProjection(): array
    {
        $rows = [
            [
                'label' => 'application_key_exists',
                'value' => is_string(config('app.key')) && config('app.key') !== '' ? 'true' : 'false',
                'state' => is_string(config('app.key')) && config('app.key') !== '' ? 'AVAILABLE' : 'NOT_CONFIGURED',
            ],
            $this->configuredMetadata('payment_provider_key_presence', $this->hasConfiguredArray(config('payment')) ? 'configured' : null),
            $this->configuredMetadata('webhook_secret_presence', $this->hasConfiguredArray(config('payment.webhooks')) ? 'configured' : null),
            $this->unverified('key_age'),
            $this->unverified('rotation_status'),
            $this->unverified('last_key_verification'),
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'Only presence and verification metadata are displayed. Secret material is never exposed.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function migrationProjection(): array
    {
        $migrationFiles = glob(database_path('migrations/*.php')) ?: [];
        $rows = [
            [
                'label' => 'migration_files_present',
                'value' => (string) count($migrationFiles),
                'state' => $migrationFiles !== [] ? 'AVAILABLE' : 'NO_DATA',
            ],
            $this->unverified('current_schema_version'),
            $this->unverified('pending_migrations'),
            $this->unverified('last_migration'),
            $this->unverified('migration_batch'),
            $this->unverified('destructive_migration_warning'),
            $this->unverified('migration_lock'),
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'Migration execution is intentionally excluded from browser operations.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function backupProjection(): array
    {
        $directory = storage_path('backups');
        $files = is_dir($directory) ? (glob($directory.'/*') ?: []) : [];
        $latest = collect($files)
            ->filter(static fn (string $path): bool => is_file($path))
            ->sortByDesc(static fn (string $path): int => (int) @filemtime($path))
            ->first();

        $rows = [
            [
                'label' => 'backup_directory',
                'value' => is_dir($directory) ? 'configured' : 'NOT_CONFIGURED',
                'state' => is_dir($directory) ? 'AVAILABLE' : 'NOT_CONFIGURED',
            ],
            [
                'label' => 'latest_backup_reference',
                'value' => is_string($latest) ? basename($latest) : 'NO_DATA',
                'state' => is_string($latest) ? 'AVAILABLE' : 'NO_DATA',
            ],
            [
                'label' => 'latest_backup_timestamp',
                'value' => is_string($latest) && is_file($latest) && filemtime($latest) !== false
                    ? date(DATE_ATOM, (int) filemtime($latest))
                    : 'NO_DATA',
                'state' => is_string($latest) ? 'AVAILABLE' : 'NO_DATA',
            ],
            $this->unverified('backup_checksum'),
            $this->unverified('retention_policy'),
            $this->unverified('encryption_state'),
            $this->unverified('restore_verification'),
        ];

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'A filesystem entry is not treated as a verified, restorable, encrypted backup without backup evidence.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function restoreProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('restore_request_record'),
                $this->unverified('target_environment_validation'),
                $this->unverified('restore_artifact'),
                $this->unverified('restore_checksum'),
                $this->unverified('restore_operator'),
                $this->unverified('restore_outcome'),
            ],
            'note' => 'Browser-triggered production restore is disabled. A controlled non-production restore service is required before this surface can execute anything.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function disasterRecoveryProjection(): array
    {
        return [
            'state' => 'NOT_VERIFIED',
            'rows' => [
                $this->unverified('rpo_target'),
                $this->unverified('rto_target'),
                $this->unverified('backup_age'),
                $this->unverified('restore_verification'),
                $this->unverified('database_replica_state'),
                $this->unverified('queue_recovery_state'),
                $this->unverified('payment_callback_recovery'),
                $this->unverified('dns_recovery_state'),
                $this->unverified('rust_engine_recovery_state'),
            ],
            'note' => 'Disaster-recovery claims require deployment and infrastructure evidence not available to this application projection.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function highAvailabilityProjection(): array
    {
        return [
            'state' => 'NOT_VERIFIED',
            'rows' => [
                $this->unverified('database_primary'),
                $this->unverified('database_replica'),
                $this->unverified('redis'),
                $this->unverified('queue_workers'),
                $this->unverified('application_nodes'),
                $this->unverified('cdn'),
                $this->unverified('external_integrations'),
                $this->unverified('rust_engine'),
            ],
            'note' => 'No node count or failover health is inferred from configuration presence.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function incidentProjection(?string $reference): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                [
                    'label' => 'incident_reference',
                    'value' => $reference ?? 'NO_DATA',
                    'state' => $reference !== null ? 'NOT_CONFIGURED' : 'NO_DATA',
                ],
                $this->unverified('active_incidents'),
                $this->unverified('incident_severity'),
                $this->unverified('incident_component'),
                $this->unverified('incident_timeline'),
                $this->unverified('incident_assignee'),
                $this->unverified('incident_resolution'),
            ],
            'note' => 'No incident record is fabricated. Configure the canonical incident store before displaying cases.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function deploymentApprovalProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('release_reference'),
                $this->unverified('release_checks'),
                $this->unverified('approver'),
                $this->unverified('approval_time'),
                $this->unverified('rejection_reason'),
                $this->unverified('blocking_findings'),
                $this->unverified('approval_state'),
            ],
            'note' => 'Release approval requires a canonical deployment approval record and is not a client-side toggle.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function deploymentHistoryProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('deployment_version'),
                $this->unverified('deployment_commit'),
                $this->unverified('deployed_at'),
                $this->unverified('deployment_actor'),
                $this->unverified('deployment_environment'),
                $this->unverified('deployment_status'),
                $this->unverified('rollback_reference'),
            ],
            'note' => 'Deployment history must come from deployment metadata, not from a browser request.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function rollbackProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('target_release'),
                $this->unverified('operator_reason'),
                $this->unverified('impact_acknowledgement'),
                $this->unverified('authorization'),
                $this->unverified('confirmation'),
                $this->unverified('audit_reference'),
            ],
            'note' => 'Arbitrary shell execution and browser-triggered rollback are disabled.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function featureFlagProjection(): array
    {
        $flags = config('features');
        $rows = [];

        if (is_array($flags) && $flags !== []) {
            foreach ($flags as $key => $value) {
                $rows[] = [
                    'label' => (string) $key,
                    'value' => is_scalar($value) ? (string) $value : 'configured',
                    'state' => 'AVAILABLE',
                ];
            }
        }

        return [
            'state' => $rows === [] ? 'NOT_CONFIGURED' : 'AVAILABLE',
            'rows' => $rows,
            'note' => 'Only configured server-side flags are displayed. Financial authority never moves into client-side flag state.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function configurationAuditProjection(): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => [
                $this->unverified('configuration_key'),
                $this->unverified('category'),
                $this->unverified('old_state_reference'),
                $this->unverified('new_state_reference'),
                $this->unverified('actor'),
                $this->unverified('reason'),
                $this->unverified('timestamp'),
            ],
            'note' => 'A dedicated immutable configuration-change history is required before this view can expose records.',
        ];
    }

    /**
     * @param list<string> $labels
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function notConfiguredProjection(array $labels, string $note): array
    {
        return [
            'state' => 'NOT_CONFIGURED',
            'rows' => array_map(fn (string $label): array => $this->unverified($label), $labels),
            'note' => $note,
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function permissionMatrixProjection(): array
    {
        $permissions = config('permission.permissions');
        $roles = AdminAccess::audit()['panel_roles'] ?? [];
        $rows = [];

        if (is_array($permissions) && $permissions !== []) {
            $rows[] = [
                'label' => 'configured_permission_count',
                'value' => (string) count($permissions),
                'state' => 'AVAILABLE',
            ];
        } else {
            $rows[] = $this->unverified('configured_permission_count');
        }

        $rows[] = [
            'label' => 'admin_panel_role_count',
            'value' => is_array($roles) && $roles !== [] ? (string) count($roles) : 'NOT_CONFIGURED',
            'state' => is_array($roles) && $roles !== [] ? 'AVAILABLE' : 'NOT_CONFIGURED',
        ];
        $rows[] = $this->unverified('role_permission_operation_matrix');

        return [
            'state' => $this->aggregateState($rows),
            'rows' => $rows,
            'note' => 'The permission catalogue is read from configuration; operation-level allowance still requires policy/runtime verification.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function rateLimitProjection(): array
    {
        $sources = [
            'security' => config('security.rate_limits'),
            'account' => config('account.rate_limits'),
            'admin' => config('admin.rate_limits'),
        ];
        $rows = [];

        foreach ($sources as $source => $limits) {
            if (! is_array($limits) || $limits === []) {
                continue;
            }

            foreach ($limits as $name => $limit) {
                $rows[] = [
                    'label' => $source.'.'.(string) $name,
                    'value' => is_scalar($limit) ? (string) $limit : 'configured',
                    'state' => 'AVAILABLE',
                ];
            }
        }

        return [
            'state' => $rows === [] ? 'NOT_CONFIGURED' : 'AVAILABLE',
            'rows' => $rows,
            'note' => 'These are configuration projections only; server middleware remains the rate-limit authority.',
        ];
    }

    /**
     * @return array{state: string, rows: list<array{label: string, value: string, state: string}>, note: string}
     */
    private function captchaProjection(): array
    {
        $captcha = config('auth_security.captcha');
        $configured = is_array($captcha) && $captcha !== [];

        return [
            'state' => $configured ? 'AVAILABLE' : 'NOT_CONFIGURED',
            'rows' => [
                $this->configuredMetadata('provider', is_array($captcha) ? ($captcha['provider'] ?? null) : null),
                $this->configuredMetadata('site_key_presence', is_array($captcha) && ! empty($captcha['site_key']) ? 'present' : null),
                $this->configuredMetadata('secret_presence', is_array($captcha) && ! empty($captcha['secret']) ? 'present' : null),
                $this->unverified('verification_state'),
                $this->unverified('challenge_failures'),
            ],
            'note' => 'CAPTCHA secret material is never rendered. Presence does not prove provider reachability.',
        ];
    }

    private function authorizeSurface(Request $request, string $surface): void
    {
        $operator = $request->user();
        $permission = in_array($surface, [
            'incidents', 'deployment-approval', 'deployments', 'configuration-audit', 'sessions', 'access-review',
            'privileged-access', 'permission-matrix', 'service-accounts', 'network-access', 'device-risk', 'mfa',
            'authentication-security', 'rate-limits', 'captcha', 'risk-rules', 'suspicious-activity', 'compliance-cases', 'sanctions',
            'kyc', 'kyc-provider', 'kyc-review', 'age-verification', 'duplicate-accounts', 'account-restrictions', 'retention',
            'privacy', 'data-rights', 'legal-registries', 'compliance-reporting', 'aml-monitoring', 'regulatory-exports', 'compliance-audit',
        ], true) ? AdminAccess::VIEW_AUDIT_LOGS : AdminAccess::MANAGE_SYSTEM_SETTINGS;

        if (! AdminAccess::canAccessPanel($operator) || ! AdminAccess::allows($operator, $permission)) {
            abort(403, trans('admin.access_denied'));
        }
    }

    /**
     * @return array{label: string, value: string, state: string}
     */
    private function artifactCheck(string $label, string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [
                'label' => $label,
                'value' => 'NOT_CONFIGURED',
                'state' => 'NOT_CONFIGURED',
            ];
        }

        $hash = hash_file('sha256', $path);

        return [
            'label' => $label,
            'value' => is_string($hash) ? $hash : 'UNAVAILABLE',
            'state' => is_string($hash) ? 'AVAILABLE' : 'UNAVAILABLE',
        ];
    }

    /**
     * @return array{label: string, value: string, state: string}
     */
    private function configuredMetadata(string $label, mixed $value): array
    {
        $configured = is_scalar($value) && trim((string) $value) !== '';

        return [
            'label' => $label,
            'value' => $configured ? (string) $value : 'NOT_CONFIGURED',
            'state' => $configured ? 'AVAILABLE' : 'NOT_CONFIGURED',
        ];
    }

    /**
     * @return array{label: string, value: string, state: string}
     */
    private function unverified(string $label): array
    {
        return [
            'label' => $label,
            'value' => 'NOT_VERIFIED',
            'state' => 'NOT_VERIFIED',
        ];
    }

    /**
     * @param list<array{label: string, value: string, state: string}> $rows
     */
    private function aggregateState(array $rows): string
    {
        if ($rows === []) {
            return 'NO_DATA';
        }

        foreach ($rows as $row) {
            if (in_array($row['state'], ['NOT_VERIFIED', 'UNAVAILABLE'], true)) {
                return 'NOT_VERIFIED';
            }
        }

        foreach ($rows as $row) {
            if ($row['state'] === 'NOT_CONFIGURED') {
                return 'NOT_CONFIGURED';
            }
        }

        return 'AVAILABLE';
    }

    private function hasConfiguredArray(mixed $value): bool
    {
        return is_array($value) && $value !== [];
    }
}
