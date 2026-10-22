<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\FinancialException;
use App\Services\Finance\LedgerPostingInputWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LedgerPostingInputWitnessTest extends TestCase
{
    private function rows(string $currency = 'THB'): array
    {
        return [['id' => 5, 'user_id' => null, 'wallet_id' => null, 'currency' => $currency, 'amount' => '10.00', 'status' => 'pending', 'deleted_at' => null], [10 => ['id' => 10, 'type' => 'asset', 'currency' => $currency, 'current_balance' => '0.00', 'is_active' => 1, 'deleted_at' => null], 20 => ['id' => 20, 'type' => 'liability', 'currency' => $currency, 'current_balance' => '0.00', 'is_active' => 1, 'deleted_at' => null]]];
    }

    private function refusal(LedgerPostingInputWitness $witness, ?array $transaction, array $accounts, string $entity, string $field): void
    {
        try {
            $witness->assertUnchanged($transaction, $accounts);
            $this->fail('Changed financial input must refuse.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_posting_inputs_changed', $error->errorCode());
            $this->assertSame(['stage' => 'payload_preflight', 'entity' => $entity, 'field' => $field], $error->context());
            $this->assertNull($error->getPrevious());
        }
    }

    public static function changes(): array
    {
        $rows = [];
        foreach (['financial_transaction' => ['id', 'user_id', 'wallet_id', 'currency', 'amount', 'status', 'deleted_at'], 'ledger_account' => ['id', 'type', 'currency', 'current_balance', 'is_active', 'deleted_at']] as $entity => $fields) {
            foreach ($fields as $field) {
                foreach ([false, true] as $remove) {
                    $rows[$entity.'-'.$field.'-'.($remove ? 'removed' : 'changed')] = [$entity, $field, $remove];
                }
            }
        }

        return $rows;
    }

    #[DataProvider('changes')]
    public function test_every_selected_financial_field_and_key_presence_is_compared(string $entity, string $field, bool $remove): void
    {
        [$tx, $accounts] = $this->rows();
        $witness = LedgerPostingInputWitness::capture($tx, $accounts);
        if ($entity === 'financial_transaction') {
            if ($remove) {
                unset($tx[$field]);
            } else {
                $tx[$field] = 'changed';
            }
        } elseif ($remove) {
            unset($accounts[20][$field]);
        } else {
            $accounts[20][$field] = 'changed';
        }
        $this->refusal($witness, $tx, $accounts, $entity, $field);
    }

    public static function rowSets(): array
    {
        return [['root-missing'], ['account-missing'], ['account-extra']];
    }

    #[DataProvider('rowSets')]
    public function test_missing_and_unexpected_row_sets_refuse(string $kind): void
    {
        [$tx, $accounts] = $this->rows();
        $witness = LedgerPostingInputWitness::capture($tx, $accounts);
        if ($kind === 'root-missing') {
            $tx = null;
        } elseif ($kind === 'account-missing') {
            unset($accounts[20]);
        } else {
            $accounts[30] = array_replace($accounts[20], ['id' => 30]);
        }
        $this->refusal($witness, $tx, $accounts, $kind === 'root-missing' ? 'financial_transaction' : 'ledger_account', $kind === 'root-missing' ? 'row' : 'row_set');
    }

    public static function stable(): array
    {
        $rows = [];
        foreach (['THB', 'BDT'] as $currency) {
            foreach (['plain', 'fields-reversed', 'accounts-reversed'] as $order) {
                $rows[] = [$currency, $order];
            }
        }

        return $rows;
    }

    #[DataProvider('stable')]
    public function test_stable_rows_ignore_projection_map_and_attribute_order(string $currency, string $order): void
    {
        [$tx, $accounts] = $this->rows($currency);
        $witness = LedgerPostingInputWitness::capture($tx, $accounts);
        if ($order === 'fields-reversed') {
            $tx = array_reverse($tx, true);
            $accounts = array_map(fn ($row) => array_reverse($row, true), $accounts);
        } elseif ($order === 'accounts-reversed') {
            $accounts = array_reverse($accounts, true);
        }
        $witness->assertUnchanged($tx, $accounts);
        $this->assertSame($currency, $tx['currency']);
    }

    public static function annotations(): array
    {
        return [['root', 'description'], ['root', 'reference_type'], ['root', 'reference_id'], ['root', 'metadata'], ['root', 'updated_at'], ['account', 'code'], ['account', 'name'], ['account', 'updated_at']];
    }

    #[DataProvider('annotations')]
    public function test_irrelevant_annotations_do_not_redefine_the_financial_plan(string $entity, string $field): void
    {
        [$tx, $accounts] = $this->rows();
        $witness = LedgerPostingInputWitness::capture($tx, $accounts);
        if ($entity === 'root') {
            $tx[$field] = 'different annotation';
        } else {
            $accounts[20][$field] = 'different annotation';
        }
        $witness->assertUnchanged($tx, $accounts);
        $this->assertSame('pending', $tx['status']);
    }

    public function test_capture_and_comparison_do_not_mutate_input_arrays(): void
    {
        [$tx, $accounts] = $this->rows();
        $before = [$tx, $accounts];
        $witness = LedgerPostingInputWitness::capture($tx, $accounts);
        $witness->assertUnchanged($tx, $accounts);
        $this->assertSame($before, [$tx, $accounts]);
    }

    public function test_independent_witnesses_keep_their_own_expected_state(): void
    {
        [$tx, $accounts] = $this->rows();
        $first = LedgerPostingInputWitness::capture($tx, $accounts);
        $tx['status'] = 'processing';
        $second = LedgerPostingInputWitness::capture($tx, $accounts);
        $second->assertUnchanged($tx, $accounts);
        $this->refusal($first, $tx, $accounts, 'financial_transaction', 'status');
    }

    public function test_checks_can_be_repeated_without_consuming_the_witness(): void
    {
        [$tx, $accounts] = $this->rows();
        $witness = LedgerPostingInputWitness::capture($tx, $accounts);
        for ($i = 0; $i < 3; $i++) {
            $witness->assertUnchanged($tx, $accounts);
        }
        $accounts[10]['current_balance'] = '1.00';
        $this->refusal($witness, $tx, $accounts, 'ledger_account', 'current_balance');
    }

    public function test_missing_nullable_key_is_not_equivalent_to_present_null(): void
    {
        [$tx, $accounts] = $this->rows();
        unset($tx['wallet_id']);
        $witness = LedgerPostingInputWitness::capture($tx, $accounts);
        $tx['wallet_id'] = null;
        $this->refusal($witness, $tx, $accounts, 'financial_transaction', 'wallet_id');
    }

    public function test_equal_restored_values_are_not_a_transaction_generation_proof(): void
    {
        [$tx, $accounts] = $this->rows();
        $witness = LedgerPostingInputWitness::capture($tx, $accounts);
        $original = $accounts[20]['current_balance'];
        $accounts[20]['current_balance'] = '123.00';
        $accounts[20]['current_balance'] = $original;
        $witness->assertUnchanged($tx, $accounts);
        $this->assertSame('0.00', $accounts[20]['current_balance']);
    }
}
