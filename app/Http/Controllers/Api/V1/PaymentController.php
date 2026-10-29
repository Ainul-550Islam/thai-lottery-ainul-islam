<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Payment\PaymentIntentData;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentProviderStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\PaymentIntentException;
use App\Exceptions\PaymentProviderException;
use App\Http\Responses\ApiResponse;
use App\Models\PaymentMethodConfig;
use App\Models\PaymentProvider;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Authenticated payment-intent endpoints.
 *
 * IDENTITY IS DERIVED, NEVER CLAIMED: wallet handle, user handle and
 * nothing money-critical is taken from the request body at face value.
 * The user is the authenticated principal; the wallet is their OWN
 * wallet fetched by the server (the body may select a wallet only
 * among the caller's own wallets, and the service re-proves the claim
 * under a row lock anyway).
 */
final class PaymentController
{
    public function __construct(
        private readonly PaymentIntentService $intents,
        private readonly PaymentGatewayManager $gateways,
    ) {
    }

    /**
     * Providers that may serve traffic — the enabled menu, with NO
     * configuration material that smells of secrets (the registry
     * schema has no home for secrets at all).
     */
    public function providers(): JsonResponse
    {
        $providers = $this->offerableMethods()->groupBy('provider_id')->map(function (Collection $offers): array {
            /** @var PaymentProvider $provider */
            $provider = $offers->first()->getRelation('provider');

            return [
                'code' => $provider->code,
                'name' => $provider->name,
                'driver' => $provider->driver,
                // Do not advertise a registry currency for which there is no
                // currently offerable, manager-verified method.
                'supported_currencies' => $offers->pluck('currency')
                    ->map(static fn ($currency): string => strtoupper(trim((string) $currency)))
                    ->unique()->values()->all(),
            ];
        })->values()->all();

        return ApiResponse::success(data: $providers, message: 'Active payment providers.');
    }

    /**
     * Enabled methods across active providers (the customer menu).
     */
    public function methods(): JsonResponse
    {
        $methods = $this->offerableMethods()->map(fn (PaymentMethodConfig $method): array => [
            'provider' => $method->getRelation('provider')->code,
            'method' => $method->method_code,
            'currency' => $method->currency,
            'min_amount' => (string) $method->min_amount,
            'max_amount' => (string) $method->max_amount,
            'fee_bps' => (int) $method->fee_bps,
        ])->values()->all();

        return ApiResponse::success(data: $methods, message: 'Enabled payment methods.');
    }

    /** @return Collection<int, PaymentMethodConfig> */
    private function offerableMethods(): Collection
    {
        return PaymentMethodConfig::query()->with('provider')
            ->where('status', PaymentMethodStatus::Enabled->value)
            ->whereHas('provider', fn ($q) => $q->where('status', PaymentProviderStatus::Active->value))
            ->orderBy('id')
            ->get(['id', 'method_code', 'currency', 'min_amount', 'max_amount', 'fee_bps', 'provider_id', 'status'])
            ->filter(fn (PaymentMethodConfig $offer): bool => $this->gateways->isRegistryOfferCapable($offer))
            ->values();
    }

    /**
     * Create a payment intent. The user + wallet are derived from the
     * authenticated principal; the body's wallet handle may only select
     * among the caller's own wallets.
     */
    public function createIntent(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'direction' => ['required', 'string', 'in:deposit,withdrawal'],
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'method' => ['required', 'string', 'regex:/^[a-z0-9_]{2,32}$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:96', 'regex:/^[A-Za-z0-9:\-_.]+$/'],
        ])->validate();

        try {
            /** @var \App\Models\User $user */
            $user = $request->user();

            // Server-derive the caller's wallet (never accept a numeric
            // id from the body as authority).
            // Currency is a selector only within this principal's primary
            // wallets. It never authorizes a caller-supplied wallet identity.
            $walletQuery = $user->wallet();
            if (isset($validated['currency'])) {
                $walletQuery->where('currency', strtoupper(trim($validated['currency'])));
            }
            /** @var \App\Models\Wallet|null $wallet */
            $wallet = $walletQuery->first();

            if ($wallet === null) {
                return ApiResponse::error(code: 'wallet_not_found', message: 'No wallet is held by this account.', status: 404);
            }

            $walletCurrency = $wallet->currency instanceof \BackedEnum
                ? (string) $wallet->currency->value
                : (string) $wallet->currency;

            $data = PaymentIntentData::fromInput(
                userId: (int) $user->id,
                walletId: (int) $wallet->id,
                direction: $validated['direction'],
                amount: $validated['amount'],
                currency: $validated['currency'] ?? $walletCurrency,
                methodCode: $validated['method'],
                idempotencyKey: $validated['idempotency_key'],
            );

            ['intent' => $intent, 'replayed' => $replayed] = $this->intents->create($data);

            return ApiResponse::success(
                data: [
                    'intent_key' => $intent->intent_key,
                    'direction' => $intent->direction->value,
                    'amount' => (string) $intent->amount,
                    'currency' => $intent->currency,
                    'method' => $intent->method_code,
                    'status' => $intent->status->value,
                    'expires_at' => $intent->expires_at?->toIso8601String(),
                    'replayed' => $replayed,
                ],
                message: $replayed ? 'Payment intent replayed (same facts already exist).' : 'Payment intent created.',
                status: $replayed ? 200 : 201,
            );
        } catch (FinancialException $e) {
            if ($e->errorCode() !== 'real_money_disabled') {
                throw $e;
            }

            return ApiResponse::error('real_money_disabled', 'Real-money operations are currently unavailable.', 503);
        } catch (PaymentIntentException|PaymentProviderException $e) {
            return ApiResponse::error(
                code: strtolower($e->errorCode()),
                message: $e->getMessage(),
                status: str_contains($e->errorCode(), 'NOT_FOUND') ? 404 : 422,
            );
        }
    }

    /**
     * One of the caller's OWN intents by key.
     */
    public function showIntent(Request $request, string $intent): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = $request->user();

            $intent = $this->intents->retrieve($intent, (int) $user->id);

            return ApiResponse::success(
                data: [
                    'intent_key' => $intent->intent_key,
                    'direction' => $intent->direction instanceof PaymentDirection ? $intent->direction->value : (string) $intent->direction,
                    'amount' => (string) $intent->amount,
                    'currency' => $intent->currency,
                    'method' => $intent->method_code,
                    'status' => $intent->status->value,
                    'expires_at' => $intent->expires_at?->toIso8601String(),
                ],
                message: 'Payment intent.',
            );
        } catch (PaymentIntentException $e) {
            return ApiResponse::error(code: strtolower($e->errorCode()), message: $e->getMessage(), status: str_contains($e->errorCode(), 'NOT_FOUND') ? 404 : 422);
        }
    }

    /**
     * Cancel one of the caller's OWN intents (provider-evidence-free only).
     */
    public function cancelIntent(Request $request, string $intent): JsonResponse
    {
        try {
            /** @var \App\Models\User $user */
            $user = $request->user();

            $intent = $this->intents->cancel($intent, (int) $user->id);

            return ApiResponse::success(
                data: ['intent_key' => $intent->intent_key, 'status' => $intent->status->value],
                message: 'Payment intent cancelled.',
            );
        } catch (PaymentIntentException $e) {
            return ApiResponse::error(code: strtolower($e->errorCode()), message: $e->getMessage(), status: str_contains($e->errorCode(), 'NOT_FOUND') ? 404 : 422);
        }
    }
}
