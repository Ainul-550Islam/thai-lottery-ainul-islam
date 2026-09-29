<?php

declare(strict_types=1);

namespace App\Services\Payment\Drivers;

use App\DTOs\Payment\GatewayDepositResponse;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Models\Deposit;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

/**
 * Bank Transfer Gateway Driver (Manual operator review flow).
 */
class BankTransferGateway extends AbstractPaymentGateway
{
    public function name(): string
    {
        return 'bank_transfer';
    }

    public function label(): string
    {
        return 'Bank Transfer';
    }

    public function status(): GatewayIntegrationStatus
    {
        return GatewayIntegrationStatus::ConfiguredOnly;
    }

    public function supportsWebhook(): bool
    {
        return false;
    }

    /**
     * FINAL AUDIT #4/#5: deposit collection is only advertised as supported
     * when the operator has supplied the REAL settlement account. Without
     * it the capability guard refuses the deposit before anything is
     * persisted - no fake account, no orphan deposit. (Withdrawals do not
     * depend on this: payout details come from the player.)
     */
    public function supportsDeposit(): bool
    {
        if (! parent::supportsDeposit()) {
            return false;
        }

        $bankName = trim((string) $this->config->get('payment.gateways.bank_transfer.settlement.bank_name', ''));
        $accountNumber = trim((string) $this->config->get('payment.gateways.bank_transfer.settlement.account_number', ''));
        $accountName = trim((string) $this->config->get('payment.gateways.bank_transfer.settlement.account_name', ''));

        return $bankName !== '' && $accountNumber !== '' && $accountName !== '';
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return true;
    }

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        // FINAL AUDIT #4: settlement instructions are OPERATOR-PROVIDED or
        // absent. Nothing here may hardcode a bank, an account number or an
        // account holder - a plausible-looking fake account is worse than
        // no account, and an "official" account holder label this platform
        // is not entitled to claim would be worse still.
        $bankName = trim((string) $this->config->get('payment.gateways.bank_transfer.settlement.bank_name', ''));
        $accountNumber = trim((string) $this->config->get('payment.gateways.bank_transfer.settlement.account_number', ''));
        $accountName = trim((string) $this->config->get('payment.gateways.bank_transfer.settlement.account_name', ''));
        $instructions = trim((string) $this->config->get('payment.gateways.bank_transfer.settlement.instructions', ''));

        if ($bankName === '' || $accountNumber === '' || $accountName === '') {
            return GatewayDepositResponse::failed(
                'Bank transfer settlement instructions are not configured.',
                [],
                ['gateway' => $this->name(), 'reason' => 'bank_transfer_not_configured'],
            );
        }

        return GatewayDepositResponse::manual(
            providerReference: 'BANK-SLIP-'.$deposit->reference_number,
            instructions: [
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'account_name' => $accountName,
                'instructions' => $instructions !== '' ? $instructions : null,
                'reference' => $deposit->reference_number,
            ],
        );
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        return GatewayWithdrawalResponse::pending(
            providerReference: 'BANK-WD-'.$withdrawal->reference_number,
            rawResponse: [],
            metadata: ['manual_bank_wire' => true],
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        return new WebhookPayload(
            gateway: $this->name(),
            eventId: 'manual_'.bin2hex(random_bytes(6)),
            eventType: WebhookEventType::Unknown,
            providerReference: null,
            internalReference: null,
            amount: '0.00',
            currency: Currency::THB,
            isSuccess: false,
        );
    }
}
