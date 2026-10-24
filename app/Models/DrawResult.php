<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DrawResult extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'draw_id',
        'winning_number',
        'source_state',
        'created_at',
        'first_prize',
        'two_digit_back',
        'three_digit_front',
        'three_digit_back',
        'second_prize',
        'second_prizes',
        'third_prize',
        'third_prizes',
        'fourth_prizes',
        'fifth_prizes',
        'consolation_prizes',
        'all_numbers',
        'source_provider',
        'payload_fingerprint',
        'ingested_by_operator_id',
        'confirmed_by_operator_id',
        'verification_status',
        'ingested_at',
        'confirmed_at',
        'total_winners',
        'total_payout',
        'house_profit',
        'published_at',
        'metadata',
    ];

    public function setWinningNumberAttribute(mixed $value): void
    {
        $this->attributes['first_prize'] = (string) $value;
    }

    protected function casts(): array
    {
        return [
            'first_prize' => 'string',
            'source_state' => \App\Enums\GloSourceState::class,
            'three_digit_front' => 'array',
            'three_digit_back' => 'array',
            'second_prize' => 'array',
            'second_prizes' => 'array',
            'third_prize' => 'array',
            'third_prizes' => 'array',
            'fourth_prizes' => 'array',
            'fifth_prizes' => 'array',
            'consolation_prizes' => 'array',
            'all_numbers' => 'array',
            'total_winners' => 'integer',
            'total_payout' => 'decimal:2',
            'house_profit' => 'decimal:2',
            'ingested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function winningNumbers(): HasMany
    {
        return $this->hasMany(WinningNumber::class, 'draw_id', 'draw_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->verification_status === 'CONFIRMED' || $this->isPublished();
    }

    public function getVerificationStatusAttribute(): ?string
    {
        return $this->attributes['verification_status']
            ?? ($this->metadata['verification_status'] ?? null);
    }

    public function getPayloadFingerprintAttribute(): ?string
    {
        return $this->attributes['payload_fingerprint']
            ?? ($this->metadata['payload_fingerprint'] ?? null);
    }

    public function getIngestedByOperatorIdAttribute(): ?int
    {
        $val = $this->attributes['ingested_by_operator_id']
            ?? ($this->metadata['ingested_by_operator_id'] ?? null);

        return $val !== null ? (int) $val : null;
    }

    public function getConfirmedByOperatorIdAttribute(): ?int
    {
        $val = $this->attributes['confirmed_by_operator_id']
            ?? ($this->metadata['confirmed_by_operator_id'] ?? null);

        return $val !== null ? (int) $val : null;
    }

    public function getConfirmedAtAttribute(): ?\Illuminate\Support\Carbon
    {
        $val = $this->attributes['confirmed_at']
            ?? ($this->metadata['confirmed_at'] ?? null);

        return $val !== null ? \Illuminate\Support\Carbon::parse((string) $val) : null;
    }
}
