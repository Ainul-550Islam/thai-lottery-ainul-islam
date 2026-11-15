<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BankAccountStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id
 * @property string $bank_code
 * @property string $account_name
 * @property string $account_number
 * @property string $account_number_hash
 * @property string $last4
 * @property BankAccountStatus $status
 * @property bool $is_default
 * @property bool $name_mismatch
 * @property string|null $verification_notes
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string $masked_number
 */
class MemberBankAccount extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'bank_code',
        'account_name',
        'account_number',
        'account_number_hash',
        'active_hash_key',
        'last4',
        'status',
        'is_default',
        'name_mismatch',
        'verification_notes',
        'verified_by',
        'verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'account_number',
        'account_number_hash',
        'active_hash_key',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'masked_number',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'status' => BankAccountStatus::class,
            'is_default' => 'boolean',
            'name_mismatch' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function maskedNumber(): Attribute
    {
        return Attribute::make(
            get: fn (): string => '••••'.$this->last4,
        );
    }

    public function isUsableForWithdrawal(): bool
    {
        return ! $this->trashed() && $this->status === BankAccountStatus::Verified;
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
