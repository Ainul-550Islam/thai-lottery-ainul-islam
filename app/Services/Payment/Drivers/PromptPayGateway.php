<?php

declare(strict_types=1);

namespace App\Services\Payment\Drivers;

use App\DTOs\Payment\GatewayDepositResponse;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Services\Payment\KasikornBankWebhookService;
use App\Services\Payment\PromptPayQrGeneratorService;
use App\Services\Payment\ScbOpenApiWebhookService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

final class PromptPayGateway extends AbstractPaymentGateway
{
    public function __construct(
        ConfigRepository $config,
        private readonly PromptPayQrGeneratorService $qrGenerator,
        private readonly KasikornBankWebhookService $kbankWebhooks,
        private readonly ScbOpenApiWebhookService $scbWebhooks,
    ) {
        parent::__construct($config);
    }

    public function name(): string
    {
        return 'promptpay';
    }

    public function label(): string
    {
        return 'PromptPay';
    }

    public function status(): GatewayIntegrationStatus
    {
        return $this->merchantId() !== null
            ? GatewayIntegrationStatus::FullyImplemented
            : GatewayIntegrationStatus::ConfiguredOnly;
    }

    public function isEnabled(): bool
    {
        return parent::isEnabled() && $this->merchantId() !== null;
    }

    public function supportsDeposit(): bool
    {
        return true;
    }

    public function supportsWithdrawal(): bool
    {
        return false;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return $currency === Currency::THB;
    }

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        if (! $this->isEnabled()) {
            return GatewayDepositResponse::failed('PromptPay merchant identity is missing or the rail is disabled.');
        }
        if ($deposit->currency !== Currency::THB) {
            return GatewayDepositResponse::failed('PromptPay deposits are THB-only.');
        }

        $merchantId = $this->merchantId();
        $reference = trim((string) $deposit->reference_number);
        if ($merchantId === null || $reference === '') {
            return GatewayDepositResponse::failed('PromptPay deposit reference is missing.');
        }

        try {
            $payload = $this->qrGenerator->generatePayload(
                recipientId: $merchantId,
                amount: bcadd((string) $deposit->amount, '0.00', 2),
                billReference: $reference,
            );
        } catch (FinancialException $exception) {
            return GatewayDepositResponse::failed($exception->getMessage());
        }

        $minutes = max(1, min(60, (int) $this->config->get('payment.gateways.promptpay.qr_expiry_minutes', 15)));
        $expiresAt = now()->copy()->addMinutes($minutes);

        return new GatewayDepositResponse(
            successful: true,
            providerReference: $reference,
            rawResponse: [],
            metadata: [
                'qr_payload' => $payload,
                'bill_reference' => $reference,
                'expires_at' => $expiresAt->toIso8601String(),
                'merchant_name' => trim((string) $this->config->get('payment.gateways.promptpay.merchant_name', 'Thai Lottery')),
                'currency' => Currency::THB->value,
            ],
        );
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        return GatewayWithdrawalResponse::failed('PromptPay payouts are not supported');
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        if ($request->headers->has('X-SCB-Signature')) {
            return $this->scbWebhooks->verifyRequest($request);
        }

        return $this->kbankWebhooks->verifyRequest($request);
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        throw FinancialException::withCode(
            'promptpay_bank_route_required',
            'PromptPay webhooks must use their signed KBank or SCB endpoint so matching cannot be bypassed.',
        );
    }

    private function merchantId(): ?string
    {
        $merchantId = trim((string) $this->config->get('payment.gateways.promptpay.merchant_id', ''));

        return $merchantId !== '' && $this->qrGenerator->isValidRecipientId($merchantId) ? $merchantId : null;
    }
}
