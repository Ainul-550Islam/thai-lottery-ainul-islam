<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The AML mode authority for the money-flow lane.
 *
 * ============================================================================
 * WHY THIS EXISTS WHEN MoneyFlowAmlObservationService ALREADY DOES THE WORK
 * ============================================================================
 * The detector, the audit row shape and the refusal decision all live in
 * {@see MoneyFlowAmlObservationService} and must keep living there — one
 * detector, one verdict. What was missing was a single place that answers three
 * questions the rest of the application needs to ask consistently:
 *
 *   1. What mode is this flow in?        -> mode()
 *   2. Is this flow enforced?            -> isEnforced()
 *   3. Screen this event, per config.    -> screen()
 *
 * Scattering those three answers across controllers is how a deposit lane ends
 * up in `enforce` while the withdrawal lane quietly reads a different key.
 *
 * ============================================================================
 * MODE VOCABULARY
 * ============================================================================
 *   off       no assessment, no audit row, nothing refused
 *   monitor   assess and record; NEVER refuse (an unavailable assessment is
 *             recorded and does not block the flow)
 *   enforce   refuse on a Critical verdict; an unavailable assessment also
 *             refuses
 *
 * `monitor` is the operator-facing word for what the older configuration called
 * `log`. BOTH are accepted, and both mean exactly the same thing, so an existing
 * environment that sets AML_MODE=log keeps its behaviour. `monitor` is what this
 * file documents and what the operator console shows.
 *
 * AN UNRECOGNISED VALUE IS NOT `off`. A typo in AML_MODE must never switch
 * screening off, so anything outside the vocabulary resolves to `enforce` — the
 * strictest mode — and that resolution is written to the log once per request so
 * it is not silent. This mirrors the pre-existing behaviour and makes it explicit
 * rather than incidental.
 *
 * ============================================================================
 * PRODUCTION
 * ============================================================================
 * `off` in production is refused by ProductionSafetyServiceProvider (it is a
 * per-request check, not only a boot check, so flipping the config at runtime
 * cannot disable screening without a restart). The production default in
 * config/aml.php is `enforce`.
 */
final class AmlScreeningService
{
    public const MODE_OFF = 'off';

    public const MODE_MONITOR = 'monitor';

    public const MODE_ENFORCE = 'enforce';

    /** The historical spelling of `monitor`. Accepted, never documented. */
    private const MODE_LEGACY_LOG = 'log';

    /** @var array<string, bool> one warning per request per flow value */
    private array $warned = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly MoneyFlowAmlObservationService $observation,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * The effective mode for a flow: the per-flow override when set, otherwise
     * the global mode, normalised into the three-word vocabulary.
     */
    public function mode(string $flow): string
    {
        $perFlow = $this->config->get('aml.flows.'.$flow);
        $raw = is_string($perFlow) && trim($perFlow) !== ''
            ? trim($perFlow)
            : (string) $this->config->get('aml.mode', self::MODE_MONITOR);

        return $this->normalise($raw, $flow);
    }

    /**
     * Whether this flow may refuse a transaction.
     */
    public function isEnforced(string $flow): bool
    {
        return $this->mode($flow) === self::MODE_ENFORCE;
    }

    /**
     * Whether this flow is assessed at all.
     */
    public function isActive(string $flow): bool
    {
        return $this->mode($flow) !== self::MODE_OFF;
    }

    /**
     * Screen one money-flow event.
     *
     * Delegates the measurement and the audit row to the canonical observer and
     * re-throws its refusal unchanged, so a caller that catches
     * RuntimeException from either class behaves identically.
     *
     * @param  array<string, mixed>  $context  descriptive facts only; never authoritative
     * @return array{mode: string, observed: bool, level: string|null, score: int|null, refused: bool}
     *
     * @throws \RuntimeException in enforce mode when the engine is Critical or unavailable
     */
    public function screen(User $user, string $flow, array $context = []): array
    {
        $mode = $this->mode($flow);

        if ($mode === self::MODE_OFF) {
            return ['mode' => $mode, 'observed' => false, 'level' => null, 'score' => null, 'refused' => false];
        }

        try {
            return $this->observation->observe($user, $flow, $context);
        } catch (Throwable $e) {
            // The observer already wrote the audit row before throwing. Record
            // that the refusal reached the caller, then re-throw unchanged so the
            // caller's own error envelope stays in charge.
            $this->recordRefusal($user, $flow, $e);

            throw $e;
        }
    }

    /**
     * Screen without ever throwing. For surfaces that must continue and report
     * rather than abort (a status page, a preflight).
     *
     * @param  array<string, mixed>  $context
     * @return array{mode: string, observed: bool, level: string|null, score: int|null, refused: bool, error: string|null}
     */
    public function inspect(User $user, string $flow, array $context = []): array
    {
        try {
            return $this->screen($user, $flow, $context) + ['error' => null];
        } catch (Throwable $e) {
            return [
                'mode' => $this->mode($flow),
                'observed' => false,
                'level' => null,
                'score' => null,
                'refused' => true,
                'error' => $e::class,
            ];
        }
    }

    /**
     * A copy of the whole policy, for the operator console and for tests. Reads
     * only configuration, so it is safe to call on every admin page render.
     *
     * @return array{global: string, flows: array<string, array{mode: string, enforced: bool, active: bool, raw: string|null}>}
     */
    public function policy(): array
    {
        $flows = [];

        /** @var array<string, string|null> $declared */
        $declared = (array) $this->config->get('aml.flows', []);

        foreach ($declared as $flow => $configured) {
            $flows[(string) $flow] = [
                'mode' => $this->mode((string) $flow),
                'enforced' => $this->isEnforced((string) $flow),
                'active' => $this->isActive((string) $flow),
                'raw' => is_string($configured) && trim($configured) !== '' ? trim($configured) : null,
            ];
        }

        // The three canonical flows always appear, even when the config file has
        // no entry for them, so the console never shows a partial policy.
        foreach ([
            MoneyFlowAmlObservationService::FLOW_DEPOSIT,
            MoneyFlowAmlObservationService::FLOW_WITHDRAWAL,
            MoneyFlowAmlObservationService::FLOW_BET,
        ] as $flow) {
            if (! isset($flows[$flow])) {
                $flows[$flow] = [
                    'mode' => $this->mode($flow),
                    'enforced' => $this->isEnforced($flow),
                    'active' => $this->isActive($flow),
                    'raw' => null,
                ];
            }
        }

        return [
            'global' => $this->normalise((string) $this->config->get('aml.mode', self::MODE_MONITOR), 'global'),
            'flows' => $flows,
        ];
    }

    /**
     * Normalise one configured word into the three-word vocabulary.
     */
    private function normalise(string $raw, string $flow): string
    {
        $value = strtolower(trim($raw));

        if ($value === self::MODE_OFF) {
            return self::MODE_OFF;
        }

        if ($value === self::MODE_MONITOR || $value === self::MODE_LEGACY_LOG) {
            return self::MODE_MONITOR;
        }

        if ($value === self::MODE_ENFORCE) {
            return self::MODE_ENFORCE;
        }

        // Unknown value: strictest, and never silent.
        $key = $flow.'|'.$value;

        if (! isset($this->warned[$key])) {
            $this->warned[$key] = true;

            Log::warning('aml.unrecognised_mode', [
                'flow' => $flow,
                'configured' => $value,
                'resolved' => self::MODE_ENFORCE,
            ]);
        }

        return self::MODE_ENFORCE;
    }

    private function recordRefusal(User $user, string $flow, Throwable $e): void
    {
        try {
            $this->audit->log(
                userId: (int) $user->getKey(),
                action: AuditAction::SecurityAlert,
                riskLevel: RiskLevel::High,
                auditable: $user,
                description: 'AML screening refused a money flow.',
                metadata: [
                    'lane' => 'aml_screening',
                    'flow' => $flow,
                    'mode' => $this->mode($flow),
                    'exception' => $e::class,
                ],
            );
        } catch (Throwable $auditFailure) {
            Log::critical('aml.refusal_audit_failed', [
                'flow' => $flow,
                'exception' => $auditFailure::class,
            ]);
        }
    }
}
