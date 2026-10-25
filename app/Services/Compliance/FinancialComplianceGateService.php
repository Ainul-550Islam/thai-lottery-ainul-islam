<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\AmlRiskLevel;
use App\Enums\AuditAction;
use App\Enums\ComplianceActionType;
use App\Enums\ComplianceCaseStatus;
use App\Enums\Currency;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\ComplianceAction;
use App\Models\ComplianceCase;
use App\Models\ResponsibleGamingLimit;
use App\Models\User;
use App\Services\Finance\Money;
use Illuminate\Config\Repository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The single fail-closed gate every money-OUT decision passes through.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * PayoutTransferService already called
 * `app(FinancialComplianceGateService::class)->assertPayoutAllowed(...)`, but
 * the class itself was never written: every prize-payout completion died with a
 * container resolution error AFTER the payout had been reviewed, approved,
 * batched and evidenced. A missing class in the middle of a money path is not a
 * missing feature — it is a payout that can never settle and a reviewer who
 * cannot tell why.
 *
 * WHAT IT IS
 * ----------
 * A composition of the EXISTING compliance services, asked in one fixed order,
 * with one answer per question. It adds no policy of its own: the real-money
 * switch belongs to RealMoneyGate, the KYC standing to the KYC lane, the
 * exclusion and cool-off windows to the responsible-gaming lane, the case and
 * hold state to the compliance-case lane, and the risk band to the AML lane.
 * This class only decides THAT all of them must agree before money leaves.
 *
 * THE RULE: REFUSE BY NAME, AUDIT EVERY REFUSAL
 * ---------------------------------------------
 * Both public verbs throw `FinancialException::withCode(...)`; neither ever
 * returns false. A caller therefore cannot mistake "not checked" for "allowed".
 * Every refusal writes one AuditLog row naming the lane, the operation, the
 * amount and the currency — never a name, an e-mail, a document number, a
 * destination or any other personal data. A refusal is a fact an auditor needs
 * ("who was stopped, under which rule, for how much"), not a dossier.
 *
 * FAIL-CLOSED MEANS REFUSING ON DOUBT
 * -----------------------------------
 * An unreadable AML assessment, an unparseable protection window, an unknown
 * configured threshold and a missing user all refuse. The only way money moves
 * is through a complete, unambiguous YES.
 */
final class FinancialComplianceGateService
{
    /** Prize / GLO payout lane: KYC standing is required at every amount. */
    public const LANE_PAYOUT = 'payout';

    /** Player withdrawal lane: the existing amount-threshold KYC rule applies. */
    public const LANE_WITHDRAWAL = 'withdrawal';

    public function __construct(
        private readonly RealMoneyGate $realMoneyGate,
        private readonly KycVerificationService $kycVerification,
        private readonly SelfExclusionService $selfExclusions,
        private readonly WithdrawalKycGateService $withdrawalKycGate,
        private readonly AmlRiskService $amlRisk,
        private readonly Repository $config,
    ) {}

    /**
     * May this beneficiary be paid this amount, in this currency, for this
     * operation? Throws by name when not.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws FinancialException
     */
    public function assertPayoutAllowed(
        User $beneficiary,
        string $amount,
        string $currency,
        string $operation,
        array $context = [],
    ): void {
        $this->assertAllowed($beneficiary, $amount, $currency, $operation, $context, self::LANE_PAYOUT);
    }

    /**
     * May this player withdraw this amount, in this currency? Throws by name
     * when not.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws FinancialException
     */
    public function assertWithdrawalAllowed(User $user, string $amount, string $currency, array $context = []): void
    {
        $this->assertAllowed($user, $amount, $currency, 'withdrawal', $context, self::LANE_WITHDRAWAL);
    }

    /**
     * The one ordered interrogation. Every step is independent: the first
     * refusal stops the walk, so the audit row names the FIRST rule that said
     * no, which is the rule an operator has to clear first.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws FinancialException
     */
    private function assertAllowed(
        User $subject,
        string $amount,
        string $currency,
        string $operation,
        array $context,
        string $lane,
    ): void {
        if ($subject->getKey() === null) {
            throw FinancialException::withCode(
                'payout_subject_missing',
                'The payout subject could not be resolved; no money was moved.',
                ['operation' => $operation, 'lane' => $lane],
            );
        }

        $currencyEnum = $this->assertMoneyShape($amount, $currency, $operation, $lane);

        // 1. The deployment-wide real-money switch. Closed means nothing moves,
        //    for any subject, at any amount.
        try {
            $this->realMoneyGate->assertOpen($operation);
        } catch (FinancialException $exception) {
            $this->refuse($subject, $exception->errorCode() ?? 'real_money_disabled', $exception->getMessage(), $operation, $lane, $amount, $currencyEnum, $context);
        }

        // 2. Identity. A payout pays a proven person; a withdrawal above the
        //    configured floor does the same through the existing KYC gate.
        $this->assertIdentity($subject, $amount, $currencyEnum, $operation, $lane, $context);

        // 3. The compliance desk's own state: an open file or an unreleased
        //    hold/restriction on the subject.
        $this->assertNoComplianceHold($subject, $operation, $lane, $amount, $currencyEnum, $context);

        // 4. Protection windows. Money leaving the game is the player's exit
        //    path, but an ACTIVE self-exclusion or cool-off still has to be
        //    honoured: the desk closes the door to play, and a protection the
        //    player asked for is not silently waived by a payout.
        $this->assertNoProtectionWindow($subject, $operation, $lane, $amount, $currencyEnum, $context);

        // 5. AML. The measured band, against the configured block threshold.
        $this->assertAmlCleared($subject, $operation, $lane, $amount, $currencyEnum, $context);

        // 6. Configured outbound bounds for this lane.
        $this->assertWithinConfiguredBounds($amount, $currencyEnum, $operation, $lane, $subject, $context);
    }

    /**
     * A well-formed 2-decimal, positive amount in a known currency. Anything
     * else is a client or configuration fault and refuses before any service
     * is consulted.
     *
     * @throws FinancialException
     */
    private function assertMoneyShape(string $amount, string $currency, string $operation, string $lane): Currency
    {
        $currencyEnum = Currency::tryFrom(strtoupper(trim($currency)));

        if ($currencyEnum === null) {
            throw FinancialException::withCode(
                'payout_currency_unsupported',
                'The requested currency is not supported for this operation.',
                ['operation' => $operation, 'lane' => $lane, 'currency' => $currency],
            );
        }

        if (preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{2}\z/D', $amount) !== 1) {
            throw FinancialException::withCode(
                'payout_amount_malformed',
                'The amount is not a well-formed 2-decimal string.',
                ['operation' => $operation, 'lane' => $lane, 'currency' => $currencyEnum->value],
            );
        }

        try {
            $money = Money::of($amount, $currencyEnum);
        } catch (Throwable) {
            throw FinancialException::withCode(
                'payout_amount_malformed',
                'The amount is not a well-formed 2-decimal string.',
                ['operation' => $operation, 'lane' => $lane, 'currency' => $currencyEnum->value],
            );
        }

        if (! $money->isPositive()) {
            throw FinancialException::withCode(
                'payout_amount_not_positive',
                'The amount must be greater than zero.',
                ['operation' => $operation, 'lane' => $lane, 'currency' => $currencyEnum->value],
            );
        }

        return $currencyEnum;
    }

    /**
     * @param  array<string, scalar|null>  $context
     *
     * @throws FinancialException
     */
    private function assertIdentity(
        User $subject,
        string $amount,
        Currency $currency,
        string $operation,
        string $lane,
        array $context,
    ): void {
        $status = $subject->kycStatus();

        if ($lane === self::LANE_WITHDRAWAL) {
            // The existing threshold rule composes untouched: below the floor a
            // small withdrawal flows, at or above it the aggregate standing
            // must be Verified. Its own stable code and audit row are kept.
            try {
                $this->withdrawalKycGate->assertCanWithdraw($subject, $amount, $currency);
            } catch (FinancialException $exception) {
                $this->refuse(
                    $subject,
                    $exception->errorCode() ?? 'kyc_gate_withdrawal_denied',
                    $exception->getMessage(),
                    $operation,
                    $lane,
                    $amount,
                    $currency,
                    $context,
                    $exception,
                );
            }
        } elseif (! $status->isVerified()) {
            $this->refuse(
                $subject,
                'payout_kyc_not_verified',
                'The beneficiary identity is not verified; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
            );
        }

        // A pronounced verdict is the authority when one exists: a Rejected or
        // Expired verdict refuses even if a stale document row still reads
        // Verified, and an assessment that cannot be read refuses rather than
        // being treated as absent.
        try {
            $verdict = $this->kycVerification->liveVerdictFor((int) $subject->getKey());
        } catch (Throwable $exception) {
            $this->refuse(
                $subject,
                'kyc_verdict_unavailable',
                'The identity verdict could not be read; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
                $exception,
            );

            return;
        }

        if ($verdict !== null && ! $verdict->status->countsAsVerified()) {
            $this->refuse(
                $subject,
                'payout_kyc_not_verified',
                'The beneficiary identity is not verified; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
            );
        }
    }

    /**
     * @param  array<string, scalar|null>  $context
     *
     * @throws FinancialException
     */
    private function assertNoComplianceHold(
        User $subject,
        string $operation,
        string $lane,
        string $amount,
        Currency $currency,
        array $context,
    ): void {
        $liveStatuses = [
            ComplianceCaseStatus::Open->value,
            ComplianceCaseStatus::Investigating->value,
            ComplianceCaseStatus::Escalated->value,
        ];

        try {
            $openCase = ComplianceCase::query()
                ->where('subject_user_id', (int) $subject->getKey())
                ->whereIn('status', $liveStatuses)
                ->exists();

            if ($openCase) {
                $this->refuse(
                    $subject,
                    'compliance_case_open',
                    'An open compliance case applies to this account; the payout was not released.',
                    $operation,
                    $lane,
                    $amount,
                    $currency,
                    $context,
                );
            }

            // An unreleased Restrict/Hold act is the desk's seal on the money
            // lane; only a Release act from the same case lifts it, and that
            // happens in ComplianceActionService, never here.
            $activeHold = ComplianceAction::query()
                ->whereIn('action_type', [
                    ComplianceActionType::Restrict->value,
                    ComplianceActionType::Hold->value,
                ])
                ->where('is_released', false)
                ->whereHas('case', static function ($query) use ($subject, $liveStatuses): void {
                    $query->where('subject_user_id', (int) $subject->getKey())
                        ->whereIn('status', $liveStatuses);
                })
                ->exists();
        } catch (Throwable $exception) {
            $this->refuse(
                $subject,
                'compliance_state_unavailable',
                'The compliance state could not be read; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
                $exception,
            );

            return;
        }

        if ($activeHold) {
            $this->refuse(
                $subject,
                'compliance_hold_active',
                'A compliance hold applies to this account; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
            );
        }
    }

    /**
     * @param  array<string, scalar|null>  $context
     *
     * @throws FinancialException
     */
    private function assertNoProtectionWindow(
        User $subject,
        string $operation,
        string $lane,
        string $amount,
        Currency $currency,
        array $context,
    ): void {
        $userId = (int) $subject->getKey();

        try {
            $excluded = $this->selfExclusions->hasActiveExclusion($userId);
        } catch (Throwable $exception) {
            $this->refuse(
                $subject,
                'protection_state_unavailable',
                'The protection state could not be read; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
                $exception,
            );

            return;
        }

        if ($excluded) {
            $this->refuse(
                $subject,
                'self_exclusion_active',
                'An active self-exclusion applies to this account; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
            );
        }

        // The legacy lane: before the self_exclusions table existed, a player's
        // protection was a pair of timestamps on responsible_gaming_limits, and
        // every row written by an operator tool or an older release still lives
        // there. Reading only the new table would let a player protected
        // through the old columns walk straight through this gate. Trashed rows
        // count (deleting a live protection is not cancelling it) and a value
        // that cannot be parsed is treated as still running, because the safe
        // answer to "when does this end?" we cannot answer is "not yet".
        try {
            /** @var ResponsibleGamingLimit|null $legacy */
            $legacy = ResponsibleGamingLimit::withTrashed()
                ->where('user_id', $userId)
                ->first(['id', 'self_excluded_until', 'cool_off_until']);
        } catch (Throwable $exception) {
            $this->refuse(
                $subject,
                'protection_state_unavailable',
                'The protection state could not be read; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
                $exception,
            );

            return;
        }

        foreach (['self_excluded_until', 'cool_off_until'] as $column) {
            $raw = $legacy?->getRawOriginal($column);

            if ($raw === null) {
                continue;
            }

            $until = $this->parseTimestamp($raw);

            if ($until === null || $until->isFuture()) {
                $this->refuse(
                    $subject,
                    'responsible_gaming_restricted',
                    'A responsible-gaming restriction applies to this account; the payout was not released.',
                    $operation,
                    $lane,
                    $amount,
                    $currency,
                    $context,
                );
            }
        }
    }

    /**
     * @param  array<string, scalar|null>  $context
     *
     * @throws FinancialException
     */
    private function assertAmlCleared(
        User $subject,
        string $operation,
        string $lane,
        string $amount,
        Currency $currency,
        array $context,
    ): void {
        try {
            $measured = $this->amlRisk->measure((int) $subject->getKey());
            $level = $measured['level'] instanceof AmlRiskLevel ? $measured['level'] : null;
        } catch (Throwable $exception) {
            $this->refuse(
                $subject,
                'aml_assessment_unavailable',
                'The risk assessment could not be completed; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
                $exception,
            );

            return;
        }

        if (! $level instanceof AmlRiskLevel) {
            $this->refuse(
                $subject,
                'aml_assessment_unavailable',
                'The risk assessment could not be completed; the payout was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
            );

            return;
        }

        $threshold = $this->amlBlockThreshold();

        if ($level->severity() >= $threshold->severity()) {
            $this->refuse(
                $subject,
                'aml_risk_blocked',
                'The account risk level blocks this payout; it was not released.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
            );
        }
    }

    /**
     * The configured AML block band. An unrecognised or missing value falls
     * back to Critical — the strictest reading — so a typo in configuration
     * can never widen what this gate refuses.
     */
    private function amlBlockThreshold(): AmlRiskLevel
    {
        $configured = $this->config->get('aml.block_level', AmlRiskLevel::Critical->value);
        $level = is_string($configured) ? AmlRiskLevel::tryFrom(strtolower(trim($configured))) : null;

        return $level ?? AmlRiskLevel::Critical;
    }

    /**
     * @param  array<string, scalar|null>  $context
     *
     * @throws FinancialException
     */
    private function assertWithinConfiguredBounds(
        string $amount,
        Currency $currency,
        string $operation,
        string $lane,
        User $subject,
        array $context,
    ): void {
        $minimum = $this->bound($currency, 'payment.withdrawal.min', 'finance.withdrawal.min');
        $maximum = $this->bound($currency, 'payment.withdrawal.max', 'finance.withdrawal.max');

        try {
            $money = Money::of($amount, $currency);
        } catch (Throwable) {
            return; // Shape was already proven above; nothing left to compare.
        }

        if ($minimum !== null && $money->isLessThan($minimum)) {
            $this->refuse(
                $subject,
                'payout_below_configured_minimum',
                'The amount is below the configured minimum for this operation.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
            );
        }

        if ($maximum !== null && $money->isGreaterThan($maximum)) {
            $this->refuse(
                $subject,
                'payout_above_configured_maximum',
                'The amount is above the configured maximum for this operation.',
                $operation,
                $lane,
                $amount,
                $currency,
                $context,
            );
        }
    }

    /**
     * A configured bound as Money in the operation's own currency, or null when
     * the deployment has not set one. An unset bound is not a zero bound: a
     * missing limit must never refuse every payout, and a malformed one must
     * never silently widen.
     */
    private function bound(Currency $currency, string ...$keys): ?Money
    {
        foreach ($keys as $key) {
            $value = $this->config->get($key);

            if (is_string($value) && trim($value) !== '') {
                try {
                    return Money::of(trim($value), $currency);
                } catch (Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Strict reading of a stored timestamp. Anything unparseable yields null,
     * which callers treat as "the window is still open".
     */
    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The single refusal point: one audit row, then the named exception.
     *
     * The audit row carries the rule code, the operation, the lane, the amount
     * and the currency. It never carries a name, an e-mail address, a phone
     * number, a document number, a bank account or a destination — the user id
     * is the only subject identifier, exactly as the other compliance lanes
     * record it.
     *
     * @param  array<string, scalar|null>  $context
     *
     * @throws FinancialException always
     */
    private function refuse(
        User $subject,
        string $code,
        string $message,
        string $operation,
        string $lane,
        string $amount,
        Currency $currency,
        array $context,
        ?Throwable $previous = null,
    ): never {
        $this->recordRefusal($subject, $code, $operation, $lane, $amount, $currency, $context);

        throw FinancialException::withCode(
            $code,
            $message,
            [
                'operation' => $operation,
                'lane' => $lane,
                'amount' => $amount,
                'currency' => $currency->value,
                'user_id' => (int) $subject->getKey(),
            ],
            $previous,
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    private function recordRefusal(
        User $subject,
        string $code,
        string $operation,
        string $lane,
        string $amount,
        Currency $currency,
        array $context,
    ): void {
        if (! (bool) $this->config->get('finance.audit.enabled', true)) {
            return;
        }

        $metadata = [
            'gate' => 'financial_compliance',
            'reason_code' => $code,
            'operation' => $operation,
            'lane' => $lane,
            'amount' => $amount,
            'currency' => $currency->value,
        ];

        // Only scalar context values survive: a caller must not be able to
        // smuggle a destination, a document number or a token into an audit row.
        foreach ($context as $key => $value) {
            if (is_string($key) && (is_scalar($value) || $value === null)) {
                $metadata['context_'.$key] = $value;
            }
        }

        $log = new AuditLog;
        $log->fill([
            'user_id' => (int) $subject->getKey(),
            'action' => $lane === self::LANE_PAYOUT ? AuditAction::Payout : AuditAction::Withdraw,
            'risk_level' => RiskLevel::High,
            'auditable_type' => User::class,
            'auditable_id' => (int) $subject->getKey(),
            'description' => sprintf(
                'Financial compliance gate refused a %s (%s) of %s %s: %s.',
                $operation,
                $lane,
                $amount,
                $currency->value,
                $code,
            ),
            'metadata' => $metadata,
        ]);
        $log->save();
    }
}
