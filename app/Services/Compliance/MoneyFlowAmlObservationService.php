<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\AmlRiskLevel;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * One shared caller for the existing AML risk engine on the money side.
 *
 * WHY THIS EXISTS
 * `AmlRiskService`, `AmlRiskAssessmentService` and `ComplianceCaseService` were
 * complete, but nothing on the deposit / withdrawal / bet path called them: the
 * only control there was the KYC gate. A risk engine with no caller cannot see
 * money move. This service is that caller — deposit, withdrawal and bet all ask
 * it the same way, so there is one place to change behaviour and one audit
 * trail with one shape.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *  - It does not compute risk. It asks {@see AmlRiskService::measure()} and
 *    reports that answer. No threshold, weight or watchlist lives here or in
 *    config/aml.php, so there cannot be two conflicting verdicts.
 *  - It never calls `pronounce()`. Pronouncing writes a compliance verdict onto
 *    the account; that is an operator decision reached through a case, not a
 *    side effect of a player clicking "deposit".
 *  - It never opens a compliance case.
 *  - It never mutates the user, the wallet or the transaction.
 *
 * REFUSAL SEMANTICS
 * Only the existing service's own `Critical` verdict may refuse, and only in
 * `enforce` mode. High risk is NOT refused: escalating an account is a case
 * decision. In `log` mode nothing is ever refused — including a Critical
 * account — because wiring a detector up must not silently start turning real
 * money away.
 *
 * FAILURE SEMANTICS
 * A throwing assessment is recorded either way (`assessment_unavailable: …`).
 * In `log` mode it is swallowed: a detector not trusted to block must not be
 * able to stop a deposit. In `enforce` mode it refuses.
 */
final class MoneyFlowAmlObservationService
{
    public const FLOW_DEPOSIT = 'deposit';

    public const FLOW_WITHDRAWAL = 'withdrawal';

    public const FLOW_BET = 'bet';

    private const MODE_LOG = 'log';

    private const MODE_ENFORCE = 'enforce';

    private const MODE_OFF = 'off';

    /** Metadata key every row written here carries, so one query finds them all. */
    public const OPERATION = 'aml_money_flow_observation';

    public function __construct(
        private readonly AmlRiskService $aml,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Consult the existing risk engine for one money-flow event.
     *
     * @param  array<string, mixed>  $context  Descriptive facts about the event (amount, currency, draw…). Never authoritative, never trusted.
     * @return array{mode: string, observed: bool, level: string|null, score: int|null, refused: bool}
     *
     * @throws RuntimeException Only in `enforce` mode.
     */
    public function observe(User $user, string $flow, array $context = []): array
    {
        $mode = $this->modeFor($flow);

        if ($mode === self::MODE_OFF) {
            return ['mode' => $mode, 'observed' => false, 'level' => null, 'score' => null, 'refused' => false];
        }

        $userId = (int) $user->getKey();

        try {
            $assessment = $this->measure($userId);
        } catch (Throwable $e) {
            $this->record($user, $flow, $context, 'assessment_unavailable: '.$e->getMessage(), null, null, [], $mode);

            if ($mode === self::MODE_ENFORCE) {
                throw new RuntimeException(
                    'AML assessment unavailable for '.$flow.'; refusing because this flow is enforced.',
                    0,
                    $e,
                );
            }

            return ['mode' => $mode, 'observed' => false, 'level' => null, 'score' => null, 'refused' => false];
        }

        $level = $assessment['level'] instanceof AmlRiskLevel
            ? $assessment['level']
            : (AmlRiskLevel::tryFrom((string) ($assessment['level'] ?? '')) ?? AmlRiskLevel::Low);
        $score = isset($assessment['score']) ? (int) $assessment['score'] : null;
        $reasons = array_values(array_map('strval', (array) ($assessment['reason_codes'] ?? [])));

        $this->record($user, $flow, $context, 'observed', $level->value, $score, $reasons, $mode);

        if ($level === AmlRiskLevel::Critical && $mode === self::MODE_ENFORCE) {
            throw new RuntimeException(
                'AML engine reports a prohibited account; the '.$flow.' is refused while this flow is enforced.',
            );
        }

        return [
            'mode' => $mode,
            'observed' => true,
            'level' => $level->value,
            'score' => $score,
            'refused' => false,
        ];
    }

    /**
     * Resolve the effective mode: a per-flow setting wins, otherwise the global
     * default. Anything unrecognised degrades to `log`, never to `off` — an
     * operator typo must not silently stop observation.
     */
    private function modeFor(string $flow): string
    {
        if (! (bool) config('aml.enabled', true)) {
            return self::MODE_OFF;
        }

        $flowMode = config('aml.flows.'.$flow);
        $mode = is_string($flowMode) && $flowMode !== '' ? $flowMode : (string) config('aml.mode', self::MODE_LOG);

        return match ($mode) {
            self::MODE_OFF => self::MODE_OFF,
            self::MODE_ENFORCE => self::MODE_ENFORCE,
            default => self::MODE_LOG,
        };
    }

    /**
     * Ask the engine, reusing a recent answer for the same account.
     *
     * @return array<string, mixed>
     */
    private function measure(int $userId): array
    {
        $ttl = (int) config('aml.freshness_seconds', 300);

        if ($ttl <= 0) {
            return $this->aml->measure($userId);
        }

        $key = 'aml:money-flow:'.$userId;
        $cached = Cache::get($key);

        if (is_array($cached) && array_key_exists('level', $cached)) {
            return $cached;
        }

        $assessment = $this->aml->measure($userId);
        Cache::put($key, $assessment, $ttl);

        return $assessment;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $reasons
     */
    private function record(
        User $user,
        string $flow,
        array $context,
        string $outcome,
        ?string $level,
        ?int $score,
        array $reasons,
        string $mode,
    ): void {
        $this->audit->log(
            (int) $user->getKey(),
            AuditAction::SecurityAlert,
            str_starts_with($outcome, 'assessment_unavailable') || $level === AmlRiskLevel::Critical->value
                ? RiskLevel::High
                : RiskLevel::Medium,
            $user,
            'AML money-flow observation: '.$flow.' ('.$outcome.')',
            [
                'operation' => self::OPERATION,
                'flow' => $flow,
                'mode' => $mode,
                'outcome' => $outcome,
                'level' => $level,
                'score' => $score,
                'reason_codes' => $reasons,
                'context' => $context,
            ],
        );
    }
}
