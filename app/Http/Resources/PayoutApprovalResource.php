<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PayoutApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $approval = is_array($row->metadata['approval'] ?? null) ? $row->metadata['approval'] : [];
        $method = $row->metadata['claim']['payout_method'] ?? null;
        $status = $approval['status'] ?? null;

        return ['id' => (int) $row->id, 'payout_reference' => $row->reference_number, 'status' => $row->status->value,
            'amount' => (string) $row->amount, 'currency' => $row->currency->value,
            'method' => in_array($method, ['wallet', 'bank_transfer', 'cheque'], true) ? $method : null,
            'approval_status' => in_array($status, ['pending', 'approved', 'rejected', 'completed'], true) ? $status : null,
            'maker_id' => is_int($approval['maker_user_id'] ?? null) ? $approval['maker_user_id'] : null,
            'checker_id' => is_int($approval['checker_user_id'] ?? null) ? $approval['checker_user_id'] : null,
            'automatic_resend_allowed' => false];
    }
}
