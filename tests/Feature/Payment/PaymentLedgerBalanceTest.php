<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\WebhookEventType;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Services\Finance\ExternalRefundPolicy;
use App\Services\Finance\RefundService;
use App\Services\Finance\WalletService;
use PHPUnit\Framework\Attributes\Test;

/**
 * Deposit postings are strict double-entry, and an external chargeback may not
 * touch the books until a provider-specific refund/dispute policy exists.
 *
 * WHY THE SECOND HALF EXPECTS A REFUSAL
 * -------------------------------------
 * A signed `charge.refunded` event proves that the provider says something
 * happened. It does not prove a merchant/refund/dispute policy, an
 * original-payment identity, fees, partial-vs-full scope, duplicate handling or
 * bank delivery. `App\Services\Finance\ExternalRefundPolicy` records that this
 * policy is absent, and both the webhook reversal lane and the legacy
 * `RefundService` fail closed with `external_refund_policy_not_configured`
 * (see docs/remaining-work-part-39.md). Consequently a chargeback webhook must
 * leave the wallet, the deposit lifecycle and the ledger untouched; only a
 * later, independently authorized provider adapter may post the compensating
 * transaction. This test pins that "no money moved" guarantee.
 */
final class PaymentLedgerBalanceTest extends PaymentTestCase
{
    #[Test]
    public function deposit_posting_is_strict_double_entry_and_external_chargeback_moves_no_money(): void
    {
        $player = $this->createPlayer('0.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '1500.00', PaymentMethod::Stripe);

        // 1. Process successful deposit webhook
        $successPayload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_stripe_ledger_888',
            eventType: WebhookEventType::DepositSuccess,
            providerReference: 'cs_test_session_888',
            internalReference: $deposit->reference_number,
            amount: '1500.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        $depositResult = $this->webhookService()->processWebhookPayload($successPayload);
        $this->assertTrue($depositResult->success);

        $deposit->refresh();
        $depositTx = FinancialTransaction::query()->find($deposit->financial_transaction_id);
        $this->assertInstanceOf(FinancialTransaction::class, $depositTx);

        $depositEntries = LedgerEntry::query()->where('financial_transaction_id', $depositTx->id)->get();
        $this->assertCount(2, $depositEntries);

        $cashAccount = LedgerAccount::query()->where('code', WalletService::ACCOUNT_SYSTEM_CASH)->firstOrFail();
        $liabilityAccount = LedgerAccount::query()->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)->firstOrFail();

        $cashEntry = $depositEntries->firstWhere('ledger_account_id', $cashAccount->id);
        $liabilityEntry = $depositEntries->firstWhere('ledger_account_id', $liabilityAccount->id);

        $this->assertNotNull($cashEntry);
        $this->assertSame('debit', $cashEntry->type->value);
        $this->assertSame('1500.00', (string) $cashEntry->amount);

        $this->assertNotNull($liabilityEntry);
        $this->assertSame('credit', $liabilityEntry->type->value);
        $this->assertSame('1500.00', (string) $liabilityEntry->amount);

        $player['wallet']->refresh();
        $this->assertSame('1500.00', (string) $player['wallet']->balance);

        // 2. A chargeback webhook is REFUSED — no provider refund policy exists yet.
        $refundPayload = new WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_stripe_refund_888',
            eventType: WebhookEventType::ChargeRefunded,
            providerReference: 'cs_test_session_888',
            internalReference: $deposit->reference_number,
            amount: '1500.00',
            currency: Currency::THB,
            isSuccess: false,
        );

        $refundResult = $this->webhookService()->processWebhookPayload($refundPayload);

        $this->assertFalse($refundResult->success, 'An external chargeback must not report success.');
        $this->assertSame('failed', $refundResult->actionTaken);
        $this->assertSame(ExternalRefundPolicy::MESSAGE, $refundResult->message);

        // Nothing moved: the deposit is still the confirmed one, the wallet still
        // holds the credited money, and the ledger still carries exactly the two
        // original legs.
        $deposit->refresh();
        $this->assertSame(DepositStatus::Confirmed, $deposit->status);

        $player['wallet']->refresh();
        $this->assertSame('1500.00', (string) $player['wallet']->balance);

        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertSame(
            2,
            LedgerEntry::query()->where('financial_transaction_id', $depositTx->id)->count(),
            'The refused chargeback must not append a compensating posting.',
        );

        $this->assertSame(
            DepositStatus::Confirmed,
            $deposit->status,
            'A refused chargeback must not relabel the deposit as refunded.',
        );

        // 3. The domain refund entry point refuses for the same reason, so no
        //    second, weaker refund path exists behind the webhook.
        $refusal = null;
        try {
            app(RefundService::class)->refundDeposit((int) $deposit->id, null, 'test chargeback');
        } catch (FinancialException $exception) {
            $refusal = $exception;
        }

        $this->assertInstanceOf(FinancialException::class, $refusal);
        $this->assertSame(ExternalRefundPolicy::CODE, $refusal->errorCode());

        // Still untouched after both refusal attempts.
        $player['wallet']->refresh();
        $this->assertSame('1500.00', (string) $player['wallet']->balance);

        // Overall ledger balance across all entries must sum to exactly zero.
        $totalDebits = '0.00';
        $totalCredits = '0.00';

        foreach (LedgerEntry::query()->get() as $entry) {
            if ($entry->type->value === 'debit') {
                $totalDebits = bcadd($totalDebits, (string) $entry->amount, 2);
            } else {
                $totalCredits = bcadd($totalCredits, (string) $entry->amount, 2);
            }
        }

        $this->assertSame('1500.00', $totalDebits);
        $this->assertSame($totalDebits, $totalCredits, 'The ledger must stay balanced.');
    }
}
