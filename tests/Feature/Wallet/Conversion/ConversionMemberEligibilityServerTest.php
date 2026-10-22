<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Enums\UserStatus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConversionMemberEligibilityFixture;
use Tests\Support\RunsServerFinancialRaces;

final class ConversionMemberEligibilityServerTest extends ConversionTestCase
{
    use ConversionMemberEligibilityFixture;
    use RunsServerFinancialRaces;

    public static function profiles(): array
    {
        return array_merge(ConversionMemberEligibilityTest::blockedProfiles(), [['positive-restored', null], ['exact-replay', null]]);
    }

    #[DataProvider('profiles')]
    public function test_current_member_facts_beat_two_real_old_rr_snapshots_after_owner_wait(string $profile, ?string $code): void
    {
        $this->requireServerRace();
        $settings = ['conversions.gates.require_verified_mobile' => true];
        config($settings);
        $f = $this->memberFixture();
        $requests = $this->memberRequests($f);
        $requests = array_map(fn ($r) => $r->withQuoteReference($this->service()->quote($r)->reference), $requests);
        $original = [];
        if ($profile === 'exact-replay') {
            foreach ($requests as $request) {
                $original[] = $this->service()->convert($request)->reference;
            }
        } elseif ($profile === 'positive-restored') {
            $f['user']->forceFill(['status' => UserStatus::Suspended])->save();
        }
        $snapshot = $this->memberSnapshot($profile === 'positive-restored' ? 'suspended' : 'active');
        $commands = [];
        foreach ($requests as $i => $request) {
            $commands[] = ['action' => 'conversion_member_snapshot', 'owner_id' => $f['user']->id,
                'mode' => $profile !== 'exact-replay' && $i === 0 ? 'quote' : 'convert',
                'expected_member_snapshot' => $snapshot, 'request' => $request->toArray()];
        }
        $committed = null;
        $rows = $this->serverRace($commands, 'users', $f['user']->id, $settings, null, null,
            function () use ($f, $profile, &$committed): void {
                $this->changeMember($f, $profile);
                $committed = $this->slotPaper();
            });
        if ($code !== null) {
            $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame([$code, $code], array_column($rows, 'code'));
            $this->assertSame($committed, $this->slotPaper());
            $this->assertSame(0, $this->memberFinancialRows());
        } elseif ($profile === 'exact-replay') {
            $this->assertSame(['replayed', 'replayed'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame($original, array_column($rows, 'reference'));
            $this->assertSame($committed, $this->slotPaper());
            $this->assertSame(2, $this->memberFinancialRows());
        } else {
            $this->assertSame(['quoted', 'completed'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame(1, $this->memberFinancialRows());
            $this->assertSame(1, DB::table('wallet_conversions')->count());
            $this->assertSame(3, DB::table('wallet_conversion_quotes')->count());
            $this->assertTrue($this->ledgerIsBalanced()['balanced']);
        }
    }
}
