<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Payment\StripeRefundRecoveryService;
use Tests\Support\RunsServerFinancialRaces;
use Tests\Support\StripeRefundRecoveryFixture;

final class StripeRefundRecoveryServerTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;
    use StripeRefundRecoveryFixture;

    private function command(array $f): array
    {
        return ['action' => 'stripe_refund_recovery', 'deposit_id' => $f['deposit']->id, 'actor_id' => $f['actor']->id,
            'inbox_id' => $f['inbox'], 'proof' => $f['proof']];
    }

    public function test_two_genuine_wallet_waiters_and_two_unlocked_http_proofs_make_one_debit(): void
    {
        $this->requireServerRace();
        $f = $this->refundFixture();
        $cmd = $this->command($f) + ['two_http_arrivals' => true];
        $rows = $this->serverRace([$cmd, $cmd], 'wallets', $f['wallet']->id, [], null,
            function (string $directory): void {
                $markers = glob($directory.'/*.refund-ready');
                $this->assertCount(2, $markers);
                foreach ($markers as $m) {
                    $p = json_decode(file_get_contents($m), true, 16, JSON_THROW_ON_ERROR);
                    $this->assertSame(4, $p['gets']);
                    $this->assertFalse($p['pdo_transaction']);
                }
            });
        $this->assertSame(['refund_recovered', 'refund_recovered'], array_column($rows, 'outcome'), json_encode($rows));
        $results = array_column($rows, 'result');
        $flags = array_column($results, 'recovered');
        sort($flags);
        $this->assertSame([false, true], $flags);
        $this->assertSame($results[0]['financial_transaction_id'], $results[1]['financial_transaction_id']);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount(StripeRefundRecoveryService::INTENTS, 1);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 1);
        $this->assertDatabaseHas('financial_transactions', ['id' => $results[0]['financial_transaction_id'], 'status' => 'completed', 'amount' => '100.00']);
    }

    public function test_two_genuine_replay_waiters_do_not_read_provider_or_mutate_money(): void
    {
        $this->requireServerRace();
        $f = $this->refundFixture();
        $out = $this->recoverRefund($f);
        $paper = $this->moneyPaper();
        $cmd = $this->command($f);
        $rows = $this->serverRace([$cmd, $cmd], 'wallets', $f['wallet']->id);
        $this->assertSame(['refund_recovered', 'refund_recovered'], array_column($rows, 'outcome'), json_encode($rows));
        foreach ($rows as $row) {
            $this->assertSame(0, $row['gets']);
            $this->assertTrue($row['result']['replayed']);
            $this->assertSame($out['financial_transaction_id'], $row['result']['financial_transaction_id']);
        }
        $this->assertSame($paper, $this->moneyPaper());
    }
}
