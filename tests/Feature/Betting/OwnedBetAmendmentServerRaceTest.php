<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\Enums\Currency;
use App\Services\Betting\BetAmendmentService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedAmendment;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class OwnedBetAmendmentServerRaceTest extends FinancialMasterTestCase
{
    use BuildsOwnedAmendment;
    use RunsServerFinancialRaces;

    public static function races(): array
    {
        return ['same-unpaid' => [false, false], 'same-paid' => [false, true], 'different-unpaid' => [true, false], 'different-paid' => [true, true]];
    }

    #[DataProvider('races')]
    public function test_two_simultaneous_ingresses_can_own_only_one_original_refund_and_replacement(bool $different, bool $paid): void
    {
        $this->requireServerRace();
        $f = $this->amendmentFixture(Currency::THB, $paid);
        $command = ['action' => 'bet_amend', 'user_id' => $f['user']->id, 'bet_id' => $f['bet']->id, 'key' => 'same-owned-amendment-command'];
        $other = $command;
        if ($different) {
            $other['key'] = 'different-owned-amendment-command';
        }
        $results = $this->serverRace([$command, $other], 'wallets', $paid ? $f['agentWallet']->id : $f['wallet']->id,
            ['agent.commission.paid_cancellation.enabled' => $paid, 'agent.commission.paid_cancellation.policy_reference' => 'synthetic-local-agent-contract-v1']);
        $this->assertContains('applied', array_column($results, 'outcome'), json_encode($results, JSON_THROW_ON_ERROR));
        foreach ($results as $result) {
            $this->assertContains($result['outcome'], ['applied', 'refused']);
            if ($result['outcome'] === 'refused') {
                $this->assertContains($result['code'], ['bet_amendment_request_conflict', 'bet_amendment_outcome_unresolved']);
            }
        }
        $this->assertSame(1, DB::table('bet_amendments')->count());
        $this->assertSame(1, DB::table('bet_amendment_ownerships')->count());
        $this->assertSame('applied', DB::table('bet_amendment_ownerships')->value('state'));
        $this->assertSame(2, DB::table('bets')->count());
        $this->assertSame('88.00', $f['wallet']->fresh()->balance);
        $this->assertSame($paid ? 2 : 0, DB::table('agent_commission_compensations')->count());
        $before = $this->amendmentState();
        $storedKey = DB::table('bet_amendments')->value('idempotency_key');
        $winner = $storedKey === 'amend-v1-'.$f['user']->id.'-'.hash('sha256', $command['key']) ? $command['key'] : $other['key'];
        $this->assertTrue(app(BetAmendmentService::class)->amend($this->amendmentCommand($f, $winner))->isApplied());
        $this->assertSame($before, $this->amendmentState());
    }
}
