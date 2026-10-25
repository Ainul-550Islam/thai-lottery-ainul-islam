<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use Database\Factories\DataSubjectRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One data-subject request (PDPA access, portability, erasure, ...).
 *
 * THE REFERENCE IS DERIVED FROM THE ROW, AFTER THE ROW EXISTS.
 * A reference generated before the insert is a race: two requests can be given
 * the same random string, or a "next number" read from the table can be handed
 * out twice under concurrent load. This model therefore inserts with a random
 * placeholder, and the created listener rewrites the reference to
 * PRV-<year>-<padded id>. The id is unique by definition, so the reference is
 * unique without a lock, and the unique index on the column is a backstop
 * rather than the mechanism.
 *
 * TWO COPIES OF THE ADDRESS, FOR TWO DIFFERENT JOBS.
 * email_hash (sha256) is the lookup key: counting open requests per address
 * and matching an account never needs to decrypt anything. email_encrypted is
 * the address itself, encrypted with the app key, because a human answering a
 * rectification request eventually has to know who it is about. There is no
 * plaintext address column.
 *
 * SCOPES ARE THE REPORTING LANGUAGE. open(), overdue() and dueSoon() are what
 * the Filament filters and the SLA sweep ask; keeping them here means the rule
 * "overdue means past due_at and not terminal" exists once.
 *
 * @property int $id
 * @property string $uuid
 * @property string $reference
 * @property int|null $user_id
 * @property string $email_hash
 * @property string $email_encrypted
 * @property string $locale
 * @property PrivacyRequestType $type
 * @property PrivacyRequestStatus $status
 * @property string|null $details_encrypted
 * @property Carbon|null $verified_at
 * @property Carbon|null $received_at
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 * @property string|null $rejected_reason_code
 * @property int|null $assigned_to
 * @property string|null $legal_hold_reason
 * @property string|null $ip_hash
 */
class DataSubjectRequest extends Model
{
    /** @use HasFactory<DataSubjectRequestFactory> */
    use HasFactory;

    /**
     * The prefix every quotable reference carries.
     */
    public const REFERENCE_PREFIX = 'PRV';

    protected $fillable = [
        'uuid',
        'reference',
        'user_id',
        'email_hash',
        'email_encrypted',
        'locale',
        'type',
        'status',
        'details_encrypted',
        'verified_at',
        'received_at',
        'due_at',
        'completed_at',
        'rejected_reason_code',
        'assigned_to',
        'legal_hold_reason',
        'ip_hash',
    ];

    /**
     * Nothing that decrypts or fingerprints an address travels in a JSON
     * projection. The model is not serialised to the public surface at all, but
     * a later caller might, and these are the fields that must not follow.
     *
     * @var list<string>
     */
    protected $hidden = [
        'email_hash',
        'email_encrypted',
        'details_encrypted',
        'ip_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PrivacyRequestType::class,
            'status' => PrivacyRequestStatus::class,
            // Eloquent's encrypted cast wraps the value with the application key
            // in an authenticated envelope: a copy of the database without the
            // key yields ciphertext, not a person's words.
            'email_encrypted' => 'encrypted',
            'details_encrypted' => 'encrypted',
            'verified_at' => 'datetime',
            'received_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if ((string) $request->uuid === '') {
                $request->uuid = (string) Str::uuid();
            }

            if ((string) $request->reference === '') {
                // Placeholder only: replaced the moment the row has an id.
                $request->reference = 'TMP-'.strtoupper(Str::random(16));
            }
        });

        static::created(function (self $request): void {
            $reference = self::buildReference((int) $request->getKey(), $request->created_at);

            if ($request->reference === $reference) {
                return;
            }

            // saveQuietly: this is the reference assignment, not a business
            // event, so it must not fire observers into a loop.
            $request->reference = $reference;
            $request->saveQuietly();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * PRV-<year>-<six-digit id>.
     *
     * Deterministic: the same row always produces the same reference, so a
     * retried write cannot mint a second one.
     */
    public static function buildReference(int $id, ?Carbon $at = null): string
    {
        $year = ($at ?? Carbon::now())->format('Y');

        return sprintf('%s-%s-%06d', self::REFERENCE_PREFIX, $year, max(0, $id));
    }

    /**
     * The hash used to find a request by address without decrypting a column.
     */
    public static function hashEmail(string $email): string
    {
        return hash('sha256', self::normaliseEmail($email));
    }

    /**
     * A one-way fingerprint of the submitting connection.
     *
     * Not an address and not reversible; it exists so that abuse ceilings can
     * count submissions without keeping anyone's IP.
     */
    public static function hashIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        if ($ip === '') {
            return null;
        }

        return hash('sha256', 'privacy-request|ip|'.$ip);
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return HasMany<DataSubjectRequestEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DataSubjectRequestEvent::class, 'request_id')->orderBy('id');
    }

    /**
     * @return HasMany<PrivacyVerificationToken, $this>
     */
    public function verificationTokens(): HasMany
    {
        return $this->hasMany(PrivacyVerificationToken::class, 'request_id');
    }

    /**
     * @return HasMany<PersonalDataExport, $this>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(PersonalDataExport::class, 'request_id')->latest('id');
    }

    /**
     * The most recent archive built for this request, if any.
     *
     * @return HasOne<PersonalDataExport, $this>
     */
    public function latestExport(): HasOne
    {
        return $this->hasOne(PersonalDataExport::class, 'request_id')->latestOfMany();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Requests that have not reached a terminal state.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            PrivacyRequestStatus::Completed->value,
            PrivacyRequestStatus::Rejected->value,
            PrivacyRequestStatus::Expired->value,
            PrivacyRequestStatus::Cancelled->value,
        ]);
    }

    /**
     * Past due and still open. The single definition of "overdue".
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', Carbon::now());
    }

    /**
     * Due within the configured reminder window and not yet due.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueSoon(Builder $query): Builder
    {
        $days = max(0, (int) config('privacy_requests.reminder_days_before_due', 5));

        return $query->open()
            ->whereNotNull('due_at')
            ->where('due_at', '>=', Carbon::now())
            ->where('due_at', '<=', Carbon::now()->addDays($days));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfStatus(Builder $query, PrivacyRequestStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function isOverdue(): bool
    {
        if ($this->status instanceof PrivacyRequestStatus && $this->status->isTerminal()) {
            return false;
        }

        return $this->due_at instanceof Carbon && $this->due_at->isPast();
    }

    public function isOpen(): bool
    {
        return $this->status instanceof PrivacyRequestStatus && $this->status->isOpen();
    }

    /**
     * Days left before the due date; negative when overdue.
     *
     * Null when the clock has not started (an unverified request has no due
     * date, because nothing has been received yet).
     */
    public function daysRemaining(): ?int
    {
        if (! $this->due_at instanceof Carbon) {
            return null;
        }

        return (int) Carbon::now()->startOfDay()->diffInDays($this->due_at->copy()->startOfDay(), false);
    }

    public function isVerified(): bool
    {
        return $this->verified_at instanceof Carbon;
    }

    /**
     * Does this request still occupy a slot against max_open_per_email?
     */
    public function occupiesOpenSlot(): bool
    {
        return $this->isOpen();
    }
}
