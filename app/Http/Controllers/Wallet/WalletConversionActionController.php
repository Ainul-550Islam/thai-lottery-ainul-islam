<?php

declare(strict_types=1);

namespace App\Http\Controllers\Wallet;

use App\Contracts\Wallet\WalletConversionServiceContract;
use App\Enums\WalletConversionChannel;
use App\Exceptions\Wallet\ConversionException;
use App\Http\Requests\Wallet\ConversionExecuteRequest;
use App\Http\Requests\Wallet\ConversionQuoteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * The member's submit actions, from a browser.
 *
 * WHY THE WEB LANE REDIRECTS INSTEAD OF RETURNING A JSON BODY
 * A form submission that answers with JSON leaves the member staring at a raw
 * payload, and a browser "back" after a successful conversion re-submits it. Both
 * problems are solved the same way: every outcome, success or refusal, answers
 * with a redirect to the member's own page, carrying either the result or the
 * reason in the session. The refusal message shown to the member is the one the
 * ENGINE produced, not a rewritten copy — a second wording is a second thing to
 * keep in sync with the policy, and it is always the copy that goes stale.
 *
 * WHY THE CONVERSION ROW IS LOOKED UP AGAIN AFTER A REFUSAL
 * It is not. A refusal moved no money and wrote no conversion row; the audit
 * trail records it. Redirecting back with the error code and the human message
 * is the whole response, and re-reading the database to confirm nothing happened
 * would be a query whose only purpose is to reassure the developer.
 */
class WalletConversionActionController
{
    public function __construct(
        private readonly WalletConversionServiceContract $conversions,
    ) {
    }

    /**
     * POST /wallet/convert/quote
     *
     * Kept as a server round-trip rather than folded into the execute call: a
     * member who asks for a price and then changes their mind has moved no
     * money, and the form can show the fee, the net amount and the expiry before
     * anything is committed.
     */
    public function quote(ConversionQuoteRequest $request): RedirectResponse
    {
        try {
            $quote = $this->conversions->quote(
                $request->toEngineRequest((int) $request->user()->getKey(), WalletConversionChannel::Web),
            );
        } catch (ConversionException $exception) {
            return $this->backWithRefusal($exception);
        }

        return redirect()
            ->route('player.wallet.conversion', ['direction' => $quote->direction->value])
            ->withInput([
                'direction' => $quote->direction->value,
                'amount' => $quote->amountIn,
            ])
            ->with('status', __('wallet_conversion.flash_quote_ready', [
                'amount' => $quote->amountIn,
                'currency' => $quote->currency->value,
                'net' => $quote->amountOut,
                'fee' => $quote->feeAmount,
                'seconds' => $quote->secondsRemaining(),
            ]))
            ->with('conversion_quote_reference', $quote->reference);
    }

    /**
     * POST /wallet/convert
     */
    public function store(ConversionExecuteRequest $request): RedirectResponse
    {
        try {
            $result = $this->conversions->convert(
                $request->toEngineRequest((int) $request->user()->getKey(), WalletConversionChannel::Web),
            );
        } catch (ConversionException $exception) {
            return $this->backWithRefusal($exception);
        }

        $walletLabel = \Illuminate\Support\Facades\Lang::has($result->direction->toType()->labelKey())
            ? __($result->direction->toType()->labelKey())
            : $result->direction->toType()->label();

        $message = $result->replayed
            ? __('wallet_conversion.status_replayed', ['reference' => $result->reference])
            : __('wallet_conversion.status_converted', [
                'net' => $result->amountOut,
                'currency' => $result->currency->value,
                'wallet' => $walletLabel,
                'fee' => $result->feeAmount,
                'reference' => $result->reference,
            ]);

        return redirect()
            ->route('player.wallet.conversion.show', ['reference' => $result->reference])
            ->with('status', $message);
    }

    /**
     * A refusal returns the member to the form with the reason attached.
     *
     * The flash key is `error` so the layout's existing error banner renders it
     * without any new template work, and the engine's own message is passed
     * through untouched. The log line is written at info level: a refusal is
     * expected behaviour, not an incident, and logging it as an error would bury
     * real incidents under routine policy enforcement.
     */
    private function backWithRefusal(ConversionException $exception): RedirectResponse
    {
        Log::info('conversion.web_refusal', [
            'code' => $exception->errorCode(),
            'user_id' => request()->user()?->getKey(),
            'context' => $exception->context(),
        ]);

        $code = (string) $exception->errorCode();
        $reasonKey = 'wallet_conversion.rejection_'.$code;
        $message = \Illuminate\Support\Facades\Lang::has($reasonKey)
            ? __($reasonKey)
            : $exception->getMessage();

        return redirect()
            ->route('player.wallet.conversion')
            ->with('error', $message)
            ->with('conversion_error_code', $code);
    }
}
