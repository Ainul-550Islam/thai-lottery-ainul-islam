<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Services\Finance\Money;
use App\Services\Operations\ProviderReadinessService;
use Illuminate\Config\Repository;
use Throwable;

/**
 * Operation-level control over real-money movement.
 *
 * WHAT THIS CLASS IS
 * ------------------
 * `RealMoneyGate` answers one question with one bit: may THIS DEPLOYMENT move
 * money at all. That is the right question for a launch switch and the wrong one
 * for an admission check, because "the switch is open" says nothing about whether
 * a deposit can actually be carried, whether the ledger can post it, or whether
 * the amount is inside anybody's stated appetite. Today every money path asks
 * only the bit:
 *
 *     app(RealMoneyGate::class)->assertOpen('deposit');
 *
 * and then discovers the rest — one `FinancialException` at a time — further down
 * the stack, after a wallet row has been locked and sometimes after a provider
 * has been called. This class is the single place that asks all of it up front,
 * for one named operation, and answers once.
 *
 * WHAT IT COMPOSES, AND WHAT IT DOES NOT RE-DECIDE
 * ------------------------------------------------
 * Four owners already exist and each keeps its own verdict:
 *
 *   - the master switch      -> RealMoneyGate::assertOpen()      (delegated, same
 *                              `real_money_disabled` code every caller sees today)
 *   - the technical ability  -> RealMoneyTechnicalReadinessService (delegated)
 *   - the provider lane      -> ProviderReadinessService          (delegated)
 *   - the legal authority    -> EnforceRealMoneyLicense middleware (NOT touched:
 *                              the licence stays a separate, future gate exactly
 *                              as it is today, and this class never enforces it)
 *
 * The only rule this class owns is the amount bound, and even that is not
 * invented here: `real_money.control.bounds` names the EXISTING config keys each
 * operation is bounded by (`finance.deposit.min`, `risk.user_limits.max_stake_per_bet`,
 * …), so display, execution and admission all read one value. An operation with
 * no configured bound reports "unbounded" rather than defaulting to a number
 * nobody chose — the same principle `risk.exposure.max_stake_per_number` follows
 * by shipping without a default.
 *
 * REPORT vs ENFORCE — WHY THE NEW CONTROLS DEFAULT TO `report`
 * ------------------------------------------------------------
 * `real_money.control.technical_readiness_mode` and `..._lane_readiness_mode`
 * each take `report` or `enforce`, and both default to `report`.
 *
 * This is not hedging. The master switch is enforced unconditionally and always
 * has been, so wiring this service into an existing path can never widen what
 * that path accepts. The two NEW controls default to `report` because a readiness
 * verdict that starts refusing live deposits the moment it is deployed is a
 * change to money behaviour made by a config file nobody reviewed — and because
 * an operator must be able to READ the answer on a staging box before it is
 * allowed to decide anything. In `report` mode a failing control does not refuse;
 * it is recorded in the decision as `warnings`, so the answer is visible without
 * being load-bearing. Flip one key to `enforce` and the same verdict refuses.
 *
 * NO WRITES, EVER
 * ---------------
 * Neither `evaluate()` nor `assertAllowed()` writes a row, a cache entry, an
 * audit entry or a log line. This matters more than it looks: admission runs
 * inside the caller's database transaction, and `FinancialComplianceGateService`
 * needed a whole extra method (`auditPayoutRefusalAfterRollback`) precisely
 * because a refusal audit written inside a transaction that then rolls back is an
 * audit that never happened. So this class returns the refusal facts via
 * `describeRefusal()` and the CALLER decides when and where to persist them,
 * after its own money boundary has unwound.
 *
 * FAIL-CLOSED, BY NAME
 * --------------------
 * `assertAllowed()` throws; it never returns false, so a caller cannot mistake
 * "not checked" for "allowed". Every refusal carries a stable machine code, and
 * every check runs BEFORE any balance is read or written, matching the contract
 * `RealMoneyGate::assertOpen()` already publishes.
 */
final class MoneyOperationControlService
{
    // ---------------------------------------------------------------------
    // Decision statuses. One per distinct reason an operation can be refused,
    // plus one for "allowed, but a report-mode control disagreed".
    // ---------------------------------------------------------------------
    public const ALLOWED = 'ALLOWED';

    public const ALLOWED_WITH_WARNINGS = 'ALLOWED_WITH_WARNINGS';

    public const REFUSED_MASTER_SWITCH = 'REFUSED_MASTER_SWITCH';

    public const REFUSED_OPERATION_UNKNOWN = 'REFUSED_OPERATION_UNKNOWN';

    /**
     * The runtime operational control is closed, or could not be read.
     *
     * A separate status from REFUSED_MASTER_SWITCH on purpose: the two have the
     * same effect and completely different remedies. The master switch is
     * configuration and changes with a deploy; this one is a row an
     * administrator wrote during an incident, and the first question an operator
     * asks is who wrote it and why - which the audit log answers.
     */
    public const REFUSED_OPERATION_CONTROL = 'REFUSED_OPERATION_CONTROL';

    public const REFUSED_AMOUNT_SHAPE = 'REFUSED_AMOUNT_SHAPE';

    public const REFUSED_AMOUNT_BOUNDS = 'REFUSED_AMOUNT_BOUNDS';

    public const REFUSED_CURRENCY = 'REFUSED_CURRENCY';

    public const REFUSED_PROVIDER_LANE = 'REFUSED_PROVIDER_LANE';

    public const REFUSED_TECHNICAL_READINESS = 'REFUSED_TECHNICAL_READINESS';

    // ---------------------------------------------------------------------
    // Control modes.
    // ---------------------------------------------------------------------
    public const MODE_REPORT = 'report';

    public const MODE_ENFORCE = 'enforce';

    public function __construct(
        private readonly RealMoneyGate $realMoneyGate,
        private readonly MoneyShutdownControlService $shutdownControl,
        private readonly RealMoneyTechnicalReadinessService $technicalReadiness,
        private readonly ProviderReadinessService $providers,
        private readonly Repository $config,
    ) {}

    /**
     * The complete control answer for one operation. Read-only.
     *
     * The return shape deliberately follows `GloL6PurchaseCapabilityService::capability()`
     * — a flat array of `status`, `allowed`, `reason`, `gates`, `missing` — so an
     * operator UI or a deploy script that already reads one capability answer can
     * read this one the same way.
     *
     * @param  string  $operation  one of RealMoneyTechnicalReadinessService::OPERATIONS
     * @param  string|null  $amount  decimal string; null means "no amount supplied"
     * @param  Currency|string|null  $currency  null resolves to the configured site currency
     * @return array{
     *     operation: string,
     *     allowed: bool,
     *     status: string,
     *     reason: string|null,
     *     message: string,
     *     amount: string|null,
     *     currency: string|null,
     *     gates: array<string, bool>,
     *     modes: array<string, string>,
     *     bounds: array<string, string|null>,
     *     blocking: array<string, string>,
     *     warnings: array<string, string>,
     *     technical: array<string, mixed>,
     *     lane: array<string, mixed>|null
     * }
     */
    public function evaluate(string $operation, ?string $amount = null, Currency|string|null $currency = null): array
    {
        $normalised = $this->normaliseOperation($operation);

        if ($normalised === null) {
            return $this->decision(
                $operation,
                self::REFUSED_OPERATION_UNKNOWN,
                'real_money_operation_unknown',
                trans('real_money.control.operation_unknown', ['operation' => $operation]),
                gates: ['operation_known' => false],
                blocking: ['operation' => 'unknown operation name'],
            );
        }

        // ---------------------------------------------------------------
        // 1. THE MASTER SWITCH. Delegated, so the refusal is byte-identical
        //    to what every existing money path already produces.
        // ---------------------------------------------------------------
        $gates = ['master_switch_open' => $this->realMoneyGate->isOpen()];

        if (! $this->realMoneyGate->isOpen()) {
            return $this->decision(
                $normalised,
                self::REFUSED_MASTER_SWITCH,
                'real_money_disabled',
                trans('real_money.control.master_switch_closed', ['operation' => $normalised]),
                amount: $amount,
                currency: $this->currencyCode($currency),
                gates: $gates,
                blocking: ['real_money.gate_switch' => 'real money is closed on this deployment'],
            );
        }

        // ---------------------------------------------------------------
        // 2. THE RUNTIME CONTROL. Beside the master switch rather than after
        //    the money-shaped checks, because both answer the same question -
        //    may money move at all - and neither depends on an amount, a
        //    currency or a lane. Refusing here reads no balance and writes
        //    nothing, so a halted operation leaves the estate untouched.
        //
        //    The control is in SERIES with the master switch: an administrator
        //    can halt money during an incident without a deploy, and opening the
        //    control again does not authorize anything the master switch, the
        //    licence and the technical readiness verdict do not already allow.
        // ---------------------------------------------------------------
        $controlOpen = $this->shutdownControl->isOpen();
        $gates['operation_control_open'] = $controlOpen;

        if (! $controlOpen) {
            $controlStatus = $this->shutdownControl->status();

            return $this->decision(
                $normalised,
                self::REFUSED_OPERATION_CONTROL,
                // An unreadable control is reported under its own reason: "we
                // cannot see the control" and "an administrator closed the
                // control" both refuse, and conflating them sends an operator
                // looking for a person where the problem is a database.
                $controlStatus['storage_available'] ? 'real_money_control_closed' : 'real_money_control_unavailable',
                trans('real_money.control.operation_control_closed', ['operation' => $normalised]),
                amount: $amount,
                currency: $this->currencyCode($currency),
                gates: $gates,
                blocking: [
                    'operation_control' => $controlStatus['storage_available']
                        ? 'the runtime money control is closed'
                        : 'the runtime money control could not be read, so it is treated as closed',
                ],
            );
        }

        // ---------------------------------------------------------------
        // 3. CURRENCY. Resolved before the amount, because an amount without
        //    a currency has no bounds to be compared against.
        // ---------------------------------------------------------------
        $resolvedCurrency = $this->resolveCurrency($currency);

        if ($resolvedCurrency === null) {
            $gates['currency_supported'] = false;

            return $this->decision(
                $normalised,
                self::REFUSED_CURRENCY,
                'real_money_currency_unsupported',
                trans('real_money.control.currency_unsupported', [
                    'operation' => $normalised,
                    'currency' => $this->currencyCode($currency) ?? '(unresolved)',
                ]),
                amount: $amount,
                // The code that was ASKED for, not a resolved fallback: a refusal
                // that echoes THB when the caller said XYZ would point an operator
                // at the wrong currency and hide the actual defect.
                currency: $this->currencyCode($currency),
                gates: $gates,
                blocking: ['currency' => 'the currency does not resolve to a supported Currency case'],
            );
        }

        $gates['currency_supported'] = true;

        // ---------------------------------------------------------------
        // 4. AMOUNT SHAPE AND BOUNDS. The one rule this class owns.
        // ---------------------------------------------------------------
        $bounds = $this->bounds($normalised);
        $money = null;

        if ($amount !== null) {
            try {
                $money = Money::of($amount, $resolvedCurrency);
                $money->assertPositive('amount');
            } catch (Throwable $e) {
                $gates['amount_within_bounds'] = false;

                return $this->decision(
                    $normalised,
                    self::REFUSED_AMOUNT_SHAPE,
                    'real_money_amount_invalid',
                    trans('real_money.control.amount_invalid', ['operation' => $normalised]),
                    amount: $amount,
                    currency: $resolvedCurrency->value,
                    gates: $gates,
                    bounds: $bounds,
                    blocking: ['amount' => $e instanceof FinancialException ? (string) $e->errorCode() : 'amount is not a positive decimal'],
                );
            }
        }

        $boundFailure = $this->boundFailure($money, $bounds, $resolvedCurrency);
        $gates['amount_within_bounds'] = $boundFailure === null;

        if ($boundFailure !== null) {
            return $this->decision(
                $normalised,
                self::REFUSED_AMOUNT_BOUNDS,
                'real_money_amount_out_of_bounds',
                trans('real_money.control.amount_out_of_bounds', [
                    'operation' => $normalised,
                    'limit' => $boundFailure['limit'],
                    'bound' => $boundFailure['bound'],
                    'currency' => $resolvedCurrency->value,
                ]),
                amount: $amount,
                currency: $resolvedCurrency->value,
                gates: $gates,
                bounds: $bounds,
                blocking: ['amount_bounds' => $boundFailure['limit'].' bound is '.$boundFailure['bound'].' '.$resolvedCurrency->value],
            );
        }

        // ---------------------------------------------------------------
        // 5. THE PROVIDER LANE, in `report` or `enforce` mode.
        // ---------------------------------------------------------------
        $lane = $this->lane($normalised, $resolvedCurrency);
        $laneMode = $this->mode('lane_readiness_mode');
        $gates['provider_lane_ready'] = $lane['ready'];
        $gates['provider_lane_required'] = $lane['required'];

        $warnings = [];
        $laneBlocking = [];

        if ($lane['required'] && ! $lane['ready']) {
            if ($laneMode === self::MODE_ENFORCE) {
                $laneBlocking['provider_lane'] = $lane['detail'];
            } else {
                $warnings['provider_lane'] = $lane['detail'];
            }
        }

        // ---------------------------------------------------------------
        // 6. TECHNICAL READINESS — last, because it is the only control that
        //    reads the database.
        // ---------------------------------------------------------------
        $technical = $this->technicalReadiness->report($normalised);
        $technicalMode = $this->mode('technical_readiness_mode');

        // The SCOPED verdict, not `$technical['ready']`. The report still lists
        // every check — an operator auditing a deposit benefits from seeing the
        // payout lane too — but `ready` on the report is the whole-estate answer,
        // and gating a bet on the KYC custody disks would be deciding one
        // operation by a rule that cannot stop it.
        $gates['technically_ready'] = $technical['blocking'] === [];

        $technicalBlocking = [];
        foreach ($technical['blocking'] as $id => $detail) {
            if ($technicalMode === self::MODE_ENFORCE) {
                $technicalBlocking[(string) $id] = (string) $detail;
            } else {
                $warnings[(string) $id] = (string) $detail;
            }
        }

        // ---------------------------------------------------------------
        // THE VERDICT.
        //
        // One source per refusal, chosen in a fixed priority order rather than
        // inferred from array order: technical readiness is the broader question
        // ("can this platform carry money at all") and the lane is the narrower
        // one ("is there a rail to carry it on"), so the broader finding names
        // the refusal when both hold. `$blocking` still carries every enforced
        // finding, so an operator fixing the first one immediately sees the next.
        // ---------------------------------------------------------------
        if ($technicalBlocking !== [] || $laneBlocking !== []) {
            $technicalRefused = $technicalBlocking !== [];

            return $this->decision(
                $normalised,
                $technicalRefused ? self::REFUSED_TECHNICAL_READINESS : self::REFUSED_PROVIDER_LANE,
                $technicalRefused ? 'real_money_technical_readiness_failed' : 'real_money_provider_lane_unready',
                trans('real_money.control.enforced_refusal', [
                    'operation' => $normalised,
                    'checks' => implode(', ', array_keys($technicalBlocking + $laneBlocking)),
                ]),
                amount: $amount,
                currency: $resolvedCurrency->value,
                gates: $gates,
                bounds: $bounds,
                blocking: $technicalBlocking + $laneBlocking,
                warnings: $warnings,
                technical: $technical,
                lane: $lane,
            );
        }

        return $this->decision(
            $normalised,
            $warnings === [] ? self::ALLOWED : self::ALLOWED_WITH_WARNINGS,
            null,
            $warnings === []
                ? trans('real_money.control.allowed', ['operation' => $normalised])
                : trans('real_money.control.allowed_with_warnings', ['operation' => $normalised, 'count' => count($warnings)]),
            amount: $amount,
            currency: $resolvedCurrency->value,
            gates: $gates,
            bounds: $bounds,
            warnings: $warnings,
            technical: $technical,
            lane: $lane,
        );
    }

    /**
     * Fail-closed assertion form. Runs the same checks in the same order and
     * throws by name on the first refusal.
     *
     * Safe to call at the top of a money path: it performs no write, so a
     * refusal leaves balances, locks and the ledger exactly as it found them.
     *
     * @throws FinancialException
     */
    public function assertAllowed(string $operation, ?string $amount = null, Currency|string|null $currency = null): void
    {
        $decision = $this->evaluate($operation, $amount, $currency);

        if ($decision['allowed'] === true) {
            return;
        }

        throw FinancialException::withCode(
            (string) ($decision['reason'] ?? 'real_money_operation_refused'),
            (string) $decision['message'],
            [
                'operation' => (string) $decision['operation'],
                'status' => (string) $decision['status'],
                'currency' => $decision['currency'],
                'amount' => $decision['amount'],
                'checks' => array_keys($decision['blocking']),
                'reasons' => $decision['blocking'],
            ],
        );
    }

    /**
     * The control surface for one operation, without running any check.
     *
     * This is what an operator needs in order to understand a verdict BEFORE
     * asking for one: which controls are enforced, which are only reported, and
     * which existing config key each bound is read from.
     *
     * @return array{
     *     operation: string|null,
     *     known: bool,
     *     modes: array<string, string>,
     *     bounds: array<string, string|null>,
     *     bound_sources: array<string, string|null>,
     *     required_checks: list<string>,
     *     lane_required: bool
     * }
     */
    public function controls(?string $operation = null): array
    {
        $normalised = $this->normaliseOperation($operation);

        return [
            'operation' => $normalised,
            'known' => $operation === null || $normalised !== null,
            'modes' => [
                'master_switch' => self::MODE_ENFORCE,
                'operation_control' => self::MODE_ENFORCE,
                'amount_bounds' => self::MODE_ENFORCE,
                'currency' => self::MODE_ENFORCE,
                'technical_readiness' => $this->mode('technical_readiness_mode'),
                'provider_lane' => $this->mode('lane_readiness_mode'),
                'license' => self::MODE_REPORT,
            ],
            // The control's live state, published on the read-only surface so an
            // operator can see a halt without first having to be refused by one.
            // `storage_available` false means the answer came from the fail-closed
            // fallback rather than from the table.
            'operation_control' => $this->shutdownControl->status(),
            'bounds' => $normalised === null ? [] : $this->bounds($normalised),
            'bound_sources' => $normalised === null ? [] : $this->boundSources($normalised),
            'required_checks' => $normalised === null
                ? RealMoneyTechnicalReadinessService::CHECKS
                : ($this->technicalReadiness->scopedIds($normalised) ?? RealMoneyTechnicalReadinessService::CHECKS),
            'lane_required' => $normalised === null ? false : in_array(
                $normalised,
                [
                    RealMoneyTechnicalReadinessService::OP_DEPOSIT,
                    RealMoneyTechnicalReadinessService::OP_WITHDRAWAL,
                    RealMoneyTechnicalReadinessService::OP_PAYOUT,
                ],
                true,
            ),
        ];
    }

    /**
     * The refusal facts, shaped for an audit row.
     *
     * Returned rather than written — see the class docblock. The caller persists
     * it AFTER its own money boundary has unwound, which is the only point at
     * which an audit row about a refusal is guaranteed to survive.
     *
     * Only scalar values are included, so a caller cannot smuggle a destination,
     * an account number, a document reference or a token into an audit row
     * through this array.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, scalar|null>
     */
    public function describeRefusal(array $decision): array
    {
        $facts = [
            'gate' => 'money_operation_control',
            'operation' => is_string($decision['operation'] ?? null) ? $decision['operation'] : null,
            'status' => is_string($decision['status'] ?? null) ? $decision['status'] : null,
            'reason_code' => is_string($decision['reason'] ?? null) ? $decision['reason'] : null,
            'currency' => is_string($decision['currency'] ?? null) ? $decision['currency'] : null,
            'amount' => is_string($decision['amount'] ?? null) ? $decision['amount'] : null,
            'refused' => ($decision['allowed'] ?? false) === true ? 'no' : 'yes',
        ];

        foreach ((array) ($decision['blocking'] ?? []) as $id => $detail) {
            // Scalars keep their type, exactly as
            // FinancialComplianceGateService::recordRefusal() does with a caller's
            // context: the metadata is JSON-encoded into an audit row, and casting
            // an int to a string here would make the row disagree with the value
            // the caller actually held. Arrays, objects and resources are dropped
            // rather than serialised, which is what stops a destination, a
            // document reference or a nested payload from reaching the row.
            if (is_string($id) && is_scalar($detail)) {
                $facts['blocking_'.$id] = $detail;
            }
        }

        return $facts;
    }

    /**
     * One control's mode, normalised. An unrecognised value falls back to
     * `report`: a typo in an env var must not silently start refusing live
     * money movements.
     */
    public function mode(string $key): string
    {
        $value = strtolower(trim((string) $this->config->get('real_money.control.'.$key, self::MODE_REPORT)));

        return $value === self::MODE_ENFORCE ? self::MODE_ENFORCE : self::MODE_REPORT;
    }

    // =====================================================================
    // Internals. All pure or read-only.
    // =====================================================================

    /**
     * @return list<string>|null  the bound config KEYS, not their values
     */
    private function boundSources(string $operation): array
    {
        $sources = (array) $this->config->get('real_money.control.bounds.'.$operation, []);

        $min = $sources['min'] ?? null;
        $max = $sources['max'] ?? null;

        return [
            'min' => is_string($min) && $min !== '' ? $min : null,
            'max' => is_string($max) && $max !== '' ? $max : null,
        ];
    }

    /**
     * The resolved bound VALUES for one operation, as decimal strings.
     *
     * A bound whose config key is absent, empty or non-numeric resolves to null,
     * which means "unbounded on this side". Nothing is invented: a silently
     * defaulted real-money ceiling looks configured while expressing nobody's
     * risk appetite.
     *
     * @return array<string, string|null>
     */
    private function bounds(string $operation): array
    {
        $sources = $this->boundSources($operation);
        $resolved = [];

        foreach (['min', 'max'] as $side) {
            $key = $sources[$side];
            $resolved[$side] = null;

            if ($key === null) {
                continue;
            }

            $value = $this->config->get($key);

            if (is_int($value) || is_float($value)) {
                $value = (string) $value;
            }

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            // A numeric decimal string only. Anything else is treated as no
            // bound rather than as zero, because a mis-typed ceiling that reads
            // as 0.00 would refuse every operation and look like a policy.
            if ($value === '' || preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $value) !== 1) {
                continue;
            }

            $resolved[$side] = $value;
        }

        return $resolved;
    }

    /**
     * @return array{limit: string, bound: string}|null
     */
    private function boundFailure(?Money $money, array $bounds, Currency $currency): ?array
    {
        if ($money === null) {
            return null;
        }

        foreach (['min', 'max'] as $side) {
            $bound = $bounds[$side] ?? null;

            if (! is_string($bound)) {
                continue;
            }

            try {
                $limit = Money::of($bound, $currency);
            } catch (Throwable) {
                // An unparseable bound is not a bound this class may enforce.
                continue;
            }

            if ($side === 'min' && $money->isLessThan($limit)) {
                return ['limit' => 'minimum', 'bound' => $limit->toString()];
            }

            if ($side === 'max' && $money->isGreaterThan($limit)) {
                return ['limit' => 'maximum', 'bound' => $limit->toString()];
            }
        }

        return null;
    }

    /**
     * The provider lane answer for one operation, scoped to a currency.
     *
     * The verdict is `ProviderReadinessService`'s. `required` is false for
     * operations that never touch a provider — a bet moves money between the
     * player wallet and the platform ledger, and a conversion moves it between
     * two pockets of the same player — so a dark gateway lane cannot refuse them.
     *
     * @return array{required: bool, ready: bool, detail: string, gateway: string|null, issues: list<string>}
     */
    private function lane(string $operation, Currency $currency): array
    {
        $required = in_array($operation, [
            RealMoneyTechnicalReadinessService::OP_DEPOSIT,
            RealMoneyTechnicalReadinessService::OP_WITHDRAWAL,
            RealMoneyTechnicalReadinessService::OP_PAYOUT,
        ], true);

        if (! $required) {
            return [
                'required' => false,
                'ready' => true,
                'detail' => 'this operation does not dispatch a provider lane',
                'gateway' => null,
                'issues' => [],
            ];
        }

        $direction = match ($operation) {
            RealMoneyTechnicalReadinessService::OP_DEPOSIT => 'deposit',
            default => 'withdrawal',
        };

        $bestName = null;
        $bestIssues = [];
        $anyReady = false;

        foreach ($this->providers->gateways() as $name => $result) {
            // Currency- and direction-scoped, so the BDT/THB market guards and
            // the bank-transfer settlement requirement are part of this answer.
            $scoped = $this->providers->gateway((string) $name, $direction, $currency);

            if (! $scoped->enabled) {
                continue;
            }

            if ($scoped->ready) {
                $anyReady = true;
                $bestName = (string) $name;
                $bestIssues = [];

                break;
            }

            // Keep the first enabled lane's issues so the detail names something
            // actionable rather than just "none ready".
            if ($bestName === null) {
                $bestName = (string) $name;
                $bestIssues = $scoped->issues;
            }
        }

        if ($anyReady) {
            return [
                'required' => true,
                'ready' => true,
                'detail' => 'a ready provider lane is configured for '.$currency->value,
                'gateway' => $bestName,
                'issues' => [],
            ];
        }

        return [
            'required' => true,
            'ready' => false,
            'detail' => $bestName === null
                ? 'no payment gateway is enabled for '.$currency->value
                : 'no enabled gateway is ready for '.$currency->value.' ('.$bestName.': '.implode(', ', $bestIssues).')',
            'gateway' => $bestName,
            'issues' => $bestIssues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decision(
        string $operation,
        string $status,
        ?string $reason,
        string $message,
        ?string $amount = null,
        ?string $currency = null,
        array $gates = [],
        array $bounds = [],
        array $blocking = [],
        array $warnings = [],
        array $technical = [],
        ?array $lane = null,
    ): array {
        return [
            'operation' => $operation,
            'allowed' => $blocking === [] && in_array($status, [self::ALLOWED, self::ALLOWED_WITH_WARNINGS], true),
            'status' => $status,
            'reason' => $reason,
            'message' => $message,
            'amount' => $amount,
            'currency' => $currency,
            'gates' => $gates,
            'modes' => [
                'master_switch' => self::MODE_ENFORCE,

                // Not mode-switchable. A control that could be downgraded to
                // `report` by configuration would not be a control: the whole
                // point of an emergency shutdown is that it halts money, and
                // there is no setting in which it merely advises.
                'operation_control' => self::MODE_ENFORCE,
                'amount_bounds' => self::MODE_ENFORCE,
                'currency' => self::MODE_ENFORCE,
                'technical_readiness' => $this->mode('technical_readiness_mode'),
                'provider_lane' => $this->mode('lane_readiness_mode'),
                'license' => self::MODE_REPORT,
            ],
            'bounds' => $bounds,
            'blocking' => $blocking,
            'warnings' => $warnings,
            'technical' => $technical,
            'lane' => $lane,
        ];
    }

    /**
     * Normalise an operation name against the ONE vocabulary the money paths
     * already use with `RealMoneyGate::assertOpen()`. Unlike the readiness
     * service this returns null rather than throwing, because `evaluate()` is a
     * read that must be able to report an unknown operation instead of dying on
     * it; `assertAllowed()` turns that report into a refusal.
     *
     * Null is accepted and means "no operation named" — which is what
     * `controls()` passes when an operator asks for the whole control surface
     * rather than one operation's share of it.
     */
    private function normaliseOperation(?string $operation): ?string
    {
        if ($operation === null) {
            return null;
        }

        $normalised = strtolower(trim($operation));

        return in_array($normalised, RealMoneyTechnicalReadinessService::OPERATIONS, true)
            ? $normalised
            : null;
    }

    private function resolveCurrency(Currency|string|null $currency): ?Currency
    {
        if ($currency instanceof Currency) {
            return $currency;
        }

        if (is_string($currency) && trim($currency) !== '') {
            return Currency::tryFromCode($currency);
        }

        return Currency::tryFromCode((string) $this->config->get('finance.currency.default', ''))
            ?? Currency::tryFromCode((string) $this->config->get('payment.currency.default', ''));
    }

    private function currencyCode(Currency|string|null $currency): ?string
    {
        if ($currency instanceof Currency) {
            return $currency->value;
        }

        if (is_string($currency) && trim($currency) !== '') {
            return strtoupper(trim($currency));
        }

        return null;
    }
}
