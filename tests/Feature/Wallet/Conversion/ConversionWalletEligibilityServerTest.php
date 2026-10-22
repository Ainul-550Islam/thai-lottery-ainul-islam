<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConversionWalletEligibilityFixture;
use Tests\Support\RunsServerFinancialRaces;

final class ConversionWalletEligibilityServerTest extends ConversionTestCase
{
    use ConversionWalletEligibilityFixture;
    use RunsServerFinancialRaces;

    public static function profiles(): array
    {
        return array_merge(ConversionWalletEligibilityTest::blockedProfiles(), array_map(fn ($p) => [$p, null],
            ['source-restored', 'destination-restored', 'funds-restored', 'source-created', 'destination-created', 'exact-replay']));
    }

    #[DataProvider('profiles')]
    public function test_current_wallet_pair_beats_two_old_rr_snapshots_after_the_real_owner_wait(string $profile, ?string $code): void
    {
        $this->requireServerRace();
        $f = $this->slotFixture();
        $requests = [$this->slotRequest($f, 'native-wallet-quote-00001'), $this->slotRequest($f, 'native-wallet-money-00001')];
        $requests[1] = $requests[1]->withQuoteReference($this->service()->quote($requests[1])->reference);
        $original = null;
        if ($profile === 'exact-replay') {
            $requests[0] = $requests[1];
            $original = $this->service()->convert($requests[1])->reference;
        }
        $this->walletOldState($f, $profile);
        $snapshot = $this->walletSnapshot($f);
        $commands = [];
        foreach ($requests as $i => $request) {
            $commands[] = ['action' => 'conversion_wallet_snapshot', 'owner_id' => $f['user']->id,
                'mode' => $profile !== 'exact-replay' && $i === 0 ? 'quote' : 'convert',
                'expected_wallet_snapshot' => $snapshot, 'request' => $request->toArray()];
        }
        $committed = null;
        $rows = $this->serverRace($commands, 'users', $f['user']->id, [], null, null,
            function () use ($f, $profile, &$committed): void {
                $this->walletChange($f, $profile);
                $committed = $this->slotPaper();
            });
        if ($code !== null) {
            $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame([$code, $code], array_column($rows, 'code'));
            $this->assertSame($committed, $this->slotPaper());
        } elseif ($profile === 'exact-replay') {
            $this->assertSame(['replayed', 'replayed'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame([$original, $original], array_column($rows, 'reference'));
            $this->assertSame($committed, $this->slotPaper());
        } else {
            $this->assertSame(['quoted', 'completed'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertTrue($this->ledgerIsBalanced()['balanced']);
        }
    }
}
