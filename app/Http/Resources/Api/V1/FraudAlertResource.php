<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\FraudAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property FraudAlert $resource
 */
class FraudAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'idempotency_event_key' => $this->resource->idempotency_event_key,
            'event_type' => $this->resource->event_type,
            'user_id' => $this->resource->user_id,
            'amount' => $this->resource->amount,
            'currency' => $this->resource->currency,
            'risk_score' => $this->resource->risk_score,
            'decision' => $this->resource->decision,
            'reason_codes' => $this->resource->reason_codes,
            'triggered_rules' => $this->resource->triggered_rules,
            'status' => $this->resource->status,
            'assigned_to_user_id' => $this->resource->assigned_to_user_id,
            'resolved_by_user_id' => $this->resource->resolved_by_user_id,
            'resolution_notes' => $this->resource->resolution_notes,
            'resolved_at' => $this->resource->resolved_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
