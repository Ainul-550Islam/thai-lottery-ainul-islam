<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\AuditAction;
use App\Enums\BankAccountStatus;
use App\Enums\KycStatus;
use App\Enums\KycVerificationStatus;
use App\Enums\RiskLevel;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\KycVerification;
use App\Models\MemberBankAccount;
use App\Models\User;
use App\Models\Withdrawal;
use App\Models\WithdrawalRequest;
use App\Services\Home\DashboardCacheInvalidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class MemberBankAccountService
{
    public function __construct(
        private readonly DashboardCacheInvalidator $dashboardCache,
    ) {}

    /**
     * @return Collection<int, MemberBankAccount>
     */
    public function listForMember(User $member): Collection
    {
        return MemberBankAccount::query()
            ->where('member_id', $member->getKey())
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @throws FinancialException
     */
    public function add(
        User $member,
        string $bankCode,
        string $accountName,
        string $accountNumber,
        bool $makeDefault = false,
    ): MemberBankAccount {
        $normalisedBank = strtoupper(trim($bankCode));
        $digitsOnly = preg_replace('/\D+/', '', $accountNumber) ?? '';
        $trimmedName = trim(preg_replace('/\s+/u', ' ', $accountName) ?? '');

        $minDigits = max(1, (int) config('rewards.bank_accounts.account_number_min_digits', 6));
        $maxDigits = max($minDigits, (int) config('rewards.bank_accounts.account_number_max_digits', 24));

        if ($normalisedBank === '' || strlen($digitsOnly) < $minDigits || strlen($digitsOnly) > $maxDigits || $trimmedName === '') {
            throw FinancialException::withCode(
                'invalid_bank_account_details',
                sprintf('Bank code, account holder name, and a %d-%d digit account number are required.', $minDigits, $maxDigits),
            );
        }

        $blindHash = $this->blindIndexHash($normalisedBank, $digitsOnly);
        $last4 = substr($digitsOnly, -4);
        $maxPerMember = max(1, (int) config('rewards.bank_accounts.max_per_member', 5));
        $nameMismatch = $this->detectKycNameMismatch($member, $trimmedName);

        try {
            return $this->persistNewAccount($member, $normalisedBank, $trimmedName, $digitsOnly, $blindHash, $last4, $maxPerMember, $nameMismatch, $makeDefault);
        } catch (QueryException $exception) {
            if (MemberBankAccount::query()->where('active_hash_key', $blindHash)->exists()) {
                throw FinancialException::withCode(
                    'bank_account_duplicate',
                    'This bank account cannot be registered. Contact support if you need help.',
                );
            }

            throw $exception;
        }
    }

    private function persistNewAccount(
        User $member,
        string $normalisedBank,
        string $trimmedName,
        string $digitsOnly,
        string $blindHash,
        string $last4,
        int $maxPerMember,
        bool $nameMismatch,
        bool $makeDefault,
    ): MemberBankAccount {
        return DB::transaction(function () use ($member, $normalisedBank, $trimmedName, $digitsOnly, $blindHash, $last4, $maxPerMember, $nameMismatch, $makeDefault): MemberBankAccount {
            User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            $activeCount = MemberBankAccount::query()
                ->where('member_id', $member->getKey())
                ->lockForUpdate()
                ->count();

            if ($activeCount >= $maxPerMember) {
                throw FinancialException::withCode(
                    'bank_account_limit_exceeded',
                    sprintf('You may register at most %d bank accounts.', $maxPerMember),
                    ['max_per_member' => $maxPerMember],
                );
            }

            $ownDuplicate = MemberBankAccount::query()
                ->where('member_id', $member->getKey())
                ->where('account_number_hash', $blindHash)
                ->exists();

            if ($ownDuplicate) {
                throw FinancialException::withCode(
                    'bank_account_duplicate',
                    'You have already registered this bank account.',
                );
            }

            // Cross-member duplicates get the SAME code but a neutral message so the
            // API never confirms that another member holds this account.
            $foreignDuplicate = MemberBankAccount::query()
                ->where('account_number_hash', $blindHash)
                ->exists();

            if ($foreignDuplicate) {
                throw FinancialException::withCode(
                    'bank_account_duplicate',
                    'This bank account cannot be registered. Contact support if you need help.',
                );
            }

            $shouldBeDefault = $makeDefault || $activeCount === 0;
            if ($shouldBeDefault) {
                MemberBankAccount::query()
                    ->where('member_id', $member->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            /** @var MemberBankAccount $account */
            $account = MemberBankAccount::query()->create([
                'member_id' => (int) $member->getKey(),
                'bank_code' => $normalisedBank,
                'account_name' => $trimmedName,
                'account_number' => $digitsOnly,
                'account_number_hash' => $blindHash,
                'active_hash_key' => $blindHash,
                'last4' => $last4,
                'status' => BankAccountStatus::PendingVerification,
                'is_default' => $shouldBeDefault,
                'name_mismatch' => $nameMismatch,
                'verification_notes' => $nameMismatch
                    ? 'Account holder name does not match verified KYC legal name.'
                    : null,
            ]);

            AuditLog::query()->create([
                'user_id' => (int) $member->getKey(),
                'action' => AuditAction::Create->value,
                'auditable_type' => MemberBankAccount::class,
                'auditable_id' => (int) $account->getKey(),
                'risk_level' => $nameMismatch ? RiskLevel::Medium->value : RiskLevel::Low->value,
                'description' => 'Member registered bank account pending verification.',
                'metadata' => [
                    'bank_code' => $normalisedBank,
                    'last4' => $last4,
                    'name_mismatch' => $nameMismatch,
                ],
            ]);

            $this->forgetDashboardCache((int) $member->getKey());

            return $account;
        }, 3);
    }

    /**
     * @throws FinancialException
     */
    public function setDefault(User $member, MemberBankAccount|int $account): MemberBankAccount
    {
        return DB::transaction(function () use ($member, $account): MemberBankAccount {
            $resolved = $this->lockOwnedAccount($member, $account);

            if (in_array($resolved->status, [BankAccountStatus::Rejected, BankAccountStatus::Disabled], true)) {
                throw FinancialException::withCode(
                    'bank_account_cannot_be_default',
                    'Rejected or disabled bank accounts cannot be set as default.',
                );
            }

            MemberBankAccount::query()
                ->where('member_id', $member->getKey())
                ->where('id', '!=', $resolved->getKey())
                ->update(['is_default' => false]);

            $resolved->is_default = true;
            $resolved->save();

            AuditLog::query()->create([
                'user_id' => (int) $member->getKey(),
                'action' => AuditAction::Update->value,
                'auditable_type' => MemberBankAccount::class,
                'auditable_id' => (int) $resolved->getKey(),
                'risk_level' => RiskLevel::Low->value,
                'description' => 'Member updated default bank account.',
                'metadata' => ['last4' => $resolved->last4],
            ]);

            return $resolved;
        });
    }

    /**
     * @throws FinancialException
     */
    public function remove(User $member, MemberBankAccount|int $account): void
    {
        DB::transaction(function () use ($member, $account): void {
            $resolved = $this->lockOwnedAccount($member, $account);

            if ($this->hasPendingWithdrawal($resolved)) {
                throw FinancialException::withCode(
                    'bank_account_in_use_by_pending_withdrawal',
                    'Cannot remove a bank account while a pending withdrawal references it.',
                );
            }

            $wasDefault = $resolved->is_default;
            $resolved->is_default = false;
            $resolved->active_hash_key = null;
            $resolved->save();
            $resolved->delete();

            if ($wasDefault) {
                /** @var MemberBankAccount|null $replacement */
                $replacement = MemberBankAccount::query()
                    ->where('member_id', $member->getKey())
                    ->whereNotIn('status', [BankAccountStatus::Rejected->value, BankAccountStatus::Disabled->value])
                    ->orderByDesc('verified_at')
                    ->orderByDesc('id')
                    ->first();

                if ($replacement instanceof MemberBankAccount) {
                    $replacement->is_default = true;
                    $replacement->save();
                }
            }

            AuditLog::query()->create([
                'user_id' => (int) $member->getKey(),
                'action' => AuditAction::Delete->value,
                'auditable_type' => MemberBankAccount::class,
                'auditable_id' => (int) $resolved->getKey(),
                'risk_level' => RiskLevel::Low->value,
                'description' => 'Member removed bank account.',
                'metadata' => [
                    'bank_code' => $resolved->bank_code,
                    'last4' => $resolved->last4,
                ],
            ]);

            $this->forgetDashboardCache((int) $member->getKey());
        });
    }

    public function verify(MemberBankAccount $account, User $admin, ?string $notes = null): MemberBankAccount
    {
        return DB::transaction(function () use ($account, $admin, $notes): MemberBankAccount {
            /** @var MemberBankAccount $locked */
            $locked = MemberBankAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === BankAccountStatus::Verified) {
                return $locked;
            }

            if ($locked->status !== BankAccountStatus::PendingVerification) {
                throw FinancialException::withCode(
                    'bank_account_not_verifiable',
                    'Only accounts pending verification can be verified.',
                    ['status' => $locked->status->value],
                );
            }

            $minOverrideLength = max(1, (int) config('rewards.bank_accounts.mismatch_override_min_note_length', 10));
            if ($locked->name_mismatch && mb_strlen(trim((string) $notes)) < $minOverrideLength) {
                throw FinancialException::withCode(
                    'bank_account_mismatch_override_required',
                    'This account name does not match the KYC legal name; a verification note explaining the override is required.',
                    ['min_note_length' => $minOverrideLength],
                );
            }

            $locked->status = BankAccountStatus::Verified;
            $locked->verified_by = (int) $admin->getKey();
            $locked->verified_at = Carbon::now();
            if ($notes !== null) {
                $locked->verification_notes = $notes;
            }
            $locked->save();

            AuditLog::query()->create([
                'user_id' => (int) $admin->getKey(),
                'action' => AuditAction::Update->value,
                'auditable_type' => MemberBankAccount::class,
                'auditable_id' => (int) $locked->getKey(),
                'risk_level' => RiskLevel::Low->value,
                'description' => 'Admin verified member bank account.',
                'metadata' => [
                    'member_id' => (int) $locked->member_id,
                    'bank_code' => $locked->bank_code,
                    'last4' => $locked->last4,
                    'notes' => $notes,
                ],
            ]);

            $this->forgetDashboardCache((int) $locked->member_id);

            return $locked;
        });
    }

    public function reject(MemberBankAccount $account, User $admin, string $reason): MemberBankAccount
    {
        return DB::transaction(function () use ($account, $admin, $reason): MemberBankAccount {
            /** @var MemberBankAccount $locked */
            $locked = MemberBankAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            if (trim($reason) === '') {
                throw FinancialException::withCode(
                    'bank_account_rejection_reason_required',
                    'A rejection reason is required.',
                );
            }

            if ($locked->status === BankAccountStatus::Rejected) {
                return $locked;
            }

            $locked->status = BankAccountStatus::Rejected;
            $locked->is_default = false;
            $locked->verified_by = (int) $admin->getKey();
            $locked->verified_at = null;
            $locked->verification_notes = trim($reason);
            $locked->save();

            AuditLog::query()->create([
                'user_id' => (int) $admin->getKey(),
                'action' => AuditAction::Update->value,
                'auditable_type' => MemberBankAccount::class,
                'auditable_id' => (int) $locked->getKey(),
                'risk_level' => RiskLevel::Medium->value,
                'description' => 'Admin rejected member bank account.',
                'metadata' => [
                    'member_id' => (int) $locked->member_id,
                    'bank_code' => $locked->bank_code,
                    'last4' => $locked->last4,
                    'reason' => trim($reason),
                ],
            ]);

            $this->forgetDashboardCache((int) $locked->member_id);

            return $locked;
        });
    }

    /**
     * @throws WithdrawalException
     */
    public function resolveForWithdrawal(User $member, ?int $bankAccountId = null, bool $lockForUpdate = false): MemberBankAccount
    {
        $query = MemberBankAccount::query()->where('member_id', $member->getKey());

        if ($lockForUpdate) {
            // Serialises against remove()/reject(), which lock this same row.
            $query->lockForUpdate();
        }

        if ($bankAccountId !== null) {
            $query->whereKey($bankAccountId);
        } else {
            $query->where('is_default', true);
        }

        /** @var MemberBankAccount|null $account */
        $account = $query->first();

        if (! $account instanceof MemberBankAccount) {
            throw WithdrawalException::withReason(
                'Selected bank account does not exist for this member.',
                'bank_account_not_found',
            );
        }

        if (! $account->isUsableForWithdrawal()) {
            throw WithdrawalException::withReason(
                'Only verified bank accounts may be used for withdrawals.',
                'bank_account_not_verified',
            );
        }

        return $account;
    }

    /**
     * Re-validate a linked account at payout/approval time (verification may have
     * been revoked since the withdrawal was requested). Call from the approval path.
     *
     * @throws WithdrawalException
     */
    public function assertUsableForPayout(int $memberId, int $bankAccountId): MemberBankAccount
    {
        /** @var MemberBankAccount|null $account */
        $account = MemberBankAccount::withTrashed()
            ->whereKey($bankAccountId)
            ->where('member_id', $memberId)
            ->lockForUpdate()
            ->first();

        if (! $account instanceof MemberBankAccount || $account->trashed() || ! $account->isUsableForWithdrawal()) {
            throw WithdrawalException::withReason(
                'The linked bank account is no longer verified for payouts.',
                'bank_account_not_verified',
            );
        }

        return $account;
    }

    public function blindIndexHash(string $bankCode, string $digitsOnly): string
    {
        $secret = (string) (config('rewards.bank_accounts.blind_index_key') ?: config('app.key'));
        if ($secret === '') {
            throw new \RuntimeException('Bank account blind-index key is not configured (set REWARDS_BANK_BLIND_INDEX_KEY or APP_KEY).');
        }

        return hash_hmac('sha256', sprintf('%s|%s', strtoupper(trim($bankCode)), $digitsOnly), $secret);
    }

    public function detectKycNameMismatch(User $member, string $submittedAccountName): bool
    {
        $legalName = $this->resolveVerifiedKycLegalName($member);
        if ($legalName === null || $legalName === '') {
            return false;
        }

        return $this->normaliseName($submittedAccountName) !== $this->normaliseName($legalName);
    }

    private function resolveVerifiedKycLegalName(User $member): ?string
    {
        /** @var KycVerification|null $verification */
        $verification = KycVerification::query()
            ->where('user_id', $member->getKey())
            ->where('status', KycVerificationStatus::Verified->value)
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->first();

        if ($verification instanceof KycVerification && is_array($verification->metadata)) {
            $candidate = $verification->metadata['legal_name']
                ?? $verification->metadata['full_name']
                ?? $verification->metadata['holder_name']
                ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        /** @var KycDocument|null $document */
        $document = KycDocument::query()
            ->where('user_id', $member->getKey())
            ->where('status', KycStatus::Verified->value)
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->first();

        if ($document instanceof KycDocument && is_array($document->metadata)) {
            $candidate = $document->metadata['legal_name']
                ?? $document->metadata['full_name']
                ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        // Fallback: a user whose KYC is approved carries the KYC-checked name on the
        // profile. Without approved KYC there is nothing authoritative to compare.
        $userKyc = $member->kyc_status;
        $isApproved = $userKyc === KycStatus::Verified
            || (is_string($userKyc) && strtolower($userKyc) === KycStatus::Verified->value);

        if ($isApproved && trim((string) $member->name) !== '') {
            return trim((string) $member->name);
        }

        return null;
    }

    /**
     * Unicode-aware, order-insensitive name key: NFC, case-folded, punctuation and
     * honorifics removed, tokens sorted, so "Somchai  Jaidee" equals "JAIDEE, Somchai".
     */
    private function normaliseName(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name;
        }

        $folded = mb_strtolower($name);
        $folded = preg_replace('/[^\p{L}\p{N}\p{M}\s]+/u', ' ', $folded) ?? '';
        $tokens = preg_split('/\s+/u', trim($folded), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $honorifics = array_map('mb_strtolower', (array) config('rewards.bank_accounts.name_honorifics', []));
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => ! in_array($t, $honorifics, true)));
        sort($tokens, SORT_STRING);

        return implode(' ', $tokens);
    }

    /**
     * @throws FinancialException
     */
    private function lockOwnedAccount(User $member, MemberBankAccount|int $account): MemberBankAccount
    {
        $id = $account instanceof MemberBankAccount ? (int) $account->getKey() : $account;

        /** @var MemberBankAccount|null $locked */
        $locked = MemberBankAccount::query()
            ->whereKey($id)
            ->where('member_id', $member->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof MemberBankAccount) {
            throw FinancialException::withCode(
                'bank_account_not_found',
                'Bank account not found for this member.',
            );
        }

        return $locked;
    }

    private function hasPendingWithdrawal(MemberBankAccount $account): bool
    {
        $activeStatuses = [
            WithdrawalStatus::Pending->value,
            WithdrawalStatus::UnderReview->value,
            WithdrawalStatus::KycRequired->value,
            WithdrawalStatus::Approved->value,
            WithdrawalStatus::Processing->value,
        ];

        $inWithdrawals = Withdrawal::query()
            ->where('member_bank_account_id', $account->getKey())
            ->whereIn('status', $activeStatuses)
            ->exists();

        if ($inWithdrawals) {
            return true;
        }

        return WithdrawalRequest::query()
            ->where('member_bank_account_id', $account->getKey())
            ->whereIn('status', ['PENDING', 'APPROVED', 'PROCESSING', 'pending', 'approved', 'processing'])
            ->exists();
    }

    private function forgetDashboardCache(int $memberId): void
    {
        $this->dashboardCache->forget($memberId);
    }
}
