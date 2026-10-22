<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Conversion;

use App\Enums\WalletConversionDirection;

/**
 * The conversion pages are the member's only view of their money. These tests render
 * them with real data, so a missing translation key, an undefined enum method or a
 * broken summary shape fails here rather than on the first member who opens the page.
 */
final class ConversionPageRenderingTest extends ConversionTestCase
{
    public function test_the_conversion_page_renders_for_a_funded_member_with_history(): void
    {
        $member = $this->makeFundedMember('1000.00');
        $this->convert($member['user'], '100.00', WalletConversionDirection::CashToWin, 'render-page-key-00000001');

        $this->actingAs($member['user'])
            ->get('/wallet/convert')
            ->assertOk()
            ->assertSee('Convert between your wallets')
            ->assertSee('Conversion history')
            ->assertSee('One conversion completed', false);
    }

    public function test_the_conversion_detail_page_renders_for_its_owner(): void
    {
        $member = $this->makeFundedMember('1000.00');
        $result = $this->convert($member['user'], '100.00', WalletConversionDirection::CashToWin, 'render-detail-key-00001');

        $this->actingAs($member['user'])
            ->get('/wallet/convert/'.$result->reference)
            ->assertOk()
            ->assertSee($result->reference);
    }

    public function test_another_member_cannot_open_a_conversion_detail_page(): void
    {
        $owner = $this->makeFundedMember('1000.00');
        $result = $this->convert($owner['user'], '100.00', WalletConversionDirection::CashToWin, 'render-foreign-key-00001');

        $stranger = $this->makeFundedMember('1000.00');

        $this->actingAs($stranger['user'])
            ->get('/wallet/convert/'.$result->reference)
            // Same answer as a missing reference, so the response cannot reveal which
            // references exist: a redirect to the page with a generic message.
            ->assertRedirect(route('player.wallet.conversion'));
    }

    public function test_the_live_preview_returns_the_engine_figures_without_moving_money(): void
    {
        $member = $this->makeFundedMember('1000.00');
        $before = $this->balanceOf($member['cash']);

        $response = $this->actingAs($member['user'])
            ->postJson('/wallet/convert/preview', [
                'direction' => WalletConversionDirection::CashToWin->value,
                'amount' => '100.00',
            ])
            ->assertOk()
            ->assertJsonPath('amount_in', '100.00')
            ->assertJsonPath('currency', 'THB');

        $fee = (string) $response->json('fee_amount');
        $this->assertSame(bcsub('100.00', $fee, 2), (string) $response->json('amount_out'));
        $this->assertSame($before, $this->balanceOf($member['cash']), 'A preview must never move money.');
    }
}
