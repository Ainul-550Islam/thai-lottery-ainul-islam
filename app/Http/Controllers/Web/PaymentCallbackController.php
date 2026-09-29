<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Payment;
use App\Services\Payment\PaymentCallbackService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BROWSER RETURN pages for gateway redirects (FINAL AUDIT #2).
 *
 * Four routes - /payment/success, /payment/failure, /payment/cancel,
 * /payment/pending - are where a gateway drops the player's browser after a
 * checkout. They are PRESENTATION ONLY:
 *
 *  - The route the browser lands on is CONTEXT, never proof. A forged hit on
 *    /payment/success?reference=... cannot display or create a paid state.
 *  - The ONLY authority is the internal payments paper: the projection is
 *    read from the database via PaymentCallbackService::browserReturnProjection(),
 *    which is a read-only view over state written by verified webhook
 *    processing or manual operations.
 *  - References are resolved only through safe keys (our PAY- reference, our
 *    DP-/WD- document reference, or the gateway session reference), and only
 *    for the authenticated owner; another player's reference is reported as
 *    not found.
 *  - Webhooks remain the sole path that can change payment/ledger state.
 */
final class PaymentCallbackController
{
    public function __construct(
        private readonly PaymentCallbackService $callbacks,
    ) {
    }

    public function success(Request $request): View
    {
        return $this->render($request, 'success');
    }

    public function failure(Request $request): View
    {
        return $this->render($request, 'failure');
    }

    public function cancel(Request $request): View
    {
        return $this->render($request, 'cancel');
    }

    public function pending(Request $request): View
    {
        return $this->render($request, 'pending');
    }

    /**
     * Render the browser-return page for one of the four landing contexts.
     *
     * The context colours the copy ("you cancelled the checkout") but the
     * STATE shown is always the internal payment status - including the
     * honest case where a user lands on /payment/success while the verified
     * state is still pending: the page then says pending, not paid.
     */
    private function render(Request $request, string $context): View
    {
        $projection = $this->callbacks->browserReturnProjection(
            paymentReference: $request->query('payment'),
            documentReference: $request->query('reference'),
            gatewayReference: $request->query('session_id'),
            viewerId: $request->user()?->id,
        );

        /** @var Payment|null $payment */
        $payment = $projection['payment'] ?? null;

        return view('payment.callback', [
            'context' => $context,
            'found' => (bool) ($projection['found'] ?? false),
            'state' => (string) ($projection['state'] ?? ''),
            'paid' => (bool) ($projection['paid'] ?? false),
            'reference' => (string) ($projection['document_reference'] ?? ''),
            'paymentReference' => $payment !== null ? (string) $payment->reference_number : '',
            'amount' => $payment !== null
                ? \App\Services\Finance\Money::of((string) $payment->amount, $payment->currency)->format()
                : '',
        ]);
    }
}
