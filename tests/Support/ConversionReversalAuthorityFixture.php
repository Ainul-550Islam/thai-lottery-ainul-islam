<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\Enums\WalletConversionChannel;
use App\Models\WalletConversion;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Real conversion and real persisted grants; never an authorization double. */
trait ConversionReversalAuthorityFixture
{
    protected function authorityFixture(bool $direct = false): array
    {
        $f = $this->makeFundedMember();
        $quote = $this->quoteFor($f['user']);
        $request = $this->request($f['user'], quoteReference: $quote->reference);
        $result = $this->service()->convert($request);
        $row = WalletConversion::query()->where('reference', $result->reference)->sole();
        $actor = $this->makeVerifiedMember();
        $role = Role::findOrCreate('admin', 'web');
        $permission = Permission::findOrCreate('manage wallet', 'web');
        $role->syncPermissions($direct ? [] : [$permission]);
        $actor->assignRole($role);
        if ($direct) {
            $actor->givePermissionTo($permission);
        }

        return $f + ['request' => $request, 'row' => $row, 'actor' => $actor, 'role' => $role, 'permission' => $permission,
            'context' => new ConversionAuditContextData(WalletConversionChannel::Admin, $actor->id)];
    }

    protected function authorityPaper(): array
    {
        $paper = [];
        foreach (['wallets', 'wallet_conversions', 'wallet_conversion_quotes', 'wallet_conversion_audits', 'financial_transactions', 'ledger_entries', 'ledger_accounts'] as $table) {
            $paper[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $paper;
    }

    protected function revokeAuthority(array $f, string $mode): void
    {
        // Deliberately bypass Spatie cache invalidation, just as another process or
        // committed maintenance transaction can. The financial boundary must not trust it.
        match ($mode) {
            'suspended' => DB::table('users')->where('id', $f['actor']->id)->update(['status' => 'suspended']),
            'deleted' => DB::table('users')->where('id', $f['actor']->id)->update(['deleted_at' => now()]),
            'invalid-status' => DB::table('users')->where('id', $f['actor']->id)->update(['status' => 'invented']),
            'role-membership' => DB::table('model_has_roles')->where('model_id', $f['actor']->id)->delete(),
            'role-permission' => DB::table('role_has_permissions')->where('role_id', $f['role']->id)->delete(),
            'direct-permission' => DB::table('model_has_permissions')->where('model_id', $f['actor']->id)->delete(),
            'role-guard' => DB::table('roles')->where('id', $f['role']->id)->update(['guard_name' => 'api']),
            'permission-guard' => DB::table('permissions')->where('id', $f['permission']->id)->update(['guard_name' => 'api']),
            'permission-name' => DB::table('permissions')->where('id', $f['permission']->id)->update(['name' => 'unrelated authority']),
        };
    }
}
