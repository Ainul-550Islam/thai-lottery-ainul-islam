<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConversionReversalAuthorityFixture;
use Tests\Support\RunsServerFinancialRaces;

final class ConversionReversalAuthorityServerTest extends ConversionTestCase
{
    use ConversionReversalAuthorityFixture;
    use RunsServerFinancialRaces;

    private const REASON = 'Synthetic authorized reversal race.';

    private const KEY = 'native-current-authority-reverse-0001';

    private function command(array $f, bool $oldAllowed): array
    {
        return ['action' => 'conversion_authority_snapshot', 'conversion_id' => $f['row']->id,
            'actor_id' => $f['actor']->id, 'key' => self::KEY,
            'expected_authority_snapshot' => ['actor_status' => 'active', 'policy_allowed' => $oldAllowed]];
    }

    public static function revocations(): array
    {
        return [['suspended', false], ['role-membership', false], ['role-permission', false], ['direct-permission', true], ['permission-name', false]];
    }

    #[DataProvider('revocations')]
    public function test_committed_revocation_beats_two_old_allow_snapshots_and_cached_policy(string $mode, bool $retry): void
    {
        $this->requireServerRace();
        $f = $this->authorityFixture($mode === 'direct-permission');
        if ($retry) {
            $this->service()->reverse($f['row']->id, $f['context'], self::REASON, self::KEY);
        }
        $command = $this->command($f, true);
        $before = $this->authorityPaper();
        $rows = $this->serverRace([$command, $command], 'users', $f['user']->id, [], null, null, function () use ($f, $mode): void {
            $this->revokeAuthority($f, $mode);
        });
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(['reversal_forbidden', 'reversal_forbidden'], array_column($rows, 'code'));
        $this->assertSame($before, $this->authorityPaper());
    }

    public function test_current_new_grant_beats_two_old_deny_snapshots_and_restores_money_once(): void
    {
        $this->requireServerRace();
        $f = $this->authorityFixture();
        DB::table('role_has_permissions')->where('role_id', $f['role']->id)->delete();
        $command = $this->command($f, false);
        $rows = $this->serverRace([$command, $command], 'users', $f['user']->id, [], null, null, function () use ($f): void {
            DB::table('role_has_permissions')->insert(['role_id' => $f['role']->id, 'permission_id' => $f['permission']->id]);
        });
        $outcomes = array_column($rows, 'outcome');
        sort($outcomes);
        $this->assertSame(['completed', 'replayed'], $outcomes, json_encode($rows));
        $this->assertSame($rows[0]['reference'], $rows[1]['reference']);
        $this->assertSame('1000.00', $this->balanceOf($f['cash']));
        $this->assertSame('0.00', $this->balanceOf($f['win']));
        $this->assertDatabaseCount('wallet_conversions', 2);
        $this->assertDatabaseCount('financial_transactions', 2);
    }
}
