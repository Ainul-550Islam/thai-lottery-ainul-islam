<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Api\V1\WithdrawalController as CanonicalWithdrawalController;
use App\Http\Requests\Withdrawal\CreateWithdrawalRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Compatibility adapter for retired provider-specific withdrawal pages.
 *
 * The former implementation advertised balances, bank identities, limits, fees,
 * references and payout statuses it could not honour. It now carries no such
 * promises of its own: every value it returns is read from configuration or
 * derived from the same gateway manager the payout path uses, and every
 * mutation is delegated to the canonical controller.
 *
 * WHAT A WITHDRAWAL PAGE MUST NEVER DO
 * -----------------------------------
 * Offer a method this deployment cannot pay. `enabled` here is therefore
 * computed by PaymentGatewayManager (the driver must be switched on, must
 * report itself configured, and must declare withdrawal support), not by a
 * hardcoded list. A rail that fails any of those is reported
 * NOT_CONFIGURED, so a client cannot render a Withdraw button that only ever
 * returns an error.
 *
 * THE AUTOMATED THAI BANK RAIL IS NOT A METHOD
 * --------------------------------------------
 * It is reported under `routing`, never in `methods`. A player asks for
 * `bank_transfer` or `promptpay`; WithdrawalDisbursementService decides from
 * server-side configuration and the stored destination whether the automated
 * rail pays it. App\Enums\PaymentMethod has no `thai_bank` case precisely so
 * that decision can never be made by a caller.
 */
final class WithdrawalMethodsPageController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        return $request->user() !== null
            ? redirect()->route('player.withdraw')
            : redirect()->route('login');
    }

    /**
     * The TRUE withdrawal rails for this deployment.
     */
    public function getWithdrawalMethodsApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        $manager = app(PaymentGatewayManager::class);
        $allowed = (array) config('payment.withdrawal.allowed_methods', []);

        $methods = [];
        foreach ($allowed as $method) {
            if (! is_string($method)) {
                continue;
            }

            $enum = PaymentMethod::tryFrom($method);
            if (! $enum instanceof PaymentMethod) {
                continue;
            }

            $methods[] = $this->rail($manager, $enum);
        }

        return ApiResponse::success([
            'methods' => $methods,
            'default_currency' => Currency::THB->value,
            'currencies' => ['THB', 'USD', 'BDT'],
            'min_amount' => (string) config('payment.withdrawal.min', '100.00'),
            'max_amount' => (string) config('payment.withdrawal.max', '500000.00'),
            'requires_kyc_above' => (string) config('finance.withdrawal.kyc_gate_threshold', '5000.00'),
            'routing' => [
                'automated_thai_bank' => (bool) config('thai_payout.enabled', false),
                'note' => 'The system, not the player, decides whether an automated Thai bank transfer pays a bank_transfer request.',
            ],
        ], 'Withdrawal methods retrieved.');
    }

    /**
     * Withdrawal requests are delegated, never re-implemented.
     *
     * The canonical endpoint owns the validation grammar (amount shape,
     * destination rules, prohibited identity fields) and the money path. This
     * adapter rebuilds its FormRequest from the incoming request, runs the
     * SAME validation, and hands the very same request to the SAME controller,
     * so the compatibility route cannot drift from the canonical one. The user
     * resolver is carried over explicitly: the delegated controller sees the
     * authenticated principal that already passed the middleware, and no
     * identity is substituted anywhere.
     */
    public function requestWithdrawalApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        $withdrawalRequest = CreateWithdrawalRequest::createFrom($request);
        $withdrawalRequest->setContainer(app())->setRedirector(app('redirect'));
        $withdrawalRequest->setUserResolver(fn () => $request->user());
        $withdrawalRequest->validateResolved();

        return app(CanonicalWithdrawalController::class)->store($withdrawalRequest);
    }

    public function getRecentWithdrawalsApi(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalWithdrawalController::class)->index($request);
    }

    public function getWithdrawalStatusApi(Request $request, string $refId): JsonResponse
    {
        if ($request->user() === null) {
            return $this->authenticationRequired();
        }

        return app(CanonicalWithdrawalController::class)->show($refId, $request);
    }

    /**
     * One rail as the frontend needs to see it.
     *
     * @return array<string, mixed>
     */
    private function rail(PaymentGatewayManager $manager, PaymentMethod $method): array
    {
        $enabled = $manager->isWithdrawalCapable($method);

        return [
            'id' => $method->value,
            'name' => $method->name,
            'label' => $method->label(),
            'enabled' => $enabled,
            'state' => $enabled ? 'AVAILABLE' : 'NOT_CONFIGURED',
            'reason' => $enabled ? null : 'This withdrawal rail is disabled or not configured on this deployment.',
            'min_amount' => (string) config('payment.withdrawal.min', '100.00'),
            'max_amount' => (string) config('payment.withdrawal.max', '500000.00'),
            'currency' => Currency::THB->value,
            'request_route' => route('api.v1.withdrawal.request'),
        ];
    }

    private function authenticationRequired(): JsonResponse
    {
        return response()->json([
            'status' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Authentication is required for withdrawal information.',
        ], 401);
    }
}
