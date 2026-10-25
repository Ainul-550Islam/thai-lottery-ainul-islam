<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Enums\WithdrawalStatus;
use App\Models\ComplianceCase;
use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "May an erasure or restriction be executed for this person — and if not, why?"
 *
 * =========================================================================
 * IT ANSWERS. IT DOES NOT DELETE.
 * =========================================================================
 * There is no delete, update, purge or truncate anywhere in this class, and
 * there never should be: a lawful erasure is a REVIEWED decision that has to
 * account for records the platform is obliged to keep. This service exists so
 * the reviewer can see exactly what must be preserved, in plain language, before
 * they decide. Nothing here is executed automatically.
 *
 * =========================================================================
 * WHAT IT LOOKS AT, AND WHY EACH THING MATTERS
 * =========================================================================
 *  - Financial records: a ledger that can lose rows cannot be audited, and tax
 *    and accounting duties outlive an account.
 *  - AML records: an anti-money-laundering assessment has its own statutory
 *    minimum, and deleting it because its subject asked would defeat the point
 *    of keeping it.
 *  - Open withdrawal: money in flight must be settled before any record about
 *    the person is touched.
 *  - Open compliance case: a live dispute may need the very records the person
 *    asked to erase.
 *  - Active self-exclusion: a responsible-gaming commitment runs on a clock of
 *    its own and must not be cut short by a data request.
 *  - Legal hold, either on the account (an active financial hold) or recorded on
 *    the request itself.
 *
 * =========================================================================
 * WHAT IT DELIBERATELY DOES NOT DO
 * =========================================================================
 * It does not opine on the law. Every window it reads comes from
 * config/privacy_requests.php ('retention'), which is an operator's declared
 * position and can be reviewed; the reasons it returns are translation keys so
 * the reviewer reads them in their own language.
 */
final class PrivacyRetentionGuard
{
    /** Reasons this guard can report. */
    public const REASON_FINANCIAL = 'financial_records';

    public const REASON_AML = 'aml_records';

    public const REASON_OPEN_WITHDRAWAL = 'open_withdrawal';

    public const REASON_OPEN_DISPUTE = 'open_dispute';

    public const REASON_SELF_EXCLUSION = 'self_exclusion';

    public const REASON_LEGAL_HOLD = 'legal_hold';

    public const REASON_LEGAL_HOLD_ON_REQUEST = 'legal_hold_reason';

    public const REASON_UNKNOWN_USER = 'unknown_user';

    /**
     * Evaluate one request.
     *
     * @return array{
     *     blocked: bool,
     *     user_id: int|null,
     *     reasons: list<array{code: string, label_key: string, detail: array<string, string|int>}>
     * }
     */
    public function evaluate(DataSubjectRequest $request): array
    {
        $user = $this->resolveUser($request);

        if (! $user instanceof User) {
            return [
                'blocked' => true,
                'user_id' => null,
                'reasons' => [$this->reason(self::REASON_UNKNOWN_USER)],
            ];
        }

        return $this->evaluateUser($user, $request);
    }

    /**
     * Evaluate one account, optionally in the context of a request.
     *
     * @return array{
     *     blocked: bool,
     *     user_id: int|null,
     *     reasons: list<array{code: string, label_key: string, detail: array<string, string|int>}>
     * }
     */
    public function evaluateUser(User $user, ?DataSubjectRequest $request = null): array
    {
        $userId = (int) $user->getKey();
        $reasons = [];

        $financial = $this->financialRecordsWithinWindow($userId);

        if ($financial > 0) {
            $reasons[] = $this->reason(self::REASON_FINANCIAL, [
                'records' => $financial,
                'days' => $this->windowDays('financial_records_days'),
            ]);
        }

        $aml = $this->amlRecordsWithinWindow($userId);

        if ($aml > 0) {
            $reasons[] = $this->reason(self::REASON_AML, [
                'records' => $aml,
                'days' => $this->windowDays('aml_records_days'),
            ]);
        }

        $openWithdrawals = $this->openWithdrawals($userId);

        if ($openWithdrawals > 0) {
            $reasons[] = $this->reason(self::REASON_OPEN_WITHDRAWAL, ['records' => $openWithdrawals]);
        }

        $openDisputes = $this->openDisputes($userId);

        if ($openDisputes > 0) {
            $reasons[] = $this->reason(self::REASON_OPEN_DISPUTE, ['records' => $openDisputes]);
        }

        if ($this->selfExclusionActive($userId)) {
            $reasons[] = $this->reason(self::REASON_SELF_EXCLUSION);
        }

        $holds = $this->activeFinancialHolds($userId);

        if ($holds > 0) {
            $reasons[] = $this->reason(self::REASON_LEGAL_HOLD, ['records' => $holds]);
        }

        if ($request instanceof DataSubjectRequest
            && is_string($request->legal_hold_reason)
            && trim($request->legal_hold_reason) !== '') {
            $reasons[] = $this->reason(self::REASON_LEGAL_HOLD_ON_REQUEST);
        }

        return [
            'blocked' => $reasons !== [],
            'user_id' => $userId,
            'reasons' => $reasons,
        ];
    }

    /**
     * The account a request is about, resolved strictly.
     *
     * Exactly one match or nothing: a linked user_id wins, otherwise an exact
     * (normalised) address match must identify a single account. Ambiguity is
     * reported by the caller as NeedsInformation - never guessed here.
     */
    public function resolveUser(DataSubjectRequest $request): ?User
    {
        if ($request->user_id !== null) {
            $user = User::query()->find((int) $request->user_id);

            return $user instanceof User ? $user : null;
        }

        $email = DataSubjectRequest::normaliseEmail((string) $request->email_encrypted);
        $matches = User::query()->whereRaw('LOWER(email) = ?', [$email])->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Count of the reviewer-facing reasons as a single sentence key.
     */
    public function summaryKey(array $report): string
    {
        return ($report['blocked'] ?? false) ? 'privacy_requests.retention.blocked' : 'privacy_requests.retention.clear';
    }

    private function financialRecordsWithinWindow(int $userId): int
    {
        if (! Schema::hasTable('financial_transactions')) {
            return 0;
        }

        return (int) DB::table('financial_transactions')
            ->where('user_id', $userId)
            ->where('created_at', '>=', Carbon::now()->subDays($this->windowDays('financial_records_days')))
            ->count();
    }

    private function amlRecordsWithinWindow(int $userId): int
    {
        if (! Schema::hasTable('aml_risk_assessments')) {
            return 0;
        }

        return (int) DB::table('aml_risk_assessments')
            ->where('user_id', $userId)
            ->where('assessed_at', '>=', Carbon::now()->subDays($this->windowDays('aml_records_days')))
            ->count();
    }

    /**
     * Withdrawals that have not reached a final state.
     */
    private function openWithdrawals(int $userId): int
    {
        return Withdrawal::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                WithdrawalStatus::Pending->value,
                WithdrawalStatus::UnderReview->value,
                WithdrawalStatus::KycRequired->value,
                WithdrawalStatus::Approved->value,
                WithdrawalStatus::Processing->value,
            ])
            ->count();
    }

    private function openDisputes(int $userId): int
    {
        if (! Schema::hasTable('compliance_cases')) {
            return 0;
        }

        return ComplianceCase::query()
            ->where('subject_user_id', $userId)
            ->whereIn('status', ['open', 'investigating', 'escalated'])
            ->count();
    }

    private function selfExclusionActive(int $userId): bool
    {
        if (! Schema::hasTable('responsible_gaming_limits')) {
            return false;
        }

        return DB::table('responsible_gaming_limits')
            ->where('user_id', $userId)
            ->whereNotNull('self_excluded_until')
            ->where('self_excluded_until', '>', Carbon::now())
            ->exists();
    }

    private function activeFinancialHolds(int $userId): int
    {
        if (! Schema::hasTable('financial_holds') || ! Schema::hasTable('wallets')) {
            return 0;
        }

        return (int) DB::table('financial_holds')
            ->join('wallets', 'wallets.id', '=', 'financial_holds.wallet_id')
            ->where('wallets.user_id', $userId)
            ->where('financial_holds.status', 'active')
            ->count();
    }

    private function windowDays(string $key): int
    {
        return max(1, (int) config('privacy_requests.retention.'.$key, 2555));
    }

    /**
     * @param  array<string, string|int>  $detail
     * @return array{code: string, label_key: string, detail: array<string, string|int>}
     */
    private function reason(string $code, array $detail = []): array
    {
        return [
            'code' => $code,
            'label_key' => 'privacy_requests.retention.'.$code,
            'detail' => $detail,
        ];
    }
}
