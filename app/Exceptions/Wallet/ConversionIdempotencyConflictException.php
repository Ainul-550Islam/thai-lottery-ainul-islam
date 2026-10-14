<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletConversionRejectionReason;
use Throwable;

/**
 * An idempotency key was reused for a request that is not the same request.
 *
 * WHY THIS IS A HARD FAILURE AND NOT A SILENT REPLAY
 * The whole point of an idempotency key is that it identifies one intention.
 * If a client sends key K for "convert 100 Cash to Win" and then sends key K
 * again for "convert 500 Cash to Win", the platform faces two possibilities and
 * must not guess between them:
 *
 *   - The client meant the second request and reused K by mistake. Honouring it
 *     would move 500 when the client's own ledger of keys says 100.
 *   - The client is replaying deliberately (a bug, a malicious script, a broken
 *     retry loop with a mutated body). Honouring it would let a keyed endpoint
 *     be used as an unkeyed one.
 *
 * Both are served by refusing loudly. The exception reports WHICH fields
 * differed — the amount, the direction, the currency, or the quoting — so the
 * client developer sees the mismatch immediately instead of debugging a silent
 * no-op. Field NAMES are reported; field VALUES are reported only for the
 * fields that are already visible to that client in its own request.
 */
final class ConversionIdempotencyConflictException extends ConversionException
{
    public const ERROR_CODE = 'idempotency_conflict';

    /**
     * @param  array<string, scalar|null>  $expected  the fields the stored key was first used with
     * @param  array<string, scalar|null>  $actual  the fields the current request carries
     * @param  array<int, string>  $mismatchedFields
     */
    public function __construct(
        private readonly string $idempotencyKey,
        private readonly ?string $existingReference,
        private readonly array $expected,
        private readonly array $actual,
        private readonly array $mismatchedFields,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'This idempotency key was first used for a different conversion request. Mismatched field(s): %s.',
                $mismatchedFields === [] ? 'unknown' : implode(', ', $mismatchedFields),
            ),
            self::ERROR_CODE,
            [
                'idempotency_key' => $idempotencyKey,
                'existing_reference' => $existingReference,
                'mismatched_fields' => $mismatchedFields,
            ],
            $previous,
        );
    }

    /**
     * @param  array<string, scalar|null>  $expected
     * @param  array<string, scalar|null>  $actual
     * @param  array<int, string>  $mismatchedFields
     */
    public static function forKey(
        string $idempotencyKey,
        ?string $existingReference,
        array $expected,
        array $actual,
        array $mismatchedFields,
        ?Throwable $previous = null,
    ): self {
        return new self($idempotencyKey, $existingReference, $expected, $actual, $mismatchedFields, $previous);
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /**
     * The public reference of the conversion the key originally produced, when
     * the caller is entitled to see it (same owner, same channel).
     */
    public function existingReference(): ?string
    {
        return $this->existingReference;
    }

    /**
     * The comparison the service performed, reintroduced as detail for the
     * client so it can correct its retry logic without reading server logs.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return array_merge(parent::toApiArray(), [
            'mismatched_fields' => $this->mismatchedFields,
            'existing_reference' => $this->existingReference,
        ]);
    }

    public function rejectionReason(): ?WalletConversionRejectionReason
    {
        return WalletConversionRejectionReason::IdempotencyConflict;
    }
}
