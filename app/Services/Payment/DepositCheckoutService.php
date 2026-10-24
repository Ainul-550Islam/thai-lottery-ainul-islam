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
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\DepositAdmission;
use App\Services\Finance\DepositIntegrityGuard;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Committed dispatch intent, at most one automatic provider invocation, encrypted response custody and SQL-only recovery. */
final class DepositCheckoutService
{
    public const TABLE = 'deposit_checkout_attempts';

    public const MARKER = 'checkout_owner_v1';

    public function assertTopLevel(): void
    {
        $baseDepth = (app()->environment('testing') && class_exists(\Tests\TestCase::class))
            ? \Tests\TestCase::$refreshDatabaseBaseDepth
            : 0;

        if (DB::transactionLevel() !== $baseDepth || ($baseDepth === 0 && DB::connection()->getPdo()->inTransaction())) {
            $this->refuse('checkout_requires_top_level', 'Checkout dispatch cannot run inside a caller transaction.');
        }
    }

    public function execute(Wallet $wallet, Money $amount, PaymentMethod $method, string $key, array $options, PaymentGatewayInterface $driver): array
    {
        $this->assertTopLevel();
        $optionsHash = $this->optionsHash($options);
        $deposit = DB::transaction(function () use ($wallet, $amount, $method, $key, $options, $optionsHash, $driver): Deposit {
            $row = app(DepositService::class)->request($wallet, $amount, $method, $key, $options);
            $payment = Payment::withTrashed()->where('payable_type', Deposit::class)->where('payable_id', $row->id)->lockForUpdate()->first();
            $attempt = DB::table(self::TABLE)->where('deposit_id', $row->id)->lockForUpdate()->first();
            if ($attempt) {
                $this->assertOriginal($row, $payment, $attempt, $driver->name());
                if (! hash_equals($attempt->request_hash, $this->requestHash($attempt->terms_hash, $attempt->provider, $attempt->account_scope, $optionsHash))) {
                    $this->refuse('checkout_request_conflict', 'The original checkout request options do not match.');
                }

                return $row;
            }
            if (! $row->wasRecentlyCreated || $payment) {
                // Old Part52 original snapshots remain read-only; no historical attempt enrollment.
                if (! $payment || data_get($payment->metadata, self::MARKER) !== null) {
                    $this->refuse('payment_initiation_replay_unproven', 'Existing checkout evidence needs reconciliation.');
                }
                $this->legacyReplay($row, $payment, $driver->name());

                return $row;
            }
            $uuid = (string) Str::uuid();
            $scope = app(DepositProviderAccountService::class)->assertActive($row);
            $terms = app(DepositIntegrityGuard::class)->termsFingerprint($row);
            $payment = Payment::query()->create([
                'reference_number' => 'PAY-'.strtoupper(bin2hex(random_bytes(8))), 'user_id' => $row->user_id,
                'payable_type' => Deposit::class, 'payable_id' => $row->id, 'method' => $method,
                'status' => PaymentStatus::Pending, 'currency' => $row->currency, 'amount' => (string) $row->amount,
                'fee' => (string) $row->fee, 'gateway' => $driver->name(),
                'metadata' => [self::MARKER => $uuid, 'deposit_reference' => $row->reference_number],
            ]);
            DB::table(self::TABLE)->insert([
                'uuid' => $uuid, 'deposit_id' => $row->id, 'payment_id' => $payment->id,
                'provider' => $driver->name(), 'account_scope' => $scope, 'terms_hash' => $terms,
                'configuration_hash' => $this->configurationHash($driver->name()),
                'request_hash' => $this->requestHash($terms, $driver->name(), $scope, $optionsHash),
                'dispatch_key' => 'checkout:'.bin2hex(random_bytes(32)), 'state' => 'prepared', 'attempt_count' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $row;
        });
        $claim = $this->claim($deposit);
        if ($claim === null) {
            return $this->recover($deposit);
        }
        // The durable claim is committed before this potentially irreversible external side effect.
        $this->assertTopLevel();
        try {
            $response = $driver->initiateDeposit($claim->deposit, array_merge($options, [
                'checkout_dispatch_key' => $claim->dispatch_key, 'checkout_attempt' => $claim->uuid, 'account_scope' => $claim->account_scope,
            ]));
        } catch (Throwable) {
            $this->markUnknown($claim);
            $this->refuse('checkout_outcome_unknown', 'Checkout acknowledgement is unknown; do not resend.');
        }
        try {
            $this->capture($claim, $response);
        } catch (Throwable) {
            $this->markUnknown($claim);
            $this->refuse('checkout_outcome_unknown', 'Checkout response requires controlled recovery; do not resend.');
        }

        return $this->recover($deposit);
    }

    /** Explicit staff read-only provider lookup; neither a customer retry nor a settlement grant. */
    public function reconcileStripe(Deposit $route, ?User $actor): array
    {
        $this->assertTopLevel();
        app(DepositCheckoutAccess::class)->authorize($actor);
        $snapshot = DB::transaction(function () use ($route, $actor): array {
            [$row, $wallet, $payment, $attempt] = $this->locked($route);
            if (! $attempt || $attempt->provider !== 'stripe') {
                $this->refuse('checkout_lookup_unavailable', 'Only newly owned original Stripe checkout attempts can be looked up.');
            }
            $this->assertOriginal($row, $payment, $attempt, 'stripe');
            app(DepositCheckoutAccess::class)->authorize($actor);
            if (in_array($attempt->state, ['response_received', 'projected'], true)) {
                return [$row, null];
            }
            if (! in_array($attempt->state, ['dispatching', 'unknown'], true)) {
                $this->refuse('checkout_lookup_unavailable', 'A checkout that was never dispatched cannot be reconciled as remotely accepted.');
            }
            Crypt::getKey();

            return [$row, $attempt];
        });
        [$original, $claim] = $snapshot;
        if ($claim === null) {
            return $this->recover($original);
        }
        // GET only, with no open SQL transaction and no capability to create a new checkout.
        $response = app(StripeCheckoutLookup::class)->find($original, $claim);
        $bytes = json_encode(['response' => $response->toArray(), 'raw' => $response->rawResponse], JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 1048576) {
            $this->refuse('checkout_lookup_inconclusive', 'The original lookup response exceeds its custody bound.');
        }
        $cipher = Crypt::encryptString($bytes);
        DB::transaction(function () use ($original, $claim, $actor, $response, $bytes, $cipher): void {
            [$row, $wallet, $payment, $a] = $this->locked($original);
            if (! $a) {
                $this->refuse('checkout_original_conflict', 'Original lookup dispatch ownership is missing.');
            }
            $this->assertOriginal($row, $payment, $a, 'stripe');
            $currentActor = app(DepositCheckoutAccess::class)->authorize($actor);
            foreach (['uuid', 'fence', 'request_hash', 'terms_hash', 'configuration_hash', 'dispatch_key', 'account_scope'] as $field) {
                if (! hash_equals((string) $claim->{$field}, (string) $a->{$field})) {
                    $this->refuse('checkout_original_conflict', 'The lookup no longer belongs to its original dispatch.');
                }
            }
            if (in_array($a->state, ['response_received', 'projected'], true)) {
                $stored = $this->custody($a);
                if ($stored['response']['provider_reference'] !== $response->providerReference) {
                    $this->refuse('checkout_response_unproven', 'Concurrent original checkout references disagree.');
                }

                return; // Original response custody wins; never overwrite it with a later lookup.
            }
            if (! in_array($a->state, ['dispatching', 'unknown'], true) || $a->response_cipher !== null || $a->response_hash !== null) {
                $this->refuse('checkout_original_conflict', 'The original lookup cannot replace contradictory custody.');
            }
            $uuid = (string) Str::uuid();
            DB::table('deposit_checkout_lookup_receipts')->insert([
                'uuid' => $uuid, 'attempt_id' => $a->id, 'actor_id' => $currentActor->id,
                'terms_hash' => $a->terms_hash, 'request_hash' => $a->request_hash, 'configuration_hash' => $a->configuration_hash,
                'fence' => $a->fence, 'dispatch_key_hash' => hash('sha256', $a->dispatch_key), 'account_scope' => $a->account_scope,
                'provider_reference' => $response->providerReference, 'response_cipher' => $cipher,
                'response_hash' => hash('sha256', $bytes), 'created_at' => now(),
            ]);
            DB::table(self::TABLE)->where('id', $a->id)->update(['lookup_receipt_uuid' => $uuid,
                'state' => 'response_received', 'response_cipher' => $cipher, 'response_hash' => hash('sha256', $bytes), 'updated_at' => now()]);
        }, attempts: 3); // SQL only; never repeats the provider GET or any POST.

        return $this->recover($original);
    }

    private function custody(object $a): array
    {
        try {
            $bytes = Crypt::decryptString($a->response_cipher);
            if (! hash_equals($a->response_hash, hash('sha256', $bytes))) {
                throw new \RuntimeException;
            }
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            $this->response($value['response'], $value['raw']);

            return $value;
        } catch (Throwable) {
            $this->refuse('checkout_response_unproven', 'Original encrypted checkout custody cannot be proven.');
        }
    }

    private function assertLookupReceipt(object $a): void
    {
        if ($a->lookup_receipt_uuid === null) {
            return;
        }
        $receipt = DB::table('deposit_checkout_lookup_receipts')->where('attempt_id', $a->id)->lockForUpdate()->first();
        if (! $receipt || $receipt->uuid !== $a->lookup_receipt_uuid || $a->provider !== 'stripe'
            || ! in_array($a->state, ['response_received', 'projected'], true)) {
            $this->refuse('checkout_response_unproven', 'The original provider lookup receipt is missing or contradictory.');
        }
        foreach (['terms_hash', 'request_hash', 'configuration_hash', 'fence', 'account_scope', 'response_hash', 'response_cipher'] as $field) {
            if (! hash_equals((string) $receipt->{$field}, (string) $a->{$field})) {
                $this->refuse('checkout_response_unproven', 'Original provider lookup receipt binding failed.');
            }
        }
        if (! hash_equals($receipt->dispatch_key_hash, hash('sha256', $a->dispatch_key))
            || $this->custody($a)['response']['provider_reference'] !== $receipt->provider_reference) {
            $this->refuse('checkout_response_unproven', 'The original provider lookup reference is not proven.');
        }
    }

    private function claim(Deposit $route): ?object
    {
        return DB::transaction(function () use ($route): ?object {
            [$row, $wallet, $payment, $attempt] = $this->locked($route);
            if (! $attempt) {
                return null;
            }
            $this->assertOriginal($row, $payment, $attempt);
            if (in_array($attempt->state, ['response_received', 'projected'], true)) {
                return null;
            }
            if ($attempt->state !== 'prepared') {
                $this->refuse('checkout_outcome_unknown', 'An already dispatched checkout must not be resent.');
            }
            if ($row->status !== DepositStatus::Pending || $row->financial_transaction_id !== null) {
                $this->refuse('checkout_request_conflict', 'The deposit is no longer eligible for first checkout dispatch.');
            }
            app(DepositService::class)->assertWalletAcceptsDeposit($wallet, $row->currency);
            app(DepositAdmission::class)->assertAllowed($wallet, (string) $row->amount, (int) $row->id);
            try {
                // Resolve/validate local encryption before committing permission to dispatch.
                Crypt::getKey();
            } catch (Throwable) {
                $this->refuse('checkout_custody_unavailable', 'Checkout response encryption is not available; no provider call was authorized.');
            }
            $fence = bin2hex(random_bytes(32));
            DB::table(self::TABLE)->where('id', $attempt->id)->update([
                'state' => 'dispatching', 'attempt_count' => 1, 'fence' => $fence, 'dispatched_at' => now(), 'updated_at' => now(),
            ]);

            $claim = DB::table(self::TABLE)->where('id', $attempt->id)->first();
            $claim->deposit = $row;

            return $claim;
        });
    }

    private function capture(object $claim, GatewayDepositResponse $response): void
    {
        $this->assertTopLevel();
        $reference = $response->providerReference;
        if (! $response->successful || ! is_string($reference) || $reference === '' || trim($reference) !== $reference
            || strlen($reference) > 255 || preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            $this->refuse('checkout_response_unproven', 'A provider response without an original acknowledgement is uncertain.');
        }
        $bytes = json_encode(['response' => $response->toArray(), 'raw' => $response->rawResponse], JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 1048576) {
            $this->refuse('checkout_response_unproven', 'The checkout response exceeds the custody bound.');
        }
        $cipher = Crypt::encryptString($bytes);
        DB::transaction(function () use ($claim, $cipher, $bytes): void {
            $row = DB::table(self::TABLE)->where('id', $claim->id)->lockForUpdate()->first();
            if (! $row || $row->state !== 'dispatching' || (int) $row->attempt_count !== 1 || ! hash_equals((string) $row->fence, $claim->fence)) {
                $this->refuse('checkout_response_unproven', 'Checkout response fencing does not match its original dispatch.');
            }
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'state' => 'response_received', 'response_cipher' => $cipher, 'response_hash' => hash('sha256', $bytes), 'updated_at' => now(),
            ]);
        });
    }

    private function markUnknown(object $claim): void
    {
        // If this write/connection fails, the already committed dispatching state itself still fences resends.
        try {
            $this->assertTopLevel();
            DB::table(self::TABLE)->where('id', $claim->id)->where('state', 'dispatching')->where('fence', $claim->fence)
                ->update(['state' => 'unknown', 'updated_at' => now()]);
        } catch (Throwable) {
            // Never erase response_received/projected custody or re-arm a dispatch on a diagnostic failure.
        }
    }

    /** Recovery projects ONLY an already-custodied original response; it NEVER invokes a provider. */
    public function recover(Deposit $route): array
    {
        $this->assertTopLevel();

        return DB::transaction(function () use ($route): array {
            [$row, $wallet, $payment, $attempt] = $this->locked($route);
            if (! $attempt) {
                if (! $payment || data_get($payment->metadata, self::MARKER) !== null) {
                    $this->refuse('payment_initiation_replay_unproven', 'No original checkout response is available.');
                }

                return $this->legacyReplay($row, $payment, (string) $payment->gateway);
            }
            $this->assertOriginal($row, $payment, $attempt);
            if (! in_array($attempt->state, ['response_received', 'projected'], true)) {
                $this->refuse('checkout_outcome_unknown', 'No original response is in custody; reconciliation is required.');
            }
            try {
                $bytes = Crypt::decryptString($attempt->response_cipher);
                if (! hash_equals($attempt->response_hash, hash('sha256', $bytes))) {
                    throw new \RuntimeException;
                }
                $stored = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
                $response = $this->response($stored['response'], $stored['raw']);
            } catch (Throwable) {
                $this->refuse('checkout_response_unproven', 'The original encrypted checkout response cannot be proven.');
            }
            if (($payment->gateway_reference !== null && $payment->gateway_reference !== $response->providerReference)
                || ($row->provider_reference !== null && $row->provider_reference !== $response->providerReference)) {
                $this->refuse('checkout_response_unproven', 'The original provider reference conflicts with current paper.');
            }
            if ($attempt->state === 'projected') {
                $pMeta = is_array($payment->metadata) ? $payment->metadata : [];
                if (array_key_exists('redirect_url', $pMeta)) {
                    if (is_string($pMeta['redirect_url']) && $pMeta['redirect_url'] !== '' && empty($pMeta['redirect_url_encrypted'])) {
                        $pMeta['redirect_url_encrypted'] = Crypt::encryptString($pMeta['redirect_url']);
                    }
                    unset($pMeta['redirect_url']);
                    $payment->metadata = $pMeta;
                    $payment->save();
                }
                if ($payment->gateway_reference !== $response->providerReference || $row->provider_reference !== $response->providerReference
                    || data_get($payment->metadata, 'original_initiation_response') !== $response->toArray()) {
                    $this->refuse('checkout_response_unproven', 'The original projected response no longer matches.');
                }
            } else {
                // Preserve any independently proved callback capture/credit; checkout response is NOT settlement authority.
                $payment->gateway_reference = $response->providerReference;
                if ($payment->status === PaymentStatus::Pending && empty($payment->gateway_response)) {
                    $sanitizedRaw = is_array($response->rawResponse) ? $response->rawResponse : [];
                    unset($sanitizedRaw['url'], $sanitizedRaw['redirect_url'], $sanitizedRaw['client_secret'], $sanitizedRaw['checkout_url'], $sanitizedRaw['bkashURL']);
                    $payment->gateway_response = $sanitizedRaw;
                }
                $paymentMeta = $payment->metadata ?? [];
                unset($paymentMeta['redirect_url']);
                $paymentMeta['original_initiation_response'] = $response->toArray();
                $paymentMeta['redirect_url_encrypted'] = $response->redirectUrl !== null
                    ? Crypt::encryptString($response->redirectUrl)
                    : null;
                $payment->metadata = $paymentMeta;
                $payment->save();
                $row->provider = $attempt->provider;
                $row->provider_reference = $response->providerReference;
                $rowMeta = $row->metadata ?? [];
                unset($rowMeta['redirect_url'], $rowMeta['client_secret']);
                $rowMeta['payment_id'] = (int) $payment->id;
                if ($response->redirectUrl !== null) {
                    $rowMeta['redirect_url_encrypted'] = Crypt::encryptString($response->redirectUrl);
                }
                if ($response->clientSecret !== null) {
                    $rowMeta['client_secret_encrypted'] = Crypt::encryptString($response->clientSecret);
                }
                $row->metadata = $rowMeta;
                $row->save();
                DB::table(self::TABLE)->where('id', $attempt->id)->update(['state' => 'projected', 'updated_at' => now()]);
            }

            return ['deposit' => $row, 'payment' => $payment, 'gateway_response' => $response];
        });
    }

    public function status(Deposit $deposit): ?array
    {
        $a = DB::table(self::TABLE)->useWritePdo()->where('deposit_id', $deposit->id)->first();
        if (! $a) {
            return null;
        }

        return ['state' => $a->state, 'attempt_count' => (int) $a->attempt_count,
            'response_in_custody' => $a->response_hash !== null, 'sql_recovery_available' => $a->state === 'response_received',
            'automatic_resend_allowed' => false];
    }

    /** Internal settlement boundary: caller owns a SQL root; original wallet/player/payment/attempt locks stay ordered. */
    public function lockedStripeSettlement(Deposit $route): array
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse('stripe_settlement_transaction_required', 'Original settlement validation requires its SQL root.');
        }
        [$row, $wallet, $payment, $attempt] = $this->locked($route);
        if (! $attempt || $attempt->provider !== 'stripe' || $attempt->state !== 'projected') {
            $this->refuse('stripe_settlement_original_unproven', 'Recover the owned original Stripe checkout before settlement.');
        }
        $this->assertOriginal($row, $payment, $attempt, 'stripe');
        $reference = $this->custody($attempt)['response']['provider_reference'];
        if ($row->provider_reference !== $reference || $payment->gateway_reference !== $reference) {
            $this->refuse('stripe_settlement_original_unproven', 'The projected original session reference differs.');
        }

        return [$row, $wallet, $payment, $attempt, $reference];
    }

    /** Read-only fingerprint, not a settlement capability or a caller-provided account selector. */
    public function stripeConfigurationFingerprint(): string
    {
        return $this->configurationHash('stripe');
    }

    private function locked(Deposit $route): array
    {
        $wallet = app(WalletLockService::class)->lock((int) $route->wallet_id);
        app(DepositAdmission::class)->lockPlayer($wallet);
        $row = Deposit::query()->lockForUpdate()->findOrFail($route->id);
        app(DepositIntegrityGuard::class)->assertTerms($row, $wallet);
        $payments = Payment::withTrashed()->where('payable_type', Deposit::class)->where('payable_id', $row->id)->lockForUpdate()->get();
        if ($payments->count() !== 1) {
            $this->refuse('payment_initiation_replay_unproven', 'No unique original checkout payment exists.');
        }

        return [$row, $wallet, $payments->sole(), DB::table(self::TABLE)->where('deposit_id', $row->id)->lockForUpdate()->first()];
    }

    private function assertOriginal(Deposit $row, ?Payment $payment, object $a, ?string $provider = null): void
    {
        $scope = app(DepositProviderAccountService::class)->assertActive($row);
        $validState = in_array($a->state, ['prepared', 'dispatching', 'unknown', 'response_received', 'projected'], true);
        $dispatched = $a->state !== 'prepared';
        if (! $payment || $payment->trashed() || ! $validState || (int) $a->payment_id !== (int) $payment->id
            || (int) $a->deposit_id !== (int) $row->id || $a->provider !== $payment->gateway || ($provider !== null && $a->provider !== $provider)
            || $a->account_scope !== $scope || ! hash_equals($a->configuration_hash, $this->configurationHash($a->provider)) || ! hash_equals($a->terms_hash, app(DepositIntegrityGuard::class)->termsFingerprint($row))
            || data_get($payment->metadata, self::MARKER) !== $a->uuid || $payment->user_id !== $row->user_id
            || $payment->currency !== $row->currency || $payment->method !== $row->method
            || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0 || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0
            || (int) $a->attempt_count !== ($dispatched ? 1 : 0) || ($dispatched && (! is_string($a->fence) || strlen($a->fence) !== 64 || ! $a->dispatched_at))
            || (! $dispatched && ($a->fence !== null || $a->response_cipher !== null || $a->response_hash !== null))
            || ! preg_match('/^checkout:[a-f0-9]{64}$/D', $a->dispatch_key)) {
            $this->refuse('checkout_original_conflict', 'Original checkout intent or dispatch facts do not match.');
        }
        $this->assertLookupReceipt($a);
    }

    private function legacyReplay(Deposit $row, Payment $payment, string $provider): array
    {
        $s = data_get($payment->metadata, 'original_initiation_response');
        $meta = is_array($payment->metadata) ? $payment->metadata : [];
        if (! is_array($s) && $payment->gateway_reference !== null && $payment->gateway_reference === $row->provider_reference) {
            $legacyRedirect = $meta['redirect_url'] ?? null;
            if (is_string($legacyRedirect) && $legacyRedirect !== '') {
                unset($meta['redirect_url']);
                $meta['redirect_url_encrypted'] = Crypt::encryptString($legacyRedirect);
            } elseif (isset($meta['redirect_url_encrypted']) && is_string($meta['redirect_url_encrypted'])) {
                $legacyRedirect = Crypt::decryptString($meta['redirect_url_encrypted']);
            }
            $s = [
                'successful' => true,
                'redirect_url' => is_string($legacyRedirect) ? $legacyRedirect : null,
                'provider_reference' => $payment->gateway_reference,
                'client_secret' => null,
                'metadata' => [],
            ];
            $meta['original_initiation_response'] = $s;
            $payment->metadata = $meta;
            $payment->save();
        } elseif (isset($meta['redirect_url']) && is_string($meta['redirect_url'])) {
            $meta['redirect_url_encrypted'] = Crypt::encryptString($meta['redirect_url']);
            unset($meta['redirect_url']);
            $payment->metadata = $meta;
            $payment->save();
        }
        $rowMeta = is_array($row->metadata) ? $row->metadata : [];
        if (isset($rowMeta['redirect_url']) || isset($rowMeta['client_secret'])) {
            if (is_string($rowMeta['redirect_url'] ?? null) && $rowMeta['redirect_url'] !== '') {
                $rowMeta['redirect_url_encrypted'] = Crypt::encryptString($rowMeta['redirect_url']);
            }
            if (is_string($rowMeta['client_secret'] ?? null) && $rowMeta['client_secret'] !== '') {
                $rowMeta['client_secret_encrypted'] = Crypt::encryptString($rowMeta['client_secret']);
            }
            unset($rowMeta['redirect_url'], $rowMeta['client_secret']);
            $row->metadata = $rowMeta;
            $row->save();
        }
        if ($payment->trashed() || $payment->user_id !== $row->user_id || $payment->currency !== $row->currency || $payment->method !== $row->method
            || $payment->gateway !== $provider || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0
            || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0 || ! is_array($s) || ($s['successful'] ?? null) !== true
            || ($s['provider_reference'] ?? null) !== $payment->gateway_reference || $payment->gateway_reference !== $row->provider_reference) {
            $this->refuse('payment_initiation_replay_unproven', 'Historical checkout response cannot be proven.');
        }

        return ['deposit' => $row, 'payment' => $payment, 'gateway_response' => $this->response($s, $payment->gateway_response ?? [])];
    }

    private function response(array $s, array $raw): GatewayDepositResponse
    {
        if (($s['successful'] ?? null) !== true || ! is_string($s['provider_reference'] ?? null) || $s['provider_reference'] === '') {
            $this->refuse('checkout_response_unproven', 'No original successful checkout acknowledgement exists.');
        }

        return new GatewayDepositResponse(successful: true, redirectUrl: $s['redirect_url'], providerReference: $s['provider_reference'],
            clientSecret: $s['client_secret'], rawResponse: $raw, metadata: $s['metadata']);
    }

    private function optionsHash(array $options): string
    {
        foreach (['checkout_dispatch_key', 'checkout_attempt', 'account_scope'] as $reserved) {
            if (array_key_exists($reserved, $options)) {
                $this->refuse('checkout_request_conflict', 'Provider dispatch identity is server-owned.');
            }
        }
        unset($options['ip'], $options['user_agent']);
        $normalise = function (mixed $value, int $depth = 0) use (&$normalise): mixed {
            if ($value instanceof Money) {
                return $value->toString();
            }
            if ($depth > 6 || is_float($value) || is_object($value) || is_resource($value)) {
                $this->refuse('checkout_request_conflict', 'Checkout request options are not canonical scalar facts.');
            }
            if (is_array($value)) {
                ksort($value);
                foreach ($value as $k => $v) {
                    $value[$k] = $normalise($v, $depth + 1);
                }
            }

            return $value;
        };
        $bytes = json_encode($normalise($options), JSON_THROW_ON_ERROR);
        if (strlen($bytes) > 16384) {
            $this->refuse('checkout_request_conflict', 'Checkout request options exceed the canonical bound.');
        }

        return hash('sha256', $bytes);
    }

    private function configurationHash(string $provider): string
    {
        return hash_hmac('sha256', json_encode([config('payment.gateways.'.$provider), config('payment.routes'), config('app.url')], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function requestHash(string $terms, string $provider, string $scope, string $optionsHash): string
    {
        return hash('sha256', json_encode(['checkout-v1', $terms, $provider, $scope, $optionsHash], JSON_THROW_ON_ERROR));
    }

    private function refuse(string $code, string $message): never
    {
        throw FinancialException::withCode($code, $message);
    }
}
