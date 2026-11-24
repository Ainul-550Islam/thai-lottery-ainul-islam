<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProviderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment gateway provider as the house registers it. Status may
 * only move through PaymentProviderStatus transitions and is therefore
 * NOT mass-assignable: lanes set it by property after checks.
 */
class PaymentProvider extends Model
{
    protected $fillable = [
        'code',
        'name',
        'driver',
        'supported_currencies',
        'non_secrets',
        'suspended_at',
        'disabled_at',
        'status_reason',
    ];

    protected $casts = [
        'status' => PaymentProviderStatus::class,
        'supported_currencies' => 'array',
        'non_secrets' => 'array',
        'suspended_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    /**
     * The method/currency offers this provider resolves.
     *
     * The foreign key has always existed (payment_method_configs.provider_id) but the
     * inverse relation did not, so the admin panel could not count a provider's offers
     * or show them without hand-writing a where-clause in the view. Read-only by
     * intent: offers are created through PaymentProviderRegistry::registerMethod(),
     * which owns their identity and audit.
     *
     * @return HasMany<PaymentMethodConfig, $this>
     */
    public function methods(): HasMany
    {
        return $this->hasMany(PaymentMethodConfig::class, 'provider_id');
    }
}
