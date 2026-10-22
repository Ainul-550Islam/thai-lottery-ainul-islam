<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Services\Wallet\Conversion\ConversionLimitService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConversionRollingLimitFixture;
use Tests\Support\RunsServerFinancialRaces;

final class ConversionRollingLimitServerTest extends ConversionTestCase
{
    use ConversionRollingLimitFixture;
    use RunsServerFinancialRaces;

    public static function profiles(): array
    {
        return [['daily'], ['monthly'], ['reversed'], ['replay']];
    }

    #[DataProvider('profiles')]
    public function test_committed_real_original_beats_two_old_empty_allowance_snapshots(string $profile): void
    {
        $this->requireServerRace();
        $f = $this->rollingFixture();
        $actor = $profile === 'reversed' ? $this->makeOperator() : null;
        $parent = $this->rollingRequest($f, 'native-limit-parent-original-0001', '80.00');
        $first = $profile === 'replay' ? $parent : $this->rollingRequest($f, 'native-limit-child-cash-0001');
        $second = $profile === 'replay' ? $parent : $this->rollingRequest($f, 'native-limit-child-win-0001', direction: WalletConversionDirection::WinToCash);
        $commands = array_map(fn ($request) => ['action' => 'conversion_limit_snapshot', 'owner_id' => $f['user']->id, 'request' => $request->toArray()], [$first, $second]);
        $settings = ['conversions.limits.daily_limit' => '100.00', 'conversions.limits.monthly_limit' => '100.00'];
        config($settings);
        $committed = null;
        $rows = $this->serverRace($commands, 'users', $f['user']->id, $settings, null, null, function () use ($f, $profile, $parent, $actor, &$committed): void {
            if ($profile === 'monthly') {
                // Create and post genuinely through the service at a controlled past clock;
                // do not rewrite a completed original or synthesize accounting history.
                Carbon::setTestNow(Carbon::now()->subDays(2));
            }
            try {
                $request = $profile === 'monthly' ? $this->rollingRequest($f, 'native-limit-past-original-0001', '80.00') : $parent;
                $result = $this->service()->convert($request);
                if ($profile === 'reversed') {
                    $this->service()->reverse($result->conversionId,
                        new ConversionAuditContextData(WalletConversionChannel::Admin, $actor->id),
                        'Synthetic rolling allowance reversal.', 'native-limit-parent-reverse-0001');
                }
                $committed = $this->rollingPaper();
            } finally {
                Carbon::setTestNow();
            }
        });
        if ($profile === 'replay') {
            $this->assertSame(['replayed', 'replayed'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame($rows[0]['reference'], $rows[1]['reference']);
        } else {
            $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
            $this->assertSame(array_fill(0, 2, $profile === 'monthly' ? 'monthly_limit_exceeded' : 'daily_limit_exceeded'), array_column($rows, 'code'));
            $this->assertSame(['110.00', '110.00'], array_column($rows, 'attempted'));
        }
        $this->assertSame($committed, $this->rollingPaper());
        $this->assertSame($profile === 'monthly' ? '0.00' : '80.00', app(ConversionLimitService::class)->dailyTotal($f['user']->id));
        $this->assertSame('80.00', app(ConversionLimitService::class)->monthlyTotal($f['user']->id));
        $this->assertSame($profile === 'reversed' ? 2 : 1, DB::table('financial_transactions')->count());
    }

    public function test_native_decimal_rows_retain_precision_beyond_binary_float_integer_range(): void
    {
        $this->requireServerRace();
        $f = $this->rollingFixture();
        $this->syntheticAllowance($f, '9999999999999999.99');
        $this->syntheticAllowance($f, '0.01');
        $this->assertSame('10000000000000000.00', app(ConversionLimitService::class)->monthlyTotal($f['user']->id));
    }
}
