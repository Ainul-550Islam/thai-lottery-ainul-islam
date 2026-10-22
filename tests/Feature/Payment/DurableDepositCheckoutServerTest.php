<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payment\DepositCheckoutService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RunsServerFinancialRaces;

final class DurableDepositCheckoutServerTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    public static function races(): array
    {
        return [['same_request'], ['changed_options'], ['lost_acknowledgement']];
    }

    #[DataProvider('races')]
    public function test_competing_committed_intents_never_make_a_second_automatic_provider_call(string $kind): void
    {
        $this->requireServerRace();
        $f = $this->createPlayer('0.00');
        $command = ['action' => 'deposit_checkout', 'wallet_id' => $f['wallet']->id,
            'key' => 'synthetic-server-checkout-original', 'lose_acknowledgement' => $kind === 'lost_acknowledgement'];
        $other = $command;
        if ($kind === 'changed_options') {
            $other['options'] = ['metadata' => ['original' => 'different']];
        }
        $acceptances = [];
        $results = $this->serverRace([$command, $other], 'wallets', $f['wallet']->id,
            afterChildren: function (string $directory) use (&$acceptances): void {
                $path = $directory.'/checkout-acceptances.jsonl';
                $this->assertFileExists($path);
                $acceptances = array_map(fn ($line) => json_decode($line, true, 16, JSON_THROW_ON_ERROR), file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            });
        $this->assertCount(1, $acceptances, json_encode($results));
        $this->assertSame('dispatching', $acceptances[0]['state']);
        $this->assertSame(1, $acceptances[0]['attempt_count']);
        $this->assertSame(0, $acceptances[0]['transaction_level']);
        foreach ($results as $r) {
            if ($r['outcome'] === 'refused') {
                $this->assertNull($r['sqlstate'], json_encode($r));
                $this->assertContains($r['code'], ['checkout_outcome_unknown', 'checkout_request_conflict']);
            } else {
                $this->assertSame('checkout_projected', $r['outcome']);
            }
        }
        $a = DB::table(DepositCheckoutService::TABLE)->sole();
        $this->assertSame(1, (int) $a->attempt_count);
        $this->assertSame($kind === 'lost_acknowledgement' ? 'unknown' : 'projected', $a->state);
        $this->assertDatabaseCount('deposits', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
    }
}
