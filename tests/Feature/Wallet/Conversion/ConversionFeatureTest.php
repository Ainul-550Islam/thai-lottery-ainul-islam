<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use App\Models\LedgerEntry;
use App\Models\WalletConversion;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Cash <-> Win lane as the member actually meets it: over HTTP.
 *
 * WHY THESE ASSERTIONS ARE ON THE TRANSPORT AND NOT THE SERVICE
 * The service suite already proves the money arithmetic. What it cannot prove is
 * that a browser or a mobile client ever REACHES that arithmetic: whether the
 * middleware lets a request through, whether a missing idempotency key is
 * refused before a wallet is touched, whether a retry returns the first
 * conversion instead of creating a second one, whether another member can read
 * this one's row. Those are the failures that reach production, because they are
 * the ones no unit test on the engine can see.
 *
 * The API half deliberately drives the real routes under `auth:sanctum`, and the
 * web half is driven with a real `web` session. Neither stubs the controller.
 */
class ConversionFeatureTest extends ConversionTestCase
{
    // ------------------------------------------------------------------ web

    #[Test]
    public function a_guest_is_sent_to_sign_in_rather_than_shown_a_conversion_form(): void
    {
        $this->get('/wallet/convert')->assertRedirect();
    }

    #[Test]
    public function the_web_quote_form_is_told_the_engines_own_numbers_before_anything_is_committed(): void
    {
        $member = $this->makeFundedMember();

        $response = $this->actingAs($member['user'])->post('/wallet/convert/quote', [
            'direction' => 'cash_to_win',
            'amount' => '100.00',
        ]);

        $response->assertRedirect();

        $breakdown = $this->calculator()->breakdownFor(
            WalletConversionDirection::CashToWin,
            '100.00',
            'THB',
        );

        // The member is shown the price as text on the page they return to, so the
        // assertion is on that text: a quote screen that renders a different fee
        // from the one the engine will charge is the same defect as a wrong price.
        $status = (string) session('status');

        $this->assertStringContainsString($breakdown->feeAmount, $status, 'The quote must state the fee the engine will charge.');
        $this->assertStringContainsString($breakdown->netAmount, $status, 'The quote must state what the member will receive.');
        $this->assertNotNull(session('conversion_quote_reference'), 'The form must be able to execute against this quote.');

        $this->assertSame('1000.00', $this->balanceOf($member['cash']), 'A quote moves no money.');
    }

    #[Test]
    public function a_web_execution_without_an_idempotency_key_moves_no_money(): void
    {
        $member = $this->makeFundedMember();

        $response = $this->actingAs($member['user'])->post('/wallet/convert', [
            'direction' => 'cash_to_win',
            'amount' => '100.00',
        ]);

        $response->assertStatus(422);

        $this->assertSame('1000.00', $this->balanceOf($member['cash']), 'A refused request must not move money.');
        $this->assertSame('0.00', $this->balanceOf($member['win']));
        $this->assertSame(0, WalletConversion::query()->count(), 'A refused request must not leave a conversion row.');
    }

    // ------------------------------------------------------------------ api

    #[Test]
    public function the_api_refuses_a_money_moving_call_that_carries_no_key(): void
    {
        $member = $this->makeFundedMember();
        Sanctum::actingAs($member['user']);

        $this->postJson('/api/v1/wallet/conversions', [
            'direction' => 'cash_to_win',
            'amount' => '100.00',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'idempotency_key_required');

        $this->assertSame('1000.00', $this->balanceOf($member['cash']));
    }

    #[Test]
    public function the_api_creates_a_conversion_and_answers_a_retry_with_the_first_one(): void
    {
        $member = $this->makeFundedMember();
        Sanctum::actingAs($member['user']);

        $quote = $this->postJson('/api/v1/wallet/conversions/quote', [
            'direction' => 'cash_to_win',
            'amount' => '100.00',
        ])->assertStatus(201)->json('data.quote');

        $payload = [
            'direction' => 'cash_to_win',
            'amount' => '100.00',
            'quote_reference' => $quote['reference'],
        ];

        $first = $this->postJson('/api/v1/wallet/conversions', $payload, [
            'Idempotency-Key' => 'api-retry-key-000001',
        ])->assertStatus(201)->json('data.conversion');

        $this->assertFalse((bool) ($first['replayed'] ?? false), 'The first call is not a replay.');

        $second = $this->postJson('/api/v1/wallet/conversions', $payload, [
            'Idempotency-Key' => 'api-retry-key-000001',
        ])->assertOk()->json('data.conversion');

        $this->assertSame($first['reference'], $second['reference'], 'A retry must return the original conversion.');
        $this->assertTrue((bool) ($second['replayed'] ?? false), 'The retry must be reported as a replay.');

        $this->assertSame(1, WalletConversion::query()->count(), 'A retry must not create a second conversion.');
        $this->assertSame('900.00', $this->balanceOf($member['cash']), 'Money must move exactly once.');
        $this->assertSame('99.00', $this->balanceOf($member['win']));
    }

    #[Test]
    public function one_member_can_never_read_another_members_conversion(): void
    {
        $mine = $this->makeFundedMember();
        $theirs = $this->makeFundedMember();

        Sanctum::actingAs($mine['user']);
        $reference = $this->postJson('/api/v1/wallet/conversions/quote', [
            'direction' => 'cash_to_win',
            'amount' => '50.00',
        ])->assertStatus(201)->json('data.quote.reference');

        $created = $this->postJson('/api/v1/wallet/conversions', [
            'direction' => 'cash_to_win',
            'amount' => '50.00',
            'quote_reference' => $reference,
        ], ['Idempotency-Key' => 'isolation-key-00000001'])->assertStatus(201)->json('data.conversion.reference');

        Sanctum::actingAs($theirs['user']);

        // 404, not 403: the existence of another member's conversion is itself
        // information this endpoint must not leak.
        $this->getJson('/api/v1/wallet/conversions/'.$created)->assertStatus(404);
    }

    #[Test]
    public function a_member_cannot_reverse_and_an_operator_can(): void
    {
        $member = $this->makeFundedMember();
        Sanctum::actingAs($member['user']);

        $reference = $this->postJson('/api/v1/wallet/conversions/quote', [
            'direction' => 'cash_to_win',
            'amount' => '100.00',
        ])->assertStatus(201)->json('data.quote.reference');

        $created = $this->postJson('/api/v1/wallet/conversions', [
            'direction' => 'cash_to_win',
            'amount' => '100.00',
            'quote_reference' => $reference,
        ], ['Idempotency-Key' => 'reversal-key-00000001'])->assertStatus(201)->json('data.conversion.reference');

        $this->postJson('/api/v1/wallet/conversions/'.$created.'/reverse', [
            'reason' => 'Member called support and the movement was made in error.',
        ], ['Idempotency-Key' => 'reversal-key-00000002'])->assertStatus(403);

        $this->assertSame('900.00', $this->balanceOf($member['cash']), 'A refused reversal must not move money.');

        $operator = $this->makeOperator();

        Sanctum::actingAs($operator);

        // The reversal is itself a MOVEMENT and it completes; the conversion it
        // undoes is the row that becomes `reversed`. Asserting the reversal were
        // `reversed` would be asserting a status that means nothing.
        $reversal = $this->postJson('/api/v1/wallet/conversions/'.$created.'/reverse', [
            'reason' => 'Operator reversal after a duplicate submission was confirmed by support.',
        ], ['Idempotency-Key' => 'reversal-key-00000003'])
            ->assertOk()
            ->assertJsonPath('data.reversal.status', WalletConversionStatus::Completed->value)
            ->assertJsonPath('data.reversal.direction', WalletConversionDirection::WinToCash->value)
            ->json('data.reversal');

        $original = WalletConversion::query()->where('reference', '=', $created)->firstOrFail();
        $reversalRow = WalletConversion::query()->where('reference', '=', $reversal['reference'])->firstOrFail();

        $this->assertSame(WalletConversionStatus::Reversed, $original->status, 'The undone conversion must say so.');
        $this->assertSame((int) $reversalRow->getKey(), (int) $original->reversed_by_id, 'The trail must name the reversal.');
        $this->assertSame((int) $original->getKey(), (int) $reversalRow->reversal_of_id, 'The reversal must name its original.');

        $this->assertSame('1000.00', $this->balanceOf($member['cash']), 'A reversal must restore the source wallet exactly.');
        $this->assertSame('0.00', $this->balanceOf($member['win']), 'A reversal must empty the destination exactly.');
    }

    // ------------------------------------------------------- whole-lane invariant

    #[Test]
    public function every_entry_written_by_the_lane_leaves_the_ledger_balanced(): void
    {
        $member = $this->makeFundedMember();

        $this->convert($member['user'], '250.00', idempotencyKey: 'invariant-key-00000001');

        $member2 = $this->makeFundedMember('400.00');
        $this->convert($member2['user'], '100.00', idempotencyKey: 'invariant-key-00000002');

        $this->assertGreaterThan(0, LedgerEntry::query()->count(), 'The lane must write ledger entries.');

        $row = DB::table('ledger_entries')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END), 0) as debits")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END), 0) as credits")
            ->first();

        $this->assertSame(
            0,
            bccomp((string) $row->debits, (string) $row->credits, 2),
            sprintf('Double entry must balance; debits %s vs credits %s.', (string) $row->debits, (string) $row->credits),
        );
    }

    #[Test]
    public function a_member_without_the_money_is_refused_and_nothing_is_written(): void
    {
        $member = $this->makeFundedMember('5.00');
        Sanctum::actingAs($member['user']);

        $this->postJson('/api/v1/wallet/conversions/quote', [
            'direction' => 'cash_to_win',
            'amount' => '100.00',
        ])->assertStatus(422);

        $this->assertSame(0, WalletConversion::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }
}
