<?php

declare(strict_types=1);

namespace App\DTOs\Fx;

use Illuminate\Support\Carbon;

/**
 * One display rate and where it came from.
 *
 * IMMUTABLE, AND EXACT
 * `rate` is a decimal STRING so bcmath can divide by it without a float ever
 * existing. `approximate` is always true in this codebase: there is no exact
 * display rate, and a field that could be false would invite somebody to use
 * these numbers for money.
 */
final readonly class FxRate
{
    public function __construct(
        public string $base,
        public string $quote,
        public string $rate,
        public string $source,
        public Carbon $capturedAt,
        public bool $approximate = true,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'base' => $this->base,
            'quote' => $this->quote,
            'rate' => $this->rate,
            'source' => $this->source,
            'captured_at' => $this->capturedAt->toIso8601String(),
            'approximate' => $this->approximate,
        ];
    }
}
