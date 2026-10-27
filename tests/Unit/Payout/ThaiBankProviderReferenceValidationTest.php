<?php

declare(strict_types=1);

namespace Tests\Unit\Payout;

use App\Enums\ThaiBankPayoutState;
use App\Exceptions\FinancialException;
use App\Services\Payout\ThaiBank\KbankPayoutAdapter;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use App\Services\Payout\ThaiBank\ThaiBankPayoutAdapterInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ThaiBankProviderReferenceValidationTest extends TestCase
{
    /** @return iterable<string, array{string, mixed, bool}> */
    public static function references(): iterable
    {
        $references = [
            'single character' => ['A', true],
            'allowed punctuation including slash' => ['BANK/2026:10.26_ABC-01', true],
            'maximum length' => ['A'.str_repeat('x', 190), true],
            'too long' => ['A'.str_repeat('x', 191), false],
            'empty' => ['', false],
            'leading slash' => ['/BANK-001', false],
            'space' => ['BANK 001', false],
            'newline' => ["BANK-001\n", false],
            'control byte' => ["BANK\0-001", false],
            'query punctuation' => ['BANK?001', false],
            'unicode' => ['BANK-ไทย', false],
            'integer' => [123, false],
            'array' => [['BANK-001'], false],
        ];

        foreach (['scb', 'kbank'] as $bank) {
            foreach ($references as $name => [$reference, $valid]) {
                yield $bank.' '.$name => [$bank, $reference, $valid];
            }
        }
    }

    #[DataProvider('references')]
    public function test_callback_preserves_only_valid_provider_references(string $bank, mixed $reference, bool $valid): void
    {
        $body = json_encode([
            'reference' => 'WD-REFERENCE-001',
            'state' => 'SETTLED',
            'providerReference' => $reference,
            'amount' => '50.00',
            'currency' => 'THB',
        ], JSON_THROW_ON_ERROR);
        $adapter = $this->adapter($bank);

        if (! $valid) {
            try {
                $adapter->parseCallback($body);
                self::fail('An invalid provider reference must refuse the callback.');
            } catch (FinancialException $exception) {
                self::assertSame('payout_webhook_payload_invalid', $exception->errorCode());
            }

            return;
        }

        $parsed = $adapter->parseCallback($body);
        self::assertSame($reference, $parsed['providerReference']);
        self::assertSame('WD-REFERENCE-001', $parsed['reference']);
        self::assertSame('SETTLED', $parsed['state']);
        self::assertSame('50.00', $parsed['amount']);
        self::assertSame('THB', $parsed['currency']);
    }

    #[DataProvider('references')]
    public function test_http_responses_keep_valid_references_and_fail_closed_for_invalid_ones(string $bank, mixed $reference, bool $valid): void
    {
        $adapter = $this->adapter($bank);
        $responses = Http::sequence();
        foreach (['ACCEPTED', 'SETTLED'] as $status) {
            $responses->push(['data' => [
                'status' => $status,
                'transactionId' => $reference,
                'amount' => '50.00',
                'currency' => 'THB',
            ]]);
        }
        Http::preventStrayRequests();
        Http::fake(['https://api.'.$bank.'.test/*' => $responses]);

        foreach ([ThaiBankPayoutState::Accepted, ThaiBankPayoutState::Settled] as $state) {
            $outcome = $adapter->queryStatus('WD-REFERENCE-001');

            self::assertSame($valid ? $state : ThaiBankPayoutState::Uncertain, $outcome->state);
            self::assertSame($valid ? $reference : null, $outcome->providerReference);
            self::assertSame($valid ? $reference : null, $outcome->rawSanitised['provider_reference'] ?? null);
            self::assertSame(200, $outcome->rawSanitised['http_status']);
            self::assertSame('50.00', $outcome->rawSanitised['amount']);
            self::assertSame('THB', $outcome->rawSanitised['currency']);
        }
        Http::assertSentCount(2);
    }

    private function adapter(string $bank): ThaiBankPayoutAdapterInterface
    {
        config([
            'thai_payout.enabled' => true,
            'thai_payout.banks.'.$bank => [
                'enabled' => true,
                'contract_confirmed' => true,
                'status_method' => $bank === 'scb' ? 'GET' : 'POST',
                'base_url' => 'https://api.'.$bank.'.test',
                'token_path' => '/oauth/token',
                'transfer_path' => '/transfers',
                'status_path' => '/transfers/{reference}',
                'client_id' => 'reference-test-client',
                'client_secret' => 'reference-test-secret',
                'resource_owner_id' => 'reference-test-owner',
                'source_account' => 'reference-test-source',
                'webhook_secret' => 'reference-test-callback',
                'timeout_seconds' => 5,
            ],
        ]);
        $adapter = $bank === 'scb' ? new ScbPayoutAdapter : new KbankPayoutAdapter;
        Cache::put($adapter::tokenCacheKey('reference-test-client'), 'reference-test-token', now()->addHour());

        return $adapter;
    }
}
