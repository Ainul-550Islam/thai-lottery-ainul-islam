<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Http\Controllers\Api\V1\DepositController as CanonicalDepositController;
use App\Http\Controllers\Api\V1\Payment\SlipVerificationController;
use App\Http\Requests\Payment\VerifySlipRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentInitiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Compatibility adapter for retired deposit-method pages.
 *
 * The former implementation exposed hardcoded bank accounts, exchange rates,
 * QR payloads, OCR success, random references and completed statuses. Those
 * values were not evidence of a provider or ledger transition. The canonical
 * authenticated deposit page and V1 deposit controller are now the only
 * deposit contract; unsupported legacy provider-specific operations fail
 * closed.
 *
 * WHAT THIS ADAPTER MUST NEVER DO
 * -------------------------------
 * A deposit page is a promise to the player that money can be collected on a
 * given rail. The only honest way to keep that promise is to derive the
 * listing from the same gateway manager the initiation path uses: a rail
 * whose driver is disabled, unconfigured or not deposit-capable is reported
 * NOT_CONFIGURED, so a client cannot render a button that only ever returns
 * an error. `crypto` is the one rail that is never a deposit rail here — it
 * is reported COMING_SOON with no initiate route at all.
 */
final class DepositMethodsPageController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return $request->user() !== null
            ? redirect()->route('player.deposit')
            : redirect()->route('login');
    }

    /**
     * The TRUE deposit rails for this deployment.
     *
     * `enabled` is computed by PaymentGatewayManager, not by a hardcoded
     * list: the driver must be switched on, must report itself configured,
     * and must declare deposit support. The wallet is derived from the
     * authenticated principal further down the initiation path, never from
     * anything a client sent.
     */
    public function getDepositMethodsApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        $manager = app(PaymentGatewayManager::class);

        $rails = [
            $this->rail($manager, PaymentMethod::Stripe, 'api.v1.payments.intents.create'),
            $this->rail($manager, PaymentMethod::Bkash, 'api.v1.payments.intents.create'),
            $this->rail($manager, PaymentMethod::Nagad, 'api.v1.payments.intents.create'),
            $this->rail($manager, PaymentMethod::PromptPay, 'api.v1.deposit.promptpay.generate'),
            $this->rail($manager, PaymentMethod::BankTransfer, 'api.v1.deposit.bank-transfer.intent'),
        ];

        return ApiResponse::success([
            'methods' => $rails,
            'default_currency' => Currency::THB->value,
            'currencies' => ['THB', 'USD', 'BDT'],
        ], 'Deposit methods retrieved.');
    }

    public function generatePromptPayApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }
        if (! app(PaymentGatewayManager::class)->isDepositCapable(PaymentMethod::PromptPay)) {
            return $this->notConfigured('PromptPay is disabled or its merchant identity is not configured.');
        }

        $idempotencyKey = $request->input('idempotency_key')
            ?? $request->header('X-Idempotency-Key')
            ?? $request->header('Idempotency-Key');
        if (is_string($idempotencyKey)) {
            $request->merge(['idempotency_key' => $idempotencyKey]);
        }
        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'merchant_id' => ['prohibited'],
            'currency' => ['prohibited'],
        ]);

        $wallet = Wallet::query()->where('user_id', $request->user()->id)->where('currency', Currency::THB->value)->first();
        if (! $wallet instanceof Wallet) {
            return ApiResponse::error('wallet_not_found', 'No THB wallet is available for this account.', 404);
        }

        try {
            $result = app(PaymentInitiationService::class)->initiateDeposit(
                wallet: $wallet,
                amount: Money::of($validated['amount'], Currency::THB),
                method: PaymentMethod::PromptPay,
                idempotencyKey: $validated['idempotency_key'],
                options: ['ip' => $request->ip()],
            );
        } catch (FinancialException $exception) {
            $code = $exception->errorCode() ?? 'deposit_request_refused';
            if (in_array($code, ['payment_gateway_disabled', 'payment_gateway_unavailable'], true)) {
                return $this->notConfigured('PromptPay is disabled or its merchant identity is not configured.');
            }

            return ApiResponse::error($code, 'PromptPay checkout could not be initiated.', 422);
        }

        $deposit = $result['deposit'];
        $gateway = $result['gateway_response'];
        $qrPayload = $gateway->metadata['qr_payload'] ?? null;
        $expiresAt = $gateway->metadata['expires_at'] ?? null;
        if (! is_string($qrPayload) || $qrPayload === '' || ! is_string($expiresAt) || $expiresAt === '') {
            return $this->notConfigured('PromptPay checkout evidence is unavailable.');
        }

        return ApiResponse::success([
            'deposit_id' => $deposit->id,
            'reference' => $deposit->reference_number,
            'reference_number' => $deposit->reference_number,
            'amount' => (string) $deposit->amount,
            'currency' => Currency::THB->value,
            'qr_payload' => $qrPayload,
            'expires_at' => $expiresAt,
        ], 'PromptPay checkout initiated.', 201);
    }

    /**
     * Bank-transfer deposit intent (authenticated, THB).
     *
     * WHAT IS RETURNED IS CONFIGURED OR ABSENT
     * ---------------------------------------
     * The receiving accounts come from slip_verification.receiving_accounts,
     * which is operator configuration. When that list is empty this endpoint
     * has nothing truthful to tell the player, so it refuses with 503 rather
     * than inventing an account number — a plausible-looking fake bank
     * account is worse than no account at all.
     *
     * WHAT IT DOES NOT DO
     * -------------------
     * It does not credit anything. The deposit it creates is PENDING: the
     * player transfers the money, and the money is only recognised by the
     * slip-verification lane or an operator review, exactly as before.
     */
    public function createBankTransferIntentApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        $receivingAccounts = $this->receivingAccounts();
        if ($receivingAccounts === []) {
            return $this->notConfigured('No bank-transfer receiving account is configured for this deployment.');
        }

        $idempotencyKey = $request->input('idempotency_key')
            ?? $request->header('X-Idempotency-Key')
            ?? $request->header('Idempotency-Key');
        if (is_string($idempotencyKey)) {
            $request->merge(['idempotency_key' => $idempotencyKey]);
        }
        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'merchant_id' => ['prohibited'],
            'currency' => ['prohibited'],
        ]);

        $wallet = Wallet::query()->where('user_id', $request->user()->id)->where('currency', Currency::THB->value)->first();
        if (! $wallet instanceof Wallet) {
            return ApiResponse::error('wallet_not_found', 'No THB wallet is available for this account.', 404);
        }

        try {
            $result = app(PaymentInitiationService::class)->initiateDeposit(
                wallet: $wallet,
                amount: Money::of($validated['amount'], Currency::THB),
                method: PaymentMethod::BankTransfer,
                idempotencyKey: $validated['idempotency_key'],
                options: ['ip' => $request->ip()],
            );
        } catch (FinancialException $exception) {
            $code = $exception->errorCode() ?? 'deposit_request_refused';
            if (in_array($code, ['payment_gateway_disabled', 'payment_gateway_unavailable', 'payment_method_not_allowed'], true)) {
                return $this->notConfigured('Bank-transfer deposits are not enabled on this deployment.');
            }

            return ApiResponse::error($code, 'Bank-transfer deposit could not be initiated.', 422);
        }

        $deposit = $result['deposit'];

        return ApiResponse::success([
            'deposit_id' => $deposit->id,
            'reference' => $deposit->reference_number,
            'reference_number' => $deposit->reference_number,
            'amount' => (string) $deposit->amount,
            'currency' => Currency::THB->value,
            'receiving_accounts' => $receivingAccounts,
            'transfer_note_reference' => $deposit->reference_number,
            'slip_verification_route' => route('api.v1.deposit.verify-slip'),
            'status' => $deposit->status->value,
        ], 'Bank-transfer deposit intent created. Transfer the amount and quote the reference in the transfer note.', 201);
    }

    public function generateCryptoDepositApi(Request $request): JsonResponse
    {
        return $this->notConfigured('Crypto deposit addresses are not configured on this compatibility route.');
    }

    /**
     * Slip verification, delegated — never re-implemented.
     *
     * The canonical SlipVerificationController owns the rules (provider
     * selection, quarantine, duplicate detection, amount and receiver
     * matching). This adapter rebuilds its FormRequest from the incoming
     * request, runs the SAME validation, and hands the very same request to
     * the SAME controller, so the compatibility route cannot drift from the
     * canonical one: there is one implementation of slip verification, not
     * two. The user resolver is carried over explicitly so the delegated
     * controller sees the authenticated principal that already passed the
     * middleware, and no identity is substituted anywhere.
     */
    public function verifySlipApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        $slipRequest = VerifySlipRequest::createFrom($request);
        $slipRequest->setContainer(app())->setRedirector(app('redirect'));
        $slipRequest->setUserResolver(fn () => $request->user());
        $slipRequest->validateResolved();

        return app(SlipVerificationController::class)->store($slipRequest);
    }

    public function pollDepositStatusApi(Request $request, string $refId): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalDepositController::class)->show($refId, $request);
    }

    /**
     * One rail as the frontend needs to see it.
     *
     * `min_amount`/`max_amount` are the configured bounds for the rail when
     * they exist, otherwise the global deposit bounds — never a number
     * invented here.
     *
     * @return array<string, mixed>
     */
    private function rail(PaymentGatewayManager $manager, PaymentMethod $method, string $initiateRoute): array
    {
        $enabled = $manager->isDepositCapable($method);
        $reason = null;

        if ($method === PaymentMethod::BankTransfer && $this->receivingAccounts() === []) {
            $enabled = false;
            $reason = 'No bank-transfer receiving account is configured for this deployment.';
        }

        return [
            'id' => $method->value,
            'name' => $method->name,
            'label' => $method->label(),
            'enabled' => $enabled,
            'state' => $enabled ? 'AVAILABLE' : 'NOT_CONFIGURED',
            'reason' => $reason ?? ($enabled ? null : 'This deposit rail is disabled or not configured on this deployment.'),
            'min_amount' => (string) config('deposit.'.$method->value.'.min_amount', (string) config('deposit.min', '50.00')),
            'max_amount' => (string) config('deposit.'.$method->value.'.max_amount', (string) config('deposit.max', '500000.00')),
            'currency' => Currency::THB->value,
            'initiate_route' => route($initiateRoute),
        ];
    }

    /**
     * @return list<array{bank: string, account_last_digits: string, name: string}>
     */
    private function receivingAccounts(): array
    {
        $accounts = config('slip_verification.receiving_accounts', []);
        if (! is_array($accounts)) {
            return [];
        }

        $normalised = [];
        foreach ($accounts as $account) {
            if (is_array($account) && isset($account['bank'], $account['account_last_digits'], $account['name'])) {
                $normalised[] = [
                    'bank' => (string) $account['bank'],
                    'account_last_digits' => (string) $account['account_last_digits'],
                    'name' => (string) $account['name'],
                ];
            }
        }

        return $normalised;
    }

    private function notConfigured(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'NOT_CONFIGURED',
            'message' => $message,
        ], 503);
    }

    private function authenticationRequired(): JsonResponse
    {
        return response()->json([
            'status' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Authentication is required for deposit information.',
        ], 401);
    }
}
