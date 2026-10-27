<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\GatewayIntegrationStatus;

final readonly class PaymentGatewayReadiness implements \JsonSerializable
{
    /** @param list<string> $issues @param list<string> $currencies @param list<string> $countries */
    public function __construct(
        public string $gateway,
        public bool $enabled,
        public bool $ready,
        public GatewayIntegrationStatus $status,
        public array $issues,
        public array $currencies,
        public array $countries,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'gateway' => $this->gateway,
            'enabled' => $this->enabled,
            'ready' => $this->ready,
            'status' => $this->status->value,
            'issues' => $this->issues,
            'currencies' => $this->currencies,
            'countries' => $this->countries,
        ];
    }
}
