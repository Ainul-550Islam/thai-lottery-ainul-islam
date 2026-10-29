<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Enums\WalletConversionDirection;
use App\Models\WalletConversionQuote;
use App\Repositories\Wallet\WalletConversionQuoteRepository;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConversionQuoteSlotFixture;
use Tests\Support\RunsServerFinancialRaces;

final class ConversionQuoteSlotServerTest extends ConversionTestCase
{
    use ConversionQuoteSlotFixture;
    use RunsServerFinancialRaces;

    public static function profiles(): array
    {
        return [['parent-fills-empty', 0, 1], ['child-fills-empty', 0, 1], ['parent-consumes', 1, 1], ['parent-fills-last', 1, 2]];
    }

    #[DataProvider('profiles')]
    public function test_current_owner_slot_admission_beats_two_old_quote_count_snapshots(string $profile, int $oldCount, int $ceiling): void
    {
        $this->requireServerRace();
        $f = $this->slotFixture();
        $settings = ['conversions.quotes.max_open_per_user' => (string) $ceiling];
        config($settings);
        $previous = $oldCount === 1 ? $this->service()->quote($this->slotRequest($f, 'native-slot-previous-0001')) : null;
        $requests = [$this->slotRequest($f, 'native-slot-child-cash-0001'),
            $this->slotRequest($f, 'native-slot-child-win-0001', WalletConversionDirection::WinToCash)];
        $commands = array_map(fn ($request) => ['action' => 'conversion_quote_slot_snapshot', 'owner_id' => $f['user']->id,
            'expected_slot_snapshot' => $oldCount, 'request' => $request->toArray()], $requests);
        $committed = null;
        $rows = $this->serverRace($commands, 'users', $f['user']->id, $settings, null, null, function () use ($profile, $f, $previous, &$committed): void {
            if (str_starts_with($profile, 'parent-fills-')) {
                $this->service()->quote($this->slotRequest($f, 'native-slot-parent-new-0001'));
            } elseif ($profile === 'parent-consumes') {
                $this->service()->convert($this->slotRequest($f, 'native-slot-real-convert-0001')->withQuoteReference($previous->reference));
            }
            $committed = $this->slotPaper();
        });
        $full = str_starts_with($profile, 'parent-fills-');
        $outcomes = array_column($rows, 'outcome');
        sort($outcomes);
        $this->assertSame($full ? ['refused', 'refused'] : ['quoted', 'refused'], $outcomes, json_encode($rows));
        $refused = array_values(array_filter($rows, fn ($r) => $r['outcome'] === 'refused'));
        $this->assertSame(array_fill(0, count($refused), 'rate_limited'), array_column($refused, 'code'));
        $this->assertSame($ceiling, app(WalletConversionQuoteRepository::class)->countOpenForUser($f['user']->id));
        $now = $this->slotPaper();
        if ($full) {
            $this->assertSame($committed, $now);
        } else {
            unset($committed['wallet_conversion_quotes'], $now['wallet_conversion_quotes']);
            $this->assertSame($committed, $now);
            $issued = array_values(array_filter($rows, fn ($r) => $r['outcome'] === 'quoted'));
            $this->assertCount(1, $issued);
            $this->assertTrue(WalletConversionQuote::query()->where('reference', $issued[0]['reference'])->firstOrFail()->isOpen());
        }
        $this->assertSame($profile === 'parent-consumes' ? 1 : 0, DB::table('financial_transactions')->count());
        if ($previous !== null) {
            $this->assertSame($profile === 'parent-consumes' ? 'consumed' : 'open',
                WalletConversionQuote::query()->where('reference', $previous->reference)->firstOrFail()->getRawOriginal('status'));
        }
    }
}
