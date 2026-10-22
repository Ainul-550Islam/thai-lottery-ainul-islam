<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WithdrawalReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $facts = $this->facts;

        return ['id' => $this->id, 'withdrawal_id' => $this->withdrawal_id, 'payment_id' => $this->payment_id,
            'maker_id' => $this->maker_id, 'checker_id' => $this->checker_id, 'status' => $this->status,
            'facts' => array_intersect_key($facts, array_flip(['internal_reference', 'provider', 'provider_reference', 'currency', 'gross_amount', 'net_amount', 'outcome', 'observed_at'])),
            'evidence_sha256' => $this->evidence_sha256, 'evidence_bytes' => $this->evidence_bytes,
            'created_at' => $this->created_at?->toIso8601String(), 'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note, 'automatic_resend_allowed' => false];
    }
}
