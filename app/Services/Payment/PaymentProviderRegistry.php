<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentMethodData;
use App\DTOs\Payment\PaymentProviderData;
use App\Enums\AuditAction;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentProviderStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PaymentProviderException;
use App\Models\AuditLog;
use App\Models\PaymentMethodConfig;
use App\Models\PaymentProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The central provider registry.
 *
 * THE LAWS
 *   1. NO SECRETS — credentials live in env/config only; this registry
 *      stores codes, names, drivers and non-secret configuration, and
 *      its DTO refuses secret-smelling keys outright. Nothing here
 *      ever returns credential-shaped material.
 *   2. ONE REGISTRY — every lane that must resolve a provider/method
 *      asks here; nobody re-implements "is this provider enabled".
 *   3. LIFECYCLES ARE SEALED — status moves through the enum's own
 *      transition map, by explicit lane verbs, with audit.
 *   4. DETERMINISTIC REPLAY — re-registering an identical provider or
 *      identical method offer is a replay of the same fact; re-using
 *      an identity for DIFFERENT facts is a fork (refused by name).
 */
final class PaymentProviderRegistry
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /* ------------------------------------------------ conductora --- */

    /**
     * Register (or replay-register) a provider.
     *
     * @return array{provider: PaymentProvider, replayed: bool}
     *
     * @throws PaymentProviderException
     */
    public function register(PaymentProviderData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var PaymentProvider|null $existing */
            $existing = PaymentProvider::query()->lockForUpdate()->where('code', $data->code)->first();

            if ($existing instanceof PaymentProvider) {
                $factsMatch = (string) $existing->driver === $data->driver
                    && (string) $existing->name === $data->name;

                if (! $factsMatch) {
                    throw PaymentProviderException::duplicate($data->code);
                }

                return ['provider' => $existing, 'replayed' => true];
            }

            // A row naming a driver no class implements cannot carry traffic, and storing it
            // would present as configured to every reader of the table. Refused here, by name,
            // with the list of drivers that DO exist — the panel's provider screen is read-only,
            // so this registry is the only door a provider row can come through.
            if (! $this->gateways->hasDriver($data->driver)) {
                throw PaymentProviderException::unsupportedDriver($data->driver, ['code' => $data->code]);
            }

            $row = new PaymentProvider();
            $row->fill([
                'code' => $data->code,
                'name' => $data->name,
                'driver' => $data->driver,
                'supported_currencies' => $data->supportedCurrencies,
                'non_secrets' => $data->nonSecrets,
            ]);
            $row->status = $data->status;
            $row->save();

            $this->recordAudit('register_provider', $row->id, sprintf('Registered provider [%s] (%s)', $data->code, $data->status->value), RiskLevel::Medium);

            return ['provider' => $row, 'replayed' => false];
        });
    }

    /* -------------------------------------------------- methods --- */

    /**
     * Register (or replay-register) a method offer on a provider.
     *
     * @return array{method: PaymentMethodConfig, replayed: bool}
     *
     * @throws PaymentProviderException
     */
    public function registerMethod(PaymentMethodData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var PaymentProvider|null $provider */
            $provider = PaymentProvider::query()->lockForUpdate()->where('code', $data->providerCode)->first();

            if (! $provider instanceof PaymentProvider) {
                throw PaymentProviderException::notFound('provider:'.$data->providerCode);
            }

            /**
             * THE LOOKUP USES THE SAME KEY THE DATABASE USES.
             *
             * The unique index on this table is (provider_id, method_code, currency).
             * Checking only the derived `method_key` hash left a hole: a row written by
             * another lane, or by an older key shape, would not match the hash, the insert
             * would then hit the index, and the caller got a raw
             * UniqueConstraintViolationException — a 500 that interpolates the whole INSERT
             * into the log — where the domain's answer is "that offer already exists".
             * Matching on the natural triple first closes it: every duplicate now arrives as
             * PaymentProviderException::duplicate() with the facts compared below.
             *
             * @var PaymentMethodConfig|null $existing
             */
            $existing = PaymentMethodConfig::query()->lockForUpdate()
                ->where(function (Builder $query) use ($data, $provider): void {
                    $query->where('method_key', $data->methodKey())
                        ->orWhere(function (Builder $triple) use ($data, $provider): void {
                            $triple->where('provider_id', (int) $provider->id)
                                ->where('method_code', $data->methodCode)
                                ->where('currency', $data->currency);
                        });
                })
                ->first();

            if ($existing instanceof PaymentMethodConfig) {
                $factsMatch = (int) $existing->provider_id === (int) $provider->id
                    && bccomp((string) $existing->min_amount, $data->minAmount, 2) === 0
                    && bccomp((string) $existing->max_amount, $data->maxAmount, 2) === 0;

                if (! $factsMatch) {
                    throw PaymentProviderException::duplicate('method:'.$data->methodKey());
                }

                return ['method' => $existing, 'replayed' => true];
            }

            $row = new PaymentMethodConfig();
            $row->fill([
                'method_key' => $data->methodKey(),
                'provider_id' => (int) $provider->id,
                'method_code' => $data->methodCode,
                'currency' => $data->currency,
                'min_amount' => $data->minAmount,
                'max_amount' => $data->maxAmount,
                'fee_bps' => $data->feeBps,
                'metadata' => [],
            ]);
            $row->status = $data->status;
            $row->save();

            $this->recordAudit(
                'register_method',
                (int) $row->id,
                sprintf('Registered method [%s/%s/%s] on [%s]', $data->methodCode, $data->currency, $data->providerCode, $data->status->value),
                RiskLevel::Medium,
                PaymentMethodConfig::class,
            );

            return ['method' => $row, 'replayed' => false];
        });
    }

    /* ----------------------------------------------- resolution ---- */

    /**
     * Resolve the offerable method for (provider, method, currency): the
     * provider must be ACTIVE, the method ENABLED, and status words
     * otherwise answer by NAME (suspended, disabled, pending, retired).
     *
     * @throws PaymentProviderException
     */
    public function resolveOffer(string $providerCode, string $methodCode, string $currency): PaymentMethodConfig
    {
        $providerCode = strtolower(trim($providerCode));

        /** @var PaymentProvider|null $provider */
        $provider = PaymentProvider::query()->where('code', $providerCode)->first();

        if (! $provider instanceof PaymentProvider) {
            throw PaymentProviderException::notFound('provider:'.$providerCode);
        }

        if ($provider->status === PaymentProviderStatus::Suspended) {
            throw PaymentProviderException::suspended($providerCode);
        }

        if ($provider->status === PaymentProviderStatus::Disabled) {
            throw PaymentProviderException::disabled($providerCode);
        }

        if ($provider->status !== PaymentProviderStatus::Active) {
            throw PaymentProviderException::unavailable($providerCode, 'provider is '.$provider->status->value);
        }

        $methodKey = PaymentMethodData::fromInput(
            providerCode: $providerCode,
            methodCode: $methodCode,
            currency: $currency,
            minAmount: '0.01',
            maxAmount: '0.01',
        )->methodKey();

        /** @var PaymentMethodConfig|null $method */
        $method = PaymentMethodConfig::query()->with('provider')->where('method_key', $methodKey)->first();

        if (! $method instanceof PaymentMethodConfig) {
            throw PaymentProviderException::notFound('method:'.$methodKey);
        }

        if ($method->status === PaymentMethodStatus::Retired) {
            throw PaymentProviderException::unavailable($methodKey, 'method is retired — even the shelf is gone');
        }

        if ($method->status !== PaymentMethodStatus::Enabled) {
            throw PaymentProviderException::unavailable($methodKey, 'method is '.$method->status->value);
        }

        return $method;
    }

    /**
     * Whether an amount fits within the offer's hard bounds, exact
     * decimal comparison both ends.
     */
    public static function amountWithin(PaymentMethodConfig $method, string $amount): bool
    {
        $ask = bcadd(trim($amount), '0', 2);

        return bccomp($ask, (string) $method->min_amount, 2) >= 0
            && bccomp($ask, (string) $method->max_amount, 2) <= 0;
    }

    /* ------------------------------------------------- lifecycle --- */

    /**
     * @throws PaymentProviderException
     */
    public function transitionProvider(PaymentProvider $provider, PaymentProviderStatus $target, string $reason): PaymentProvider
    {
        return DB::transaction(function () use ($provider, $target, $reason): PaymentProvider {
            /** @var PaymentProvider|null $locked */
            $locked = PaymentProvider::query()->lockForUpdate()->find((int) $provider->getKey());

            if (! $locked instanceof PaymentProvider) {
                throw PaymentProviderException::notFound('provider:'.(string) $provider->code);
            }

            if ($locked->status === $target) {
                return $locked; // replay
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw PaymentProviderException::invalidTransition((string) $locked->code, $locked->status->value, $target->value);
            }

            $locked->status = $target;
            $locked->status_reason = \Illuminate\Support\Str::limit(trim($reason), 255, '');
            $locked->suspended_at = $target === PaymentProviderStatus::Suspended ? now() : $locked->suspended_at;
            $locked->disabled_at = $target === PaymentProviderStatus::Disabled ? now() : $locked->disabled_at;
            $locked->save();

            $this->recordAudit('transition_provider', (int) $locked->id, sprintf('Provider [%s] → %s (%s)', $locked->code, $target->value, $locked->status_reason), RiskLevel::High);

            return $locked;
        });
    }

    /**
     * @throws PaymentProviderException
     */
    public function transitionMethod(PaymentMethodConfig $method, PaymentMethodStatus $target, string $reason): PaymentMethodConfig
    {
        return DB::transaction(function () use ($method, $target, $reason): PaymentMethodConfig {
            /** @var PaymentMethodConfig|null $locked */
            $locked = PaymentMethodConfig::query()->lockForUpdate()->find((int) $method->getKey());

            if (! $locked instanceof PaymentMethodConfig) {
                throw PaymentProviderException::notFound('method:'.(string) $method->method_key);
            }

            if ($locked->status === $target) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw PaymentProviderException::invalidTransition((string) $locked->method_key, $locked->status->value, $target->value);
            }

            $locked->status = $target;
            $locked->retired_at = $target === PaymentMethodStatus::Retired ? now() : $locked->retired_at;
            $locked->save();

            $this->recordAudit(
                'transition_method',
                (int) $locked->id,
                sprintf('Method [%s] → %s (%s)', $locked->method_key, $target->value, \Illuminate\Support\Str::limit(trim($reason), 255, '')),
                RiskLevel::High,
                PaymentMethodConfig::class,
            );

            return $locked;
        });
    }

    /* --------------------------------------------------- internals -- */

    /**
     * @param  class-string  $subjectType  The model the id belongs to.
     *
     * Method verbs used to pass PaymentMethodConfig ids while hard-coding
     * PaymentProvider::class as the morph type, so an audit row for "method retired" pointed at
     * a PROVIDER whose id happened to be the method's. The trail then answered the wrong
     * question ("which provider?" instead of "which offer?"). The type is a parameter now
     * and every caller states it; provider verbs keep the previous value, so existing rows and
     * assertions are unaffected.
     */
    private function recordAudit(
        string $verb,
        int $subjectId,
        string $description,
        RiskLevel $riskLevel,
        string $subjectType = PaymentProvider::class,
    ): void {
        $log = new AuditLog();

        $log->fill([
            // ATTRIBUTION: when the transition arrives from an authenticated operator
            // (the admin panel), the audit line names them. Console and system lanes have
            // no authenticated user and keep the historical null rather than inventing an
            // identity. The provider lifecycle is a money-adjacent power, so "who took it
            // out of traffic?" must be answerable from the trail alone.
            'user_id' => auth()->id(),
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => $subjectType,
            'auditable_id' => $subjectId,
            'description' => sprintf('%s (%s)', $description, $verb),
            'metadata' => ['lane' => 'payment-provider-registry', 'verb' => $verb],
        ]);

        $log->save();
    }
}
