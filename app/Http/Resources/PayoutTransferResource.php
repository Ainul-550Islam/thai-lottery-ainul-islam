<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Explicit allowlist: never forward claim, approval, beneficiary, reference or caller context. */
final class PayoutTransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $transfer = is_array($row->metadata['transfer'] ?? null) ? $row->metadata['transfer'] : [];

        return ['id' => (int) $row->id, 'payout_reference' => $row->reference_number,
            'status' => $row->status->value, 'amount' => (string) $row->amount, 'currency' => $row->currency->value,
            'method' => $transfer['method'] ?? null, 'lane_status' => $transfer['lane_status'] ?? null,
            'opened_at' => $transfer['opened_at'] ?? null, 'completed_at' => $transfer['completed_at'] ?? null,
            'financial_transaction_id' => $row->financial_transaction_id, 'automatic_resend_allowed' => false,
            'private_archived_evidence_required' => true, 'evidence_id' => $transfer['evidence_id'] ?? null,
            'provider_authenticity_automatically_verified' => false, 'provider_invoked_by_this_lane' => false];
    }
}
