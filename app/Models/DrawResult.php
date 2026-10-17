<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrawResult extends Model
{
    use HasUuids;

    protected $table = 'draw_results';

    protected $fillable = [
        'draw_id',
        'first_prize',
        'three_digit_front',
        'three_digit_back',
        'two_digit_back',
        'adjacent_first_prizes',
        'second_prizes',
        'third_prizes',
        'fourth_prizes',
        'fifth_prizes',
        'source_provider',
        'payload_fingerprint',
        'ingested_by_operator_id',
        'confirmed_by_operator_id',
        'verification_status',
        'ingested_at',
        'confirmed_at',
    ];

    protected $casts = [
        'three_digit_front' => 'array',
        'three_digit_back' => 'array',
        'adjacent_first_prizes' => 'array',
        'second_prizes' => 'array',
        'third_prizes' => 'array',
        'fourth_prizes' => 'array',
        'fifth_prizes' => 'array',
        'ingested_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class, 'draw_id', 'id');
    }

    public function isConfirmed(): bool
    {
        return $this->verification_status === 'CONFIRMED' && $this->confirmed_by_operator_id !== null;
    }
}
