<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\DTOs\Wallet\ConversionRequestData;
use App\Models\WalletConversion;
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsServerFinancialRaces;

final class ConversionOriginalProofServerTest extends ConversionTestCase
{
    use RunsServerFinancialRaces;

    private function paper(): array
    {
        $rows = [];
        foreach (['wallets', 'wallet_conversions', 'wallet_conversion_quotes', 'financial_transactions', 'ledger_entries', 'ledger_accounts'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }

    private function command(array $f, string $quote): array
    {
        return ['action' => 'convert', 'owner_id' => $f['user']->id, 'request' => [
            'direction' => 'cash_to_win', 'amount' => '100.00', 'currency' => 'THB', 'channel' => 'api',
            'user_id' => $f['user']->id, 'idempotency_key' => 'native-original-proof-key-0001', 'quote_reference' => $quote,
        ]];
    }

    public function test_two_proved_replays_wait_on_owner_and_leave_all_economic_paper_unchanged(): void
    {
        $this->requireServerRace();
        $f = $this->makeFundedMember();
        $quote = $this->quoteFor($f['user']);
        $command = $this->command($f, $quote->reference);
        $result = $this->service()->convert(ConversionRequestData::fromArray($command['request']));
        $before = $this->paper();
        $rows = $this->serverRace([$command, $command], 'users', $f['user']->id);
        $this->assertSame(['replayed', 'replayed'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame([$result->reference, $result->reference], array_column($rows, 'reference'));
        $this->assertSame($before, $this->paper());
    }

    public function test_old_empty_conversion_snapshots_find_the_committed_original_instead_of_reentering_new_work(): void
    {
        $this->requireServerRace();
        $f = $this->makeFundedMember();
        $quote = $this->quoteFor($f['user']);
        $command = $this->command($f, $quote->reference);
        $command['action'] = 'conversion_proof_replay_snapshot';
        $command['expected_snapshot'] = ['conversion_count' => 0, 'transaction_amount' => null];
        $committed = null;
        $rows = $this->serverRace([$command, $command], 'users', $f['user']->id, [], null, null, function () use ($command, &$committed): void {
            $this->service()->convert(ConversionRequestData::fromArray($command['request']));
            $committed = $this->paper();
        });
        $this->assertSame(['replayed', 'replayed'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame($committed, $this->paper());
        $this->assertDatabaseCount('wallet_conversions', 1);
    }

    public function test_old_valid_source_snapshots_cannot_replay_a_newly_corrupted_transaction(): void
    {
        $this->assertCurrentProof(false);
    }

    public function test_old_valid_source_snapshots_cannot_reverse_a_newly_corrupted_transaction(): void
    {
        $this->assertCurrentProof(true);
    }

    private function assertCurrentProof(bool $reverse): void
    {
        $this->requireServerRace();
        $f = $this->makeFundedMember();
        $quote = $this->quoteFor($f['user']);
        $command = $this->command($f, $quote->reference);
        $result = $this->service()->convert(ConversionRequestData::fromArray($command['request']));
        $original = WalletConversion::query()->where('reference', $result->reference)->sole();
        $operator = $this->makeOperator();
        $command += ['conversion_id' => $original->id, 'transaction_id' => $original->financial_transaction_id,
            'actor_id' => $operator->id, 'key' => 'native-original-proof-reverse-0001',
            'expected_snapshot' => ['conversion_count' => 1, 'transaction_amount' => '100.00']];
        $command['action'] = $reverse ? 'conversion_proof_reverse_snapshot' : 'conversion_proof_replay_snapshot';
        $committed = null;
        $rows = $this->serverRace([$command, $command], 'users', $f['user']->id, [], null, null, function () use ($original, &$committed): void {
            DB::table('financial_transactions')->where('id', $original->financial_transaction_id)->update(['amount' => '101.00']);
            $committed = $this->paper();
        });
        $this->assertSame(['refused', 'refused'], array_column($rows, 'outcome'), json_encode($rows));
        $this->assertSame(array_fill(0, 2, $reverse ? 'reversal_unavailable' : 'conversion_original_unproven'), array_column($rows, 'code'));
        $this->assertSame($committed, $this->paper());
        $this->assertDatabaseCount('wallet_conversions', 1);
    }
}
