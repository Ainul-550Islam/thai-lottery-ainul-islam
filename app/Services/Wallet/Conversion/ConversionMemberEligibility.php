<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Enums\KycStatus;
use App\Enums\SelfExclusionStatus;
use App\Enums\UserStatus;
use App\Exceptions\Wallet\ConversionAccountRestrictedException;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionKycRequiredException;
use App\Models\KycDocument;
use App\Models\ResponsibleGamingLimit;
use App\Models\SelfExclusion;
use App\Models\User;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Current member facts, not a cached principal or an old consistent-read snapshot. */
final class ConversionMemberEligibility
{
    private const PAGE_SIZE = 250;

    public function __construct(private readonly Repository $config) {}

    /** Outside a transaction this is advisory only; it does not reserve authority. */
    public function assertAllowed(int $userId): void
    {
        if (DB::transactionLevel() > 0) {
            $this->assertAllowedForExecution($userId);

            return;
        }
        $this->check($userId, false);
    }

    /** Retain the owner and fact locks until the caller commits the money/quote. */
    public function assertAllowedForExecution(int $userId): void
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw ConversionException::failure('transaction_required', 'Member eligibility requires the caller money transaction.');
        }
        $this->check($userId, true);
    }

    private function read(Builder $query, bool $current): Builder
    {
        return $current ? $query->lockForUpdate() : $query->useWritePdo();
    }

    private function check(int $userId, bool $current): void
    {
        // All supported owner-serialized admissions acquire User before policy facts
        // and wallets. A fresh model alone is insufficient under REPEATABLE READ.
        $user = $this->read(User::query()->whereKey($userId), $current)->first();
        $status = $user?->getRawOriginal('status');
        if ($user === null || $status !== UserStatus::Active->value) {
            throw ConversionAccountRestrictedException::forAccountStatus($userId, is_string($status) ? $status : 'missing');
        }
        if ($this->config->get('conversions.gates.block_if_self_excluded', true)) {
            $this->assertNotExcluded($userId, $current);
        }
        if ($this->config->get('conversions.gates.require_kyc', true)) {
            $this->assertVerified($userId, $current);
        }
        if ($this->config->get('conversions.gates.require_verified_mobile', false)
            && $user->getRawOriginal('phone_verified_at') === null) {
            throw ConversionException::failure('kyc_not_verified', 'Verify your mobile number before converting.');
        }
    }

    private function assertNotExcluded(int $userId, bool $current): void
    {
        // The canonical gate survives a missing/cleared legacy projection. Match
        // canonical activation's order: User -> self exclusions -> legacy limit.
        $after = 0;
        do {
            $rows = $this->read(SelfExclusion::query()->where('user_id', $userId)
                ->where('status', SelfExclusionStatus::Active->value)->where('id', '>', $after)
                ->orderBy('id')->limit(self::PAGE_SIZE), $current)->get(['id', 'ends_at']);
            foreach ($rows as $row) {
                $after = (int) $row->id;
                $until = $this->timestamp($row->getRawOriginal('ends_at'));
                if ($until === null || $until->isFuture()) {
                    $this->excluded();
                }
            }
        } while ($rows->count() === self::PAGE_SIZE);

        // Deleting a still-live safety row is not a way to cancel its protection.
        $legacy = $this->read(ResponsibleGamingLimit::withTrashed()->where('user_id', $userId), $current)
            ->first(['id', 'self_excluded_until', 'cool_off_until']);
        foreach (['self_excluded_until', 'cool_off_until'] as $field) {
            $raw = $legacy?->getRawOriginal($field);
            if ($raw !== null) {
                $until = $this->timestamp($raw);
                if ($until === null || $until->isFuture()) {
                    $this->excluded();
                }
            }
        }
    }

    private function excluded(): never
    {
        throw ConversionException::failure('self_exclusion_active', 'A self-exclusion or cooling-off period is active.');
    }

    private function assertVerified(int $userId, bool $current): void
    {
        $after = 0;
        $status = KycStatus::Unverified;
        do {
            // Do not call User::kycStatus(): it executes a separate plain relation
            // query. Keep any-live-verified-document semantics, ignore soft-deleted
            // evidence, and never let an expired/malformed expiry grant identity.
            $rows = $this->read(KycDocument::query()->where('user_id', $userId)
                ->where('id', '>', $after)->orderBy('id')->limit(self::PAGE_SIZE), $current)
                ->get(['id', 'status', 'expires_at']);
            foreach ($rows as $row) {
                $after = (int) $row->id;
                $status = KycStatus::tryFrom((string) $row->getRawOriginal('status')) ?? KycStatus::Unverified;
                if ($status === KycStatus::Verified) {
                    $raw = $row->getRawOriginal('expires_at');
                    if ($raw === null) {
                        return; // Existing verified documents without an expiry remain valid.
                    }
                    $until = $this->timestamp($raw);
                    if ($until !== null && $until->isFuture()) {
                        return;
                    }
                    $status = KycStatus::Expired;
                }
            }
        } while ($rows->count() === self::PAGE_SIZE);
        throw ConversionKycRequiredException::forUser($userId, $status->value);
    }

    /** Strict stored-second timestamps; malformed evidence never grants authority. */
    private function timestamp(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $raw) !== 1) {
            return null;
        }
        try {
            $value = Carbon::createFromFormat('!Y-m-d H:i:s', $raw, (string) $this->config->get('app.timezone', 'UTC'));

            return $value !== false && $value->format('Y-m-d H:i:s') === $raw ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
