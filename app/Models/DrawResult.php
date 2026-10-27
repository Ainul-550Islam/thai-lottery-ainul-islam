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
        // Thai market aliases (3Up / 2Up / 2Down). They are not extra columns:
        // each is a mutator over the canonical GLO tier column below
        // (three_digit_front / three_digit_back / two_digit_back), so a writer can
        // name the tier the way the Thai result sheets do without a second copy of
        // the digits that could drift.
        'front_three',
        'last_three',
        'last_two',
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

    /**
     * Thai alias setters: 3Up → three_digit_front, 2Up/2Down → two_digit_back and
     * the latter-half three → three_digit_back. Accepts a single number or a list.
     */
    public function setFrontThreeAttribute(mixed $value): void
    {
        $this->setAttribute('three_digit_front', $this->tierList($value));
    }

    public function setLastThreeAttribute(mixed $value): void
    {
        $this->setAttribute('three_digit_back', $this->tierList($value));
    }

    /**
     * 2Up/2Down are one two-digit number in this schema (`two_digit_back` is a
     * char(2) column, not a list), so the alias keeps the first declared digit and
     * the reader hands it back as a single-entry list.
     */
    public function setLastTwoAttribute(mixed $value): void
    {
        $digits = $this->tierList($value);
        $this->setAttribute('two_digit_back', $digits[0] ?? null);
    }

    /**
     * Thai alias readers, so a consumer can ask for 3Up/2Up/2Down directly and get
     * the canonical tier's digits — leading zeros intact, never int-cast.
     */
    public function getFrontThreeAttribute(): array
    {
        return $this->tierList($this->getAttribute('three_digit_front'));
    }

    public function getLastThreeAttribute(): array
    {
        return $this->tierList($this->getAttribute('three_digit_back'));
    }

    public function getLastTwoAttribute(): array
    {
        $digit = $this->attributes['two_digit_back'] ?? null;

        return $digit === null || $digit === '' ? [] : [(string) $digit];
    }

    /**
     * @return list<string>
     */
    private function tierList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                return array_values(array_map(static fn (mixed $digit): string => (string) $digit, $decoded));
            }

            return [(string) $value];
        }

        if (! is_array($value)) {
            return [(string) $value];
        }

        return array_values(array_map(static fn (mixed $digit): string => (string) $digit, $value));
    }

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
