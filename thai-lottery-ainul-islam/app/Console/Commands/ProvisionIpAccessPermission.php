<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Services\Audit\AuditLogService;
use App\Services\Network\IpAccessRuleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/** Host-operator CLI only: registers the permission, never grants it. */
final class ProvisionIpAccessPermission extends Command
{
    protected $signature = 'ip-access:provision-permission {--apply : Explicitly create the web permission record without granting it}';

    protected $description = 'Dry-run by default; register the IP-rule management permission without assigning users or roles.';

    public function handle(AuditLogService $audit, PermissionRegistrar $registrar): int
    {
        if (! $this->option('apply')) {
            $this->line('DRY_RUN: would register the web IP-rule management permission; no grants or writes. Use --apply to proceed.');

            return self::SUCCESS;
        }

        try {
            $created = DB::transaction(function () use ($audit): bool {
                $permission = Permission::query()->firstOrCreate([
                    'name' => IpAccessRuleManager::MANAGE_PERMISSION,
                    'guard_name' => 'web',
                ]);
                if (! $permission->wasRecentlyCreated) {
                    return false;
                }
                $audit->log(
                    userId: null, action: AuditAction::Create, riskLevel: RiskLevel::High,
                    auditable: $permission, description: 'IP access management permission registered without grants.',
                    metadata: ['schema_version' => 1, 'operation' => 'provision_ip_access_permission', 'source' => 'operator_cli'],
                    newValues: ['name' => $permission->name, 'guard_name' => 'web'],
                );

                return true;
            });
        } catch (Throwable) {
            // Do not print SQL, connection details or exception payloads.
            $this->error('PROVISION_FAILED: registration was not confirmed; inspect the database and audit store before retrying.');

            return self::FAILURE;
        }

        try {
            $registrar->forgetCachedPermissions();
        } catch (Throwable) {
            // This occurs after commit: never falsely report a rollback.
            $this->error('PERMISSION_READY_CACHE_REFRESH_FAILED: the record exists; repair permission-cache invalidation before use. No grants performed.');

            return self::FAILURE;
        }
        $this->info($created
            ? 'CREATED: web permission registered and audited. No grants performed.'
            : 'ALREADY_EXISTS: no permission or audit mutation. Existing assignments preserved.');

        return self::SUCCESS;
    }
}
