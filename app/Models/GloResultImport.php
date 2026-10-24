<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Provenance row for one GLO result import (official or fixture).
 *
 * Never stores raw upstream credentials. payload_summary is a bounded digest
 * (draw number, first prize digit string, tier counts) — not the full upstream
 * document. status NOT_CONFIGURED is a first-class honest outcome when the
 * official mode cannot reach the documented endpoint.
 *
 * @property int $id
 * @property string $import_reference
 * @property int|null $draw_id
 * @property string $provider
 * @property string $mode
 * @property string|null $endpoint
 * @property string|null $upstream_draw_id
 * @property string $status
 * @property string|null $result_fingerprint
 * @property array<string, mixed>|null $payload_summary
 * @property string|null $failure_reason
 * @property Carbon|null $imported_at
 */
class GloResultImport extends Model
{
    protected $table = 'glo_result_imports';

    protected $fillable = [
        'import_reference',
        'draw_id',
        'draw_date',
        'provider',
        'mode',
        'endpoint',
        'upstream_draw_id',
        'status',
        'result_fingerprint',
        'payload_summary',
        'raw_payload',
        'normalized_payload',
        'failure_reason',
        'imported_by',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function setRawPayloadAttribute(mixed $value): void
    {
        $summary = is_array($this->payload_summary) ? $this->payload_summary : [];
        $summary['raw_payload'] = $value;
        $this->payload_summary = $summary;
    }

    public function getRawPayloadAttribute(): mixed
    {
        $summary = is_array($this->payload_summary) ? $this->payload_summary : [];

        return $summary['raw_payload'] ?? null;
    }

    public function setNormalizedPayloadAttribute(mixed $value): void
    {
        $summary = is_array($this->payload_summary) ? $this->payload_summary : [];
        $summary['normalized_payload'] = $value;
        $this->payload_summary = $summary;
    }

    public function getNormalizedPayloadAttribute(): mixed
    {
        $summary = is_array($this->payload_summary) ? $this->payload_summary : [];

        return $summary['normalized_payload'] ?? null;
    }

    public function setDrawDateAttribute(mixed $value): void
    {
        $summary = is_array($this->payload_summary) ? $this->payload_summary : [];
        $summary['draw_date'] = $value;
        $this->payload_summary = $summary;
    }

    public function getDrawDateAttribute(): mixed
    {
        $summary = is_array($this->payload_summary) ? $this->payload_summary : [];

        return $summary['draw_date'] ?? null;
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
