<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\Enums\WalletConversionChannel;
use App\Exceptions\Wallet\ConversionException;
use App\Models\User;
use App\Policies\WalletConversionPolicy;
use App\Services\Wallet\Conversion\ConversionReversalAuthority;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\ConversionReversalAuthorityFixture;

final class ConversionReversalAuthorityTest extends ConversionTestCase
{
    use ConversionReversalAuthorityFixture;

    private const REASON = 'Synthetic authorized reversal race.';

    private const KEY = 'current-reversal-authority-key-0001';

    private function reverse(array $f)
    {
        return $this->service()->reverse($f['row']->id, $f['context'], self::REASON, self::KEY);
    }

    private function forbidden(callable $action): void
    {
        try {
            $action();
            $this->fail('Cached or absent authority must not authorize money or reversal replay.');
        } catch (ConversionException $error) {
            $this->assertSame('reversal_forbidden', $error->errorCode());
        }
    }

    public static function revocations(): array
    {
        $cases = [];
        foreach (['suspended', 'deleted', 'invalid-status', 'role-membership', 'role-permission', 'direct-permission', 'role-guard', 'permission-guard', 'permission-name'] as $mode) {
            foreach ([false, true] as $replay) {
                $cases[$mode.($replay ? '-replay' : '-first')] = [$mode, $replay];
            }
        }

        return $cases;
    }

    #[DataProvider('revocations')]
    public function test_cached_allow_never_overrides_current_reversal_or_retry_revocation(string $mode, bool $replay): void
    {
        $f = $this->authorityFixture($mode === 'direct-permission');
        if ($replay) {
            $this->reverse($f);
        }
        $f['actor']->load('roles', 'permissions');
        $this->assertTrue((new WalletConversionPolicy)->reverse($f['actor'], $f['row']));
        $this->revokeAuthority($f, $mode);
        $this->assertTrue((new WalletConversionPolicy)->reverse($f['actor'], $f['row']), 'The test must actually retain a stale policy allow.');
        $before = $this->authorityPaper();
        $this->forbidden(fn () => $this->reverse($f));
        $this->assertSame($before, $this->authorityPaper());
        $this->assertTrue($this->service()->convert($f['request'])->replayed, 'Member original replay is historical observation, not a new staff authority.');
        $this->assertSame($before, $this->authorityPaper());
    }

    public static function validGrants(): array
    {
        return [['inherited-wallet'], ['inherited-ledger'], ['direct-wallet'], ['direct-ledger'], ['super-admin'], ['super-admin-auditor'], ['admin-auditor'], ['self-actor'], ['morph-map']];
    }

    #[DataProvider('validGrants')]
    public function test_current_grants_preserve_reversal_semantics_and_read_only_retry(string $mode): void
    {
        $oldMap = Relation::morphMap();
        if ($mode === 'morph-map') {
            Relation::morphMap(['authority-member' => User::class], false);
        }
        try {
            $f = $this->authorityFixture(str_starts_with($mode, 'direct-'));
            if (str_ends_with($mode, '-ledger')) {
                $ledger = Permission::findOrCreate('reconcile ledger', 'web');
                $f['role']->syncPermissions($mode === 'inherited-ledger' ? [$ledger] : []);
                $f['actor']->syncPermissions($mode === 'direct-ledger' ? [$ledger] : []);
            }
            if (str_starts_with($mode, 'super-admin')) {
                $f['actor']->syncRoles([Role::findOrCreate('super-admin', 'web')]);
            }
            if (str_ends_with($mode, '-auditor')) {
                $f['actor']->assignRole(Role::findOrCreate('auditor', 'web'));
            }
            if ($mode === 'self-actor') {
                $f['user']->givePermissionTo('manage wallet');
                $f['context'] = new ConversionAuditContextData(WalletConversionChannel::Admin, $f['user']->id);
            }
            $result = $this->reverse($f);
            $this->assertFalse($result->replayed);
            $this->assertSame('1000.00', $this->balanceOf($f['cash']));
            $this->assertSame('0.00', $this->balanceOf($f['win']));
            $before = $this->authorityPaper();
            $retry = $this->reverse($f);
            $this->assertTrue($retry->replayed);
            $this->assertSame($result->reference, $retry->reference);
            $this->assertSame($before, $this->authorityPaper());
        } finally {
            Relation::morphMap($oldMap, false);
        }
    }

    public static function absentGrants(): array
    {
        return [['auditor-only'], ['no-grant'], ['wrong-role-morph'], ['wrong-direct-morph'], ['api-super-admin'], ['missing-actor'], ['null-actor'], ['teams-enabled']];
    }

    #[DataProvider('absentGrants')]
    public function test_no_cache_guard_or_morph_shortcut_can_manufacture_authority(string $mode): void
    {
        $f = $this->authorityFixture($mode === 'wrong-direct-morph');
        if ($mode === 'auditor-only') {
            $f['actor']->syncRoles([Role::findOrCreate('auditor', 'web')]);
            $f['actor']->givePermissionTo('manage wallet');
        } elseif ($mode === 'no-grant') {
            DB::table('role_has_permissions')->where('role_id', $f['role']->id)->delete();
        } elseif ($mode === 'wrong-role-morph' || $mode === 'wrong-direct-morph') {
            DB::table($mode === 'wrong-role-morph' ? 'model_has_roles' : 'model_has_permissions')
                ->where('model_id', $f['actor']->id)->update(['model_type' => 'unrelated-model']);
        } elseif ($mode === 'api-super-admin') {
            DB::table('model_has_roles')->where('model_id', $f['actor']->id)->delete();
            $role = Role::findOrCreate('super-admin', 'api');
            DB::table('model_has_roles')->insert(['model_id' => $f['actor']->id, 'model_type' => $f['actor']->getMorphClass(), 'role_id' => $role->id]);
        } elseif ($mode === 'missing-actor' || $mode === 'null-actor') {
            $f['context'] = new ConversionAuditContextData(WalletConversionChannel::Admin, $mode === 'missing-actor' ? 999999 : null);
        } elseif ($mode === 'teams-enabled') {
            config(['permission.teams' => true]);
        }
        $before = $this->authorityPaper();
        $this->forbidden(fn () => $this->reverse($f));
        $this->assertSame($before, $this->authorityPaper());
    }

    public function test_authority_requires_a_physical_transaction_and_never_grants_a_permission(): void
    {
        $f = $this->authorityFixture();
        $grants = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $before = $this->authorityPaper();
        $this->forbidden(fn () => app(ConversionReversalAuthority::class)->authorize($f['user']->id, $f['actor']->id));
        $this->assertSame($before, $this->authorityPaper());
        $this->assertSame($grants, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }

    public static function ambientRetries(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('ambientRetries')]
    public function test_revocation_refusal_survives_an_ambient_caller_catching_and_committing(bool $retry): void
    {
        $f = $this->authorityFixture();
        if ($retry) {
            $this->reverse($f);
        }
        $this->revokeAuthority($f, 'role-permission');
        $before = $this->authorityPaper();
        DB::beginTransaction();
        try {
            $this->forbidden(fn () => $this->reverse($f));
            DB::commit();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame($before, $this->authorityPaper());
    }

    public function test_changed_owner_between_preload_and_lock_cannot_escape_the_principal_boundary(): void
    {
        $f = $this->authorityFixture();
        $other = $this->makeVerifiedMember();
        $before = $this->authorityPaper();
        $injected = false;
        DB::listen(function ($query) use ($f, $other, &$injected): void {
            if (! $injected && DB::transactionLevel() > 0 && str_starts_with(strtolower($query->sql), 'select')
                && (str_contains($query->sql, '"users"') || str_contains($query->sql, '`users`'))) {
                $injected = true;
                DB::table('wallet_conversions')->where('id', $f['row']->id)->update(['user_id' => $other->id]);
            }
        });
        $this->forbidden(fn () => $this->reverse($f));
        $this->assertTrue($injected, 'The original owner must actually change after preload inside the reversible savepoint.');
        $this->assertSame($before, $this->authorityPaper());
    }

    public function test_http_stale_policy_allow_cannot_move_money_after_raw_grant_revocation(): void
    {
        $f = $this->authorityFixture();
        $f['actor']->load('roles', 'permissions');
        $this->assertTrue((new WalletConversionPolicy)->reverse($f['actor'], $f['row']));
        $this->revokeAuthority($f, 'role-permission');
        $before = $this->authorityPaper();
        Sanctum::actingAs($f['actor']);
        $this->postJson('/api/v1/wallet/conversions/'.$f['row']->reference.'/reverse', ['reason' => self::REASON], ['Idempotency-Key' => self::KEY])
            ->assertStatus(403)->assertJsonPath('error.code', 'reversal_forbidden');
        $this->assertSame($before, $this->authorityPaper());
    }
}
