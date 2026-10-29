<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Enums\KycStatus;
use App\Enums\UserStatus;
use App\Exceptions\Wallet\ConversionAccountRestrictedException;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionKycRequiredException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Query\Builder;
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

    /** A policy preview remains advisory even when its caller has a transaction. */
    public function assertAdvisoryAllowed(int $userId): void
    {
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

    /**
     * One query builder, two modes.
     *
     * The rows are read as RAW ROWS and never hydrated into models. Both tables
     * involved carry enum casts on columns this service must inspect by their
     * stored bytes: a `users.status` of `malformed_status` or a `kyc_documents.status`
     * the enum has never heard of would make the cast throw a ValueError during
     * hydration — turning "this member is not verified" into a 500 for the very
     * members the gate exists to stop. Reading the values the database actually
     * holds is the only way to answer "is this exactly `active`?" truthfully.
     */
    private function read(Builder $query, bool $current): Builder
    {
        return $current ? $query->lockForUpdate() : $query;
    }

    private function check(int $userId, bool $current): void
    {
        // All supported owner-serialized admissions acquire User before policy facts
        // and wallets. A fresh model alone is insufficient under REPEATABLE READ.
        // A soft-deleted member is not a member: the row still exists, still says
        // `active`, and belongs to somebody who has exercised their right to leave.
        $user = $this->read(DB::table('users')->where('id', $userId)->whereNull('deleted_at'), $current)
            ->first(['id', 'status', 'phone_verified_at']);
        $status = $user?->status;
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
            && $user->phone_verified_at === null) {
            throw ConversionException::failure('kyc_not_verified', 'Verify your mobile number before converting.');
        }
    }

    private function assertNotExcluded(int $userId, bool $current): void
    {
        // The canonical gate survives a missing/cleared legacy projection. Match
        // canonical activation's order: User -> self exclusions -> legacy limit.
        $after = 0;
        do {
            $rows = $this->read(DB::table('self_exclusions')->where('user_id', $userId)
                ->where('status', 'active')->where('id', '>', $after)
                ->orderBy('id')->limit(self::PAGE_SIZE), $current)->get(['id', 'ends_at']);
            foreach ($rows as $row) {
                $after = (int) $row->id;
                $until = $this->timestamp($row->ends_at);
                if ($until === null || $until->isFuture()) {
                    $this->excluded();
                }
            }
        } while ($rows->count() === self::PAGE_SIZE);

        // Deleting a still-live safety row is not a way to cancel its protection, so
        // this read deliberately includes soft-deleted rows: the row's own
        // `deleted_at` is not evidence that the protection ended.
        $legacy = $this->read(DB::table('responsible_gaming_limits')->where('user_id', $userId), $current)
            ->first(['id', 'self_excluded_until', 'cool_off_until']);
        foreach (['self_excluded_until', 'cool_off_until'] as $field) {
            $raw = $legacy?->{$field};
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
            $rows = $this->read(DB::table('kyc_documents')->where('user_id', $userId)->whereNull('deleted_at')
                ->where('id', '>', $after)->orderBy('id')->limit(self::PAGE_SIZE), $current)
                ->get(['id', 'status', 'expires_at']);
            foreach ($rows as $row) {
                $after = (int) $row->id;
                $status = KycStatus::tryFrom((string) $row->status) ?? KycStatus::Unverified;
                if ($status === KycStatus::Verified) {
                    $raw = $row->expires_at;
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
