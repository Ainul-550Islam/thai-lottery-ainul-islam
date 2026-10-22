<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\GatewayDepositResponse;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates idempotent deposit creation and provider checkout initiation.
 *
 * The wallet-backed deposit intent is committed before contacting a provider.
 * The provider call is serialized by a distributed cache lock, never by a
 * database transaction or a wallet row lock. This prevents a slow gateway from
 * holding financial locks while also ensuring simultaneous retries do not
 * create multiple checkout sessions for the same deposit. Provider requests
 * use the deposit's stable reference as their idempotency identity where the
 * provider protocol supports it.
 *
 * Gateway references and redirect instructions are persisted only after a
 * successful provider response. Provider-returned raw data is redacted before
 * persistence, and any client secret retained for an idempotent replay is
 * encrypted with the application key. No payment credential is written into
 * deposit metadata.
 */
final class PaymentInitiationService
{
    private const INITIATION_LOCK_SECONDS = 90;

    private const INITIATION_LOCK_WAIT_SECONDS = 35;

    public function __construct(
        private readonly DepositService $depositService,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * Initiate a deposit with a payment gateway.
     *
     * @param  array<string, mixed>  $options
     * @return array{deposit: Deposit, payment: Payment, gateway_response: GatewayDepositResponse}
     *
     * @throws FinancialException
     */
    public function initiateDeposit(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method,
        ?string $idempotencyKey = null,
        array $options = [],
    ): array {
        // Refuse unsupported capabilities before creating any financial record.
        $allowedMethods = (array) config('payment.deposit.allowed_methods', []);

        if ($allowedMethods !== [] && ! in_array($method->value, $allowedMethods, true)) {
            throw FinancialException::withCode(
                'payment_method_not_allowed',
                sprintf('Payment method [%s] is not allowed for deposits.', $method->value),
                ['method' => $method->value, 'allowed' => $allowedMethods],
            );
        }

        $driver = $this->gateways->depositDriver($method);

        if (! $driver->supportsCurrency($amount->currency())) {
            throw FinancialException::withCode(
                'unsupported_gateway_currency',
                sprintf('Gateway [%s] does not support currency [%s].', $driver->name(), $amount->currency()->value),
                ['gateway' => $driver->name(), 'currency' => $amount->currency()->value],
            );
        }

        // DepositService owns the wallet lock and its short database transaction.
        // Its transaction commits before this method makes a network request.
        $deposit = $this->depositService->request(
            wallet: $wallet,
            amount: $amount,
            method: $method,
            idempotencyKey: $idempotencyKey,
            options: $options,
        );

        try {
            return Cache::lock(
                'payment:deposit-initiation:'.(string) $deposit->getKey(),
                self::INITIATION_LOCK_SECONDS,
            )->block(self::INITIATION_LOCK_WAIT_SECONDS, function () use ($deposit, $driver, $options): array {
                // This is intentionally a short transaction. It also removes
                // client secrets written in plaintext by older deployments.
                $state = $this->loadCurrentState((int) $deposit->getKey());
                $currentDeposit = $state['deposit'];
                $existingPayment = $state['payment'];

                if ($existingPayment instanceof Payment && $this->hasProviderResponse($existingPayment)) {
                    return [
                        'deposit' => $currentDeposit,
                        'payment' => $existingPayment,
                        'gateway_response' => $this->restoreGatewayResponse($existingPayment),
                    ];
                }

                $status = $currentDeposit->status instanceof DepositStatus
                    ? $currentDeposit->status
                    : DepositStatus::tryFrom((string) $currentDeposit->status);

                if (! in_array($status, [DepositStatus::Pending, DepositStatus::Approved, DepositStatus::Processing], true)) {
                    throw FinancialException::withCode(
                        'deposit_not_initiable',
                        'This deposit is no longer eligible for gateway initiation.',
                        ['deposit_id' => (int) $currentDeposit->getKey(), 'deposit_status' => $status?->value],
                    );
                }

                // No database transaction is open here. The distributed lock
                // serializes retries while the external provider is contacted.
                $gatewayResponse = $driver->initiateDeposit($currentDeposit, $options);

                if (! $gatewayResponse->successful) {
                    throw FinancialException::withCode(
                        'payment_gateway_unavailable',
                        'The configured payment gateway could not initiate this deposit.',
                        ['gateway' => $driver->name(), 'deposit_id' => (int) $currentDeposit->getKey()],
                    );
                }

                $providerReference = trim((string) $gatewayResponse->providerReference);

                if ($providerReference === '' || strlen($providerReference) > 191) {
                    throw FinancialException::withCode(
                        'invalid_gateway_reference',
                        'The payment gateway returned an unusable transaction reference.',
                        ['gateway' => $driver->name(), 'deposit_id' => (int) $currentDeposit->getKey()],
                    );
                }

                try {
                    $persisted = $this->persistProviderResponse(
                        depositId: (int) $currentDeposit->getKey(),
                        gateway: $driver->name(),
                        response: $gatewayResponse,
                    );
                } catch (QueryException $exception) {
                    if (! $this->isPayableIdentityViolation($exception)) {
                        throw $exception;
                    }

                    // A signed provider webhook may have created the Payment
                    // row while checkout was being initialized. The unique
                    // payable constraint makes that race safe; read its winner.
                    $winner = $this->findPaymentForDeposit((int) $currentDeposit->getKey());

                    if (! $winner instanceof Payment) {
                        throw $exception;
                    }

                    $freshDeposit = Deposit::query()->find((int) $currentDeposit->getKey());

                    if (! $freshDeposit instanceof Deposit) {
                        throw $exception;
                    }

                    return [
                        'deposit' => $freshDeposit,
                        'payment' => $winner,
                        'gateway_response' => $this->restoreGatewayResponse($winner),
                    ];
                }

                return [
                    'deposit' => $persisted['deposit'],
                    'payment' => $persisted['payment'],
                    'gateway_response' => $this->restoreGatewayResponse($persisted['payment']),
                ];
            });
        } catch (LockTimeoutException $exception) {
            throw FinancialException::withCode(
                'payment_initiation_in_progress',
                'A deposit checkout is already being initialized. Retry with the same idempotency key.',
                ['deposit_id' => (int) $deposit->getKey()],
                $exception,
            );
        }
    }

    /**
     * @return array{deposit: Deposit, payment: Payment|null}
     */
    private function loadCurrentState(int $depositId): array
    {
        return DB::transaction(function () use ($depositId): array {
            $deposit = Deposit::query()->lockForUpdate()->findOrFail($depositId);
            $depositMetadata = is_array($deposit->metadata) ? $deposit->metadata : [];
            $depositChanged = array_key_exists('client_secret', $depositMetadata)
                || array_key_exists('redirect_url', $depositMetadata);

            unset($depositMetadata['client_secret'], $depositMetadata['redirect_url']);

            if ($depositChanged) {
                $deposit->metadata = $depositMetadata;
                $deposit->save();
            }

            $payment = Payment::query()
                ->where('payable_type', Deposit::class)
                ->where('payable_id', $depositId)
                ->lockForUpdate()
                ->first();

            if ($payment instanceof Payment) {
                $paymentMetadata = is_array($payment->metadata) ? $payment->metadata : [];
                $paymentMetadataChanged = false;

                if (array_key_exists('client_secret', $paymentMetadata)) {
                    unset($paymentMetadata['client_secret']);
                    $paymentMetadataChanged = true;
                }

                if (array_key_exists('redirect_url', $paymentMetadata)) {
                    $legacyRedirectUrl = $paymentMetadata['redirect_url'];
                    unset($paymentMetadata['redirect_url']);
                    $paymentMetadataChanged = true;

                    if (is_string($legacyRedirectUrl) && trim($legacyRedirectUrl) !== '') {
                        $existingEncryptedUrl = $paymentMetadata['redirect_url_encrypted'] ?? null;

                        if (! is_string($existingEncryptedUrl) || $existingEncryptedUrl === '') {
                            $paymentMetadata['redirect_url_encrypted'] = Crypt::encryptString(trim($legacyRedirectUrl));
                        }
                    }
                }

                if ($paymentMetadataChanged) {
                    $payment->metadata = $paymentMetadata;
                    $payment->save();
                }
            }

            return [
                'deposit' => $deposit->refresh(),
                'payment' => $payment instanceof Payment ? $payment->refresh() : null,
            ];
        }, 3);
    }

    /**
     * Persist provider output under short row locks after the network call.
     *
     * @return array{deposit: Deposit, payment: Payment}
     */
    private function persistProviderResponse(int $depositId, string $gateway, GatewayDepositResponse $response): array
    {
        return DB::transaction(function () use ($depositId, $gateway, $response): array {
            $deposit = Deposit::query()->lockForUpdate()->findOrFail($depositId);
            $payment = Payment::query()
                ->where('payable_type', Deposit::class)
                ->where('payable_id', $depositId)
                ->lockForUpdate()
                ->first();

            if (! $payment instanceof Payment) {
                $payment = new Payment;
                $payment->fill([
                    'reference_number' => 'PAY-'.strtoupper(bin2hex(random_bytes(8))),
                    'user_id' => (int) $deposit->user_id,
                    'payable_type' => Deposit::class,
                    'payable_id' => (int) $deposit->getKey(),
                    'method' => $deposit->method,
                    'status' => PaymentStatus::Pending,
                    'currency' => $deposit->currency,
                    'amount' => (string) $deposit->amount,
                    'fee' => (string) $deposit->fee,
                ]);
            } elseif ((int) $payment->user_id !== (int) $deposit->user_id
                || (int) $payment->payable_id !== (int) $deposit->getKey()
                || $payment->payable_type !== Deposit::class) {
                throw FinancialException::withCode(
                    'payment_deposit_identity_mismatch',
                    'The payment record does not match its deposit.',
                    ['deposit_id' => $depositId, 'payment_id' => (int) $payment->getKey()],
                );
            }

            $payment->gateway ??= $gateway;

            if (! is_string($payment->gateway_reference) || trim($payment->gateway_reference) === '') {
                $payment->gateway_reference = trim((string) $response->providerReference);
            }

            if ($payment->gateway_response === null) {
                $payment->gateway_response = $this->redactGatewayPayload($response->rawResponse);
            }

            $paymentMetadata = is_array($payment->metadata) ? $payment->metadata : [];
            $paymentMetadata['deposit_reference'] = (string) $deposit->reference_number;

            if (is_string($response->redirectUrl) && trim($response->redirectUrl) !== '') {
                $paymentMetadata['redirect_url_encrypted'] = Crypt::encryptString(trim($response->redirectUrl));
            }

            unset($paymentMetadata['redirect_url']);

            $instructions = $this->safeInstructions($response->metadata['instructions'] ?? null);

            if ($instructions !== []) {
                $paymentMetadata['instructions'] = $instructions;
            }

            if (is_string($response->clientSecret) && $response->clientSecret !== '') {
                $paymentMetadata['client_secret_encrypted'] = Crypt::encryptString($response->clientSecret);
            }

            unset($paymentMetadata['client_secret']);
            $paymentMetadata['gateway_initiation_state'] = 'ready';
            $payment->metadata = $paymentMetadata;
            $payment->save();

            $depositMetadata = is_array($deposit->metadata) ? $deposit->metadata : [];
            unset($depositMetadata['client_secret'], $depositMetadata['redirect_url']);
            $depositMetadata['payment_id'] = (int) $payment->getKey();
            $deposit->metadata = $depositMetadata;
            $deposit->provider ??= $gateway;

            if (! is_string($deposit->provider_reference) || trim($deposit->provider_reference) === '') {
                $deposit->provider_reference = $payment->gateway_reference;
            }

            $deposit->save();

            return [
                'deposit' => $deposit->refresh(),
                'payment' => $payment->refresh(),
            ];
        }, 3);
    }

    private function hasProviderResponse(Payment $payment): bool
    {
        $reference = is_string($payment->gateway_reference) ? trim($payment->gateway_reference) : '';
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];
        $status = $payment->status instanceof PaymentStatus
            ? $payment->status
            : PaymentStatus::tryFrom((string) $payment->status);

        return $reference !== ''
            || (is_string($metadata['redirect_url_encrypted'] ?? null) && $metadata['redirect_url_encrypted'] !== '')
            || (is_string($metadata['redirect_url'] ?? null) && trim($metadata['redirect_url']) !== '')
            || (is_array($metadata['instructions'] ?? null) && $metadata['instructions'] !== [])
            || ($status instanceof PaymentStatus && $status->isFinal());
    }

    private function restoreGatewayResponse(Payment $payment): GatewayDepositResponse
    {
        $metadata = is_array($payment->metadata) ? $payment->metadata : [];
        $status = $payment->status instanceof PaymentStatus
            ? $payment->status
            : PaymentStatus::tryFrom((string) $payment->status);
        $isFinal = $status instanceof PaymentStatus && $status->isFinal();
        $clientSecret = null;
        $redirectUrl = null;
        $encryptedSecret = $metadata['client_secret_encrypted'] ?? null;
        $encryptedRedirectUrl = $metadata['redirect_url_encrypted'] ?? null;

        if (! $isFinal && is_string($encryptedSecret) && $encryptedSecret !== '') {
            try {
                $clientSecret = Crypt::decryptString($encryptedSecret);
            } catch (Throwable $exception) {
                report($exception);

                throw FinancialException::withCode(
                    'payment_checkout_state_unreadable',
                    'The saved payment checkout credentials are unavailable. Retry using the same idempotency key.',
                    ['payment_id' => (int) $payment->getKey()],
                    $exception,
                );
            }
        }

        if (! $isFinal && is_string($encryptedRedirectUrl) && $encryptedRedirectUrl !== '') {
            try {
                $redirectUrl = Crypt::decryptString($encryptedRedirectUrl);
            } catch (Throwable $exception) {
                report($exception);

                throw FinancialException::withCode(
                    'payment_checkout_state_unreadable',
                    'The saved payment checkout link is unavailable. Retry using the same idempotency key.',
                    ['payment_id' => (int) $payment->getKey()],
                    $exception,
                );
            }
        } elseif (! $isFinal && is_string($metadata['redirect_url'] ?? null)) {
            // In-memory compatibility only for a row that bypassed the normal
            // loadCurrentState migration path; new and replayed state is encrypted.
            $redirectUrl = $metadata['redirect_url'];
        }

        return new GatewayDepositResponse(
            successful: true,
            redirectUrl: $redirectUrl,
            providerReference: is_string($payment->gateway_reference) && $payment->gateway_reference !== ''
                ? $payment->gateway_reference
                : null,
            clientSecret: $isFinal ? null : $clientSecret,
            rawResponse: is_array($payment->gateway_response) ? $payment->gateway_response : [],
            metadata: ! $isFinal ? ['instructions' => $this->safeInstructions($metadata['instructions'] ?? null)] : [],
        );
    }

    /** @return array<string, mixed> */
    private function safeInstructions(mixed $instructions): array
    {
        if (! is_array($instructions)) {
            return [];
        }

        return array_intersect_key($instructions, array_flip([
            'bank_name',
            'account_number',
            'account_name',
            'instructions',
            'reference',
        ]));
    }

    /** @param array<string, mixed> $payload
     *  @return array<string, mixed>
     */
    private function redactGatewayPayload(array $payload): array
    {
        $safe = [];

        foreach ($payload as $key => $value) {
            $name = is_string($key) ? $key : (string) $key;

            if (preg_match('/(?:secret|token|password|authorization|account[_-]?number|card[_-]?(?:number|cvv)|url|uri|checkout|redirect|confirmation|payment.*link|\b(?:cvv|cvc|pin)\b)/i', $name) === 1) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->redactGatewayPayload($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function findPaymentForDeposit(int $depositId): ?Payment
    {
        $payment = Payment::query()
            ->where('payable_type', Deposit::class)
            ->where('payable_id', $depositId)
            ->first();

        return $payment instanceof Payment ? $payment : null;
    }

    private function isPayableIdentityViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'payments_payable_once_unique')
            || (str_contains($message, 'payments.payable_type') && str_contains($message, 'payments.payable_id'));
    }
}
