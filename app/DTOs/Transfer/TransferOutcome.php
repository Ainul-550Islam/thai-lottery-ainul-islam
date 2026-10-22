<?php

declare(strict_types=1);

namespace App\DTOs\Transfer;

use App\Models\BalanceTransfer;

/**
 * What a transfer attempt produced.
 *
 * WHY `replayed` IS CARRIED SEPARATELY
 * A replayed transfer and a fresh one return the same money result but must not
 * return the same HTTP status: a fresh transfer is 201 Created, a replay is 200
 * OK. Without this flag the controller would have to guess by comparing
 * timestamps, which is exactly the kind of inference that breaks under clock
 * skew. The service knows the answer for certain, so the service says it.
 */
final readonly class TransferOutcome
{
    private function __construct(
        public BalanceTransfer $transfer,
        public bool $replayed,
    ) {}

    /**
     * A transfer that this call executed and committed.
     */
    public static function executed(BalanceTransfer $transfer): self
    {
        return new self($transfer, false);
    }

    /**
     * A transfer an EARLIER call with the same idempotency key already executed.
     *
     * No money moved during this call. Returning the original row is the whole
     * point of the key: a client that retries after a network timeout gets the
     * truth about the first attempt instead of a second charge.
     */
    public static function replayed(BalanceTransfer $transfer): self
    {
        return new self($transfer, true);
    }

    public function isReplay(): bool
    {
        return $this->replayed;
    }

    public function httpStatus(): int
    {
        return $this->replayed ? 200 : 201;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transfer' => $this->transfer->toPlayerArray(),
            'replayed' => $this->replayed,
        ];
    }
}
