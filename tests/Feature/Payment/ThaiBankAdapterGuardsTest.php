<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PayoutInstruction;
use App\Enums\ThaiBankPayoutState;
use App\Services\Payout\ThaiBank\KbankPayoutAdapter;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class ThaiBankAdapterGuardsTest extends Batch01TestCase
{
    public function test_adapters_refuse_missing_contract_confirmation_and_empty_paths(): void
    {
        config([
            'thai_payout.enabled' => true,
            'thai_payout.banks.scb' => [
                'enabled' => true, 'contract_confirmed' => true, 'base_url' => 'https://scb.example.test',
                'token_path' => '/token', 'transfer_path' => '/transfer', 'status_path' => '',
                'status_method' => 'GET', 'client_id' => 'client', 'client_secret' => 'secret',
                'resource_owner_id' => 'owner', 'webhook_secret' => 'webhook', 'source_account' => '1234567890',
                'timeout_seconds' => 10,
            ],
            'thai_payout.banks.kbank' => [
                'enabled' => true, 'contract_confirmed' => false, 'base_url' => 'https://kbank.example.test',
                'token_path' => '/token', 'transfer_path' => '', 'status_path' => '/status',
                'client_id' => 'client', 'client_secret' => 'secret', 'webhook_secret' => 'webhook',
                'source_account' => '1234567890', 'timeout_seconds' => 10,
            ],
        ]);

        self::assertFalse(app(ScbPayoutAdapter::class)->configured());
        self::assertFalse(app(KbankPayoutAdapter::class)->configured());
    }

    public function test_transfer_timeout_maps_to_uncertain_without_retrying_the_transfer(): void
    {
        config([
            'thai_payout.enabled' => true,
            'thai_payout.banks.scb' => [
                'enabled' => true, 'contract_confirmed' => true, 'base_url' => 'https://scb.example.test',
                'token_path' => '/token', 'transfer_path' => '/transfer', 'status_path' => '/status/{reference}',
                'status_method' => 'GET', 'client_id' => 'client', 'client_secret' => 'secret',
                'resource_owner_id' => 'owner', 'webhook_secret' => 'webhook', 'source_account' => '1234567890',
                'timeout_seconds' => 10,
            ],
        ]);
        $transferCalls = 0;
        Http::fake(static function (Request $request) use (&$transferCalls) {
            if (str_ends_with($request->url(), '/token')) {
                return Http::response(['data' => ['accessToken' => 'access-token', 'expiresIn' => 3600]], 200);
            }
            $transferCalls++;
            throw new ConnectionException('simulated transfer timeout');
        });
        $instruction = new PayoutInstruction(
            reference: 'WD-THAI-TIMEOUT-01', amount: '250.00', currency: 'THB',
            destinationType: 'bank_account', bankCode: '014', accountNumber: '1234567890',
            accountName: 'Recipient', memo: '',
        );

        $outcome = app(ScbPayoutAdapter::class)->initiateTransfer($instruction);

        self::assertSame(ThaiBankPayoutState::Uncertain, $outcome->state);
        self::assertSame(1, $transferCalls);
    }
}
