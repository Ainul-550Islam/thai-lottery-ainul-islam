<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\Currency;
use App\Enums\WithdrawalReconciliationOutcome;
use App\Exceptions\WithdrawalReconciliationException;
use Carbon\CarbonImmutable;

final readonly class WithdrawalReconciliationFacts
{
    private function __construct(public array $values) {}

    public static function fromArray(array $input): self
    {
        $keys = ['internal_reference', 'provider', 'provider_reference', 'currency', 'gross_amount', 'net_amount', 'outcome', 'observed_at'];
        foreach ($keys as $key) {
            if (! is_string($input[$key] ?? null) || $input[$key] === '') {
                throw new WithdrawalReconciliationException('invalid_evidence_facts');
            }
        }
        if (Currency::tryFrom($input['currency']) === null || WithdrawalReconciliationOutcome::tryFrom($input['outcome']) === null
            || preg_match('/^[a-z][a-z0-9_]{1,39}$/D', $input['provider']) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/D', $input['provider_reference']) !== 1
            || strlen($input['internal_reference']) > 128) {
            throw new WithdrawalReconciliationException('invalid_evidence_facts');
        }
        foreach (['gross_amount', 'net_amount'] as $key) {
            if (preg_match('/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2}$/D', $input[$key]) !== 1 || bccomp($input[$key], '0.00', 2) <= 0) {
                throw new WithdrawalReconciliationException('invalid_evidence_money');
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $input['observed_at']) !== 1) {
            throw new WithdrawalReconciliationException('invalid_evidence_time');
        }
        try {
            $observed = CarbonImmutable::parse($input['observed_at']);
            if ($observed->format('Y-m-d\TH:i:sP') !== str_replace('Z', '+00:00', $input['observed_at']) || $observed->isAfter(CarbonImmutable::now()->addMinute())) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            throw new WithdrawalReconciliationException('invalid_evidence_time');
        }

        return new self(array_intersect_key($input, array_flip($keys)));
    }

    public function completed(): bool
    {
        return $this->values['outcome'] === WithdrawalReconciliationOutcome::Completed->value;
    }
}
