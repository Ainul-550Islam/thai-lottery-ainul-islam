<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentWebhookStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use UnexpectedValueException;

/**
 * A persisted provider webhook envelope (evidence). Status moves
 * strictly through PaymentWebhookStatus transitions — property-set
 * after checks, never mass-assignable.
 *
 * Verified payload bytes and provider signatures are encrypted at rest. The
 * legacy JSON payload column contains only an encryption marker and fingerprint;
 * the ciphertext columns are the sole source used to reconstruct accepted facts.
 * Rejected, unverified deliveries retain only their one-way fingerprint.
 */
class PaymentWebhook extends Model
{
    protected $fillable = [
        'webhook_key',
        'provider',
        'event_id',
        'event_type',
        'signature',
        'payload',
        'payload_fingerprint',
        'status_reason',
        'received_at',
        'verified_at',
        'claimed_at',
        'applied_at',
        'rejected_at',
        'last_error_at',
        'sightings',
        'processing_attempts',
        'metadata',
    ];

    protected $casts = [
        'status' => PaymentWebhookStatus::class,
        'received_at' => 'datetime',
        'verified_at' => 'datetime',
        'claimed_at' => 'datetime',
        'applied_at' => 'datetime',
        'rejected_at' => 'datetime',
        'last_error_at' => 'datetime',
        'processing_attempts' => 'integer',
        'metadata' => 'array',
    ];

    /** @throws JsonException|UnexpectedValueException */
    public function getPayloadAttribute(mixed $value): array
    {
        $ciphertext = $this->attributes['payload_ciphertext'] ?? null;

        if (is_string($ciphertext) && $ciphertext !== '') {
            $decoded = json_decode(Crypt::decryptString($ciphertext), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($decoded)) {
                throw new UnexpectedValueException('The decrypted payment webhook payload is not an array.');
            }

            return $decoded;
        }

        // Compatibility for historical rows and rejected evidence. New
        // accepted writes never store their raw payload in this column.
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @throws JsonException */
    public function setPayloadAttribute(mixed $value): void
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException('A payment webhook payload must be an array.');
        }

        $this->attributes['payload_ciphertext'] = Crypt::encryptString(
            json_encode($value, JSON_THROW_ON_ERROR),
        );
        $this->attributes['payload'] = json_encode([
            'encrypted' => true,
            'payload_fingerprint' => $this->attributes['payload_fingerprint'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }

    public function getSignatureAttribute(mixed $value): ?string
    {
        $ciphertext = $this->attributes['signature_ciphertext'] ?? null;

        if (is_string($ciphertext) && $ciphertext !== '') {
            return Crypt::decryptString($ciphertext);
        }

        // Rejected evidence and historical rows may hold a digest or an older
        // plaintext value; only the accepted-envelope path decrypts a signature.
        return is_string($value) ? $value : null;
    }

    public function setSignatureAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['signature_ciphertext'] = null;
            $this->attributes['signature'] = null;

            return;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('A payment webhook signature must be a string or null.');
        }

        $this->attributes['signature_ciphertext'] = Crypt::encryptString($value);
        $this->attributes['signature'] = null;
    }
}
