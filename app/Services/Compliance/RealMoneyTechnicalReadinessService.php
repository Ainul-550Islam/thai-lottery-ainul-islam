<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\WalletService;
use App\Services\License\LicenseService;
use App\Services\Operations\ProviderReadinessService;
use App\Support\Config\StrictBoolean;
use App\Support\Ops\CheckResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * The TECHNICAL half of the real-money launch decision.
 *
 * WHY THIS CLASS EXISTS, AND WHY IT IS NOT ONE OF THE OTHERS
 * ----------------------------------------------------------
 * Four questions stand between an operator flipping `REAL_MONEY_ENABLED` and a
 * player's money actually moving safely. Three of them already had exactly one
 * owner each:
 *
 *   - may this deployment move money AT ALL        -> RealMoneyGate
 *   - is the operator legally allowed to           -> LicenseService
 *   - are the provider credentials configured      -> ProviderReadinessService
 *   - is this RELEASE fit to ship                  -> ProductionPreflight
 *
 * The fourth had no owner: **is the platform technically able to carry a
 * monetary movement end to end?** A deployment can hold a valid licence, an
 * open switch and a fully credentialed gateway, and still lose money because
 * the chart of accounts was never seeded, the ledger invariants were switched
 * off, `finance.currency.default` and `payment.currency.default` drifted apart,
 * the KYC custody disk is not declared, or the money tables do not exist. None
 * of those is a licence question, a credential question or a release question.
 * This class is that question, asked once, in one place.
 *
 * READ-ONLY IS THE WHOLE CONTRACT
 * -------------------------------
 * Every check reads configuration, the schema catalogue or existing rows. This
 * class performs **no write of any kind**: no row, no cache entry, no file, no
 * audit entry, no provider call, no network I/O. That is not a style preference
 * — it is what makes the class safe to call from a boot path, from an admission
 * check inside an open database transaction, and from `--json` in CI. A
 * readiness probe that writes cannot be run twice without changing what it is
 * measuring.
 *
 * DELEGATION, NOT A SECOND OPINION
 * --------------------------------
 * The provider question is asked of `ProviderReadinessService` and reported as
 * that service answered it; this class never re-derives a credential verdict,
 * because `ProviderReadinessService` exists precisely so that "a readiness
 * answer must have exactly one owner". The licence question is asked of
 * `LicenseService::status()` — the non-throwing read — never of
 * `assertLicensed()`, because the licence gate is enforced by
 * `EnforceRealMoneyLicense` and this class must not become a second, weaker
 * copy of it. Key-shape rules mirror `ProductionPreflight` exactly rather than
 * inventing stricter or looser ones, so a release that passes preflight cannot
 * be told something different here.
 *
 * A CLOSED GATE HAS NO PROVIDER AND NO LICENCE OBLIGATION
 * -------------------------------------------------------
 * Following the `ThaiRailsChecks` idiom ("a lane that is off is not a failing
 * lane"), the checks that only matter once money can move are reported SKIP —
 * advisory, never blocking — while `RealMoneyGate::isOpen()` is false. A fresh
 * deployment therefore reports honestly ("nothing here is broken, and nothing
 * here was checked either") instead of failing a release that has not launched.
 *
 * FAIL-CLOSED ON DOUBT
 * --------------------
 * An unreachable database, an unreadable encryption key and an unparseable
 * configuration value are FAIL, not SKIP. The only way this class says ready is
 * a complete, unambiguous YES.
 *
 * DELIBERATELY UNCACHED
 * ---------------------
 * Checks are recomputed on every call. A cached readiness answer describes the
 * configuration as it was when the process booted, and `config:cache`,
 * `.env` edits and runtime `config()` writes all change the true answer. The
 * DB read is one indexed `whereIn` over the chart of accounts, so recomputation
 * is cheap enough to be worth its honesty.
 */
final class RealMoneyTechnicalReadinessService
{
    // ---------------------------------------------------------------------
    // Operations. These are the SAME operation names RealMoneyGate::assertOpen()
    // is already called with across the money paths ('deposit', 'withdrawal',
    // 'bet'), so an operator reading a refusal sees one vocabulary everywhere.
    // ---------------------------------------------------------------------
    public const OP_DEPOSIT = 'deposit';

    public const OP_WITHDRAWAL = 'withdrawal';

    public const OP_BET = 'bet';

    public const OP_PAYOUT = 'payout';

    public const OP_CONVERSION = 'conversion';

    /** @var list<string> */
    public const OPERATIONS = [
        self::OP_DEPOSIT,
        self::OP_WITHDRAWAL,
        self::OP_BET,
        self::OP_PAYOUT,
        self::OP_CONVERSION,
    ];

    // ---------------------------------------------------------------------
    // Check identifiers. Prefixed `real_money.` so they cannot collide with the
    // `thai_rails.*`, `app.*` and `field_encryption.*` ids ProductionPreflight
    // publishes, and so one merged report stays unambiguous.
    // ---------------------------------------------------------------------
    public const CHECK_GATE_SWITCH = 'real_money.gate_switch';

    public const CHECK_LICENSE_CONTRACT = 'real_money.license_contract';

    /**
     * Does THIS environment permit the lanes that are switched on?
     *
     * A live provider lane enabled under local, testing or staging is a
     * configuration breach, not a style preference: those environments must
     * move no real provider money. A lane that declares no sandbox marker at
     * all cannot be proven to be a test double either, but that is reported
     * as advisory, because most of this repository's lanes predate the marker
     * and failing them would fail every release that has not launched.
     */
    public const CHECK_ENVIRONMENT_ISOLATION = 'real_money.environment_isolation';

    /**
     * Are the money switches strict booleans?
     *
     * `RealMoneyGate::isOpen()` accepts only the literal `true`, so a mis-typed
     * switch fails closed at the gate. That is the right behaviour and a
     * terrible diagnosis: the deployment simply refuses money and nothing says
     * why. This check names the defect instead - a value that was written as
     * 'yes', '1', a blank string or an array is reported with its type, never
     * with its contents.
     */
    public const CHECK_CONFIGURATION_STRICTNESS = 'real_money.configuration_strictness';

    public const CHECK_APPLICATION_KEY = 'real_money.application_key';

    public const CHECK_FIELD_ENCRYPTION_KEY = 'real_money.field_encryption_key';

    public const CHECK_CURRENCY_CONTRACT = 'real_money.currency_contract';

    public const CHECK_LEDGER_INVARIANTS = 'real_money.ledger_invariants';

    public const CHECK_DATABASE_SCHEMA = 'real_money.database_schema';

    public const CHECK_CHART_OF_ACCOUNTS = 'real_money.chart_of_accounts';

    /**
     * Do the services that actually move money still exist and load?
     *
     * Existence only - never instantiation. Resolving a financial service can
     * open a database transaction, bind a provider client or write a log, and a
     * readiness evaluator is required to be read-only. A class that was renamed
     * or deleted leaves the application bootable and every monetary write fatal
     * at the first request, which is exactly the failure this names in advance.
     */
    public const CHECK_FINANCIAL_SERVICES = 'real_money.financial_services';

    public const CHECK_UPLOAD_CUSTODY_DISKS = 'real_money.upload_custody_disks';

    public const CHECK_QUEUE_DRIVER = 'real_money.queue_driver';

    public const CHECK_SESSION_DRIVER = 'real_money.session_driver';

    /**
     * Can an inbound provider notification be trusted?
     *
     * Signature verification, replay protection, receipt persistence and an
     * age bound are what stand between an attacker and a free deposit. Every
     * one of them is configuration, so every one of them can be switched off by
     * a deploy manifest nobody reviews.
     */
    public const CHECK_WEBHOOK_SECURITY = 'real_money.webhook_security';

    /**
     * Can a retried request double-credit a player?
     *
     * Providers retry. Idempotency is the only thing that makes a retry safe,
     * and it depends on a key, a TTL and - easily missed - a cache store that
     * survives the request. An `array` cache store in production discards every
     * idempotency key the moment the worker recycles, which turns a duplicate
     * delivery into a duplicate deposit.
     */
    public const CHECK_IDEMPOTENCY = 'real_money.idempotency';

    public const CHECK_PROVIDER_LANES = 'real_money.provider_lanes';

    /**
     * Every check id, in report order. Fixed order, not discovery order: an
     * operator diffing two runs needs the same line in the same place.
     *
     * @var list<string>
     */
    public const CHECKS = [
        self::CHECK_GATE_SWITCH,
        self::CHECK_LICENSE_CONTRACT,
        self::CHECK_ENVIRONMENT_ISOLATION,
        self::CHECK_CONFIGURATION_STRICTNESS,
        self::CHECK_APPLICATION_KEY,
        self::CHECK_FIELD_ENCRYPTION_KEY,
        self::CHECK_CURRENCY_CONTRACT,
        self::CHECK_LEDGER_INVARIANTS,
        self::CHECK_DATABASE_SCHEMA,
        self::CHECK_CHART_OF_ACCOUNTS,
        self::CHECK_FINANCIAL_SERVICES,
        self::CHECK_UPLOAD_CUSTODY_DISKS,
        self::CHECK_QUEUE_DRIVER,
        self::CHECK_SESSION_DRIVER,
        self::CHECK_WEBHOOK_SECURITY,
        self::CHECK_IDEMPOTENCY,
        self::CHECK_PROVIDER_LANES,
    ];

    /**
     * Which checks decide one operation. Anything not listed here is still
     * reported — an operator auditing `deposit` benefits from seeing the payout
     * lane too — but only these decide `readyForOperation()`.
     *
     * @var array<string, list<string>>
     */
    private const OPERATION_CHECKS = [
        self::OP_DEPOSIT => [
            self::CHECK_GATE_SWITCH,
            self::CHECK_LICENSE_CONTRACT,
            self::CHECK_ENVIRONMENT_ISOLATION,
            self::CHECK_CONFIGURATION_STRICTNESS,
            self::CHECK_APPLICATION_KEY,
            self::CHECK_CURRENCY_CONTRACT,
            self::CHECK_LEDGER_INVARIANTS,
            self::CHECK_DATABASE_SCHEMA,
            self::CHECK_CHART_OF_ACCOUNTS,
            self::CHECK_FINANCIAL_SERVICES,
            self::CHECK_WEBHOOK_SECURITY,
            self::CHECK_IDEMPOTENCY,
            self::CHECK_PROVIDER_LANES,
        ],
        self::OP_WITHDRAWAL => [
            self::CHECK_GATE_SWITCH,
            self::CHECK_LICENSE_CONTRACT,
            self::CHECK_ENVIRONMENT_ISOLATION,
            self::CHECK_CONFIGURATION_STRICTNESS,
            self::CHECK_APPLICATION_KEY,
            self::CHECK_CURRENCY_CONTRACT,
            self::CHECK_LEDGER_INVARIANTS,
            self::CHECK_DATABASE_SCHEMA,
            self::CHECK_CHART_OF_ACCOUNTS,
            self::CHECK_FINANCIAL_SERVICES,
            self::CHECK_QUEUE_DRIVER,
            self::CHECK_WEBHOOK_SECURITY,
            self::CHECK_IDEMPOTENCY,
            self::CHECK_PROVIDER_LANES,
        ],
        self::OP_BET => [
            self::CHECK_GATE_SWITCH,
            self::CHECK_LICENSE_CONTRACT,
            self::CHECK_ENVIRONMENT_ISOLATION,
            self::CHECK_CONFIGURATION_STRICTNESS,
            self::CHECK_APPLICATION_KEY,
            self::CHECK_CURRENCY_CONTRACT,
            self::CHECK_LEDGER_INVARIANTS,
            self::CHECK_DATABASE_SCHEMA,
            self::CHECK_CHART_OF_ACCOUNTS,
            self::CHECK_FINANCIAL_SERVICES,
            self::CHECK_IDEMPOTENCY,
        ],
        self::OP_PAYOUT => [
            self::CHECK_GATE_SWITCH,
            self::CHECK_LICENSE_CONTRACT,
            self::CHECK_ENVIRONMENT_ISOLATION,
            self::CHECK_CONFIGURATION_STRICTNESS,
            self::CHECK_APPLICATION_KEY,
            self::CHECK_FIELD_ENCRYPTION_KEY,
            self::CHECK_CURRENCY_CONTRACT,
            self::CHECK_LEDGER_INVARIANTS,
            self::CHECK_DATABASE_SCHEMA,
            self::CHECK_CHART_OF_ACCOUNTS,
            self::CHECK_FINANCIAL_SERVICES,
            self::CHECK_UPLOAD_CUSTODY_DISKS,
            self::CHECK_QUEUE_DRIVER,
            self::CHECK_SESSION_DRIVER,
            self::CHECK_WEBHOOK_SECURITY,
            self::CHECK_IDEMPOTENCY,
            self::CHECK_PROVIDER_LANES,
        ],
        self::OP_CONVERSION => [
            self::CHECK_GATE_SWITCH,
            self::CHECK_ENVIRONMENT_ISOLATION,
            self::CHECK_CONFIGURATION_STRICTNESS,
            self::CHECK_APPLICATION_KEY,
            self::CHECK_CURRENCY_CONTRACT,
            self::CHECK_LEDGER_INVARIANTS,
            self::CHECK_DATABASE_SCHEMA,
            self::CHECK_CHART_OF_ACCOUNTS,
            self::CHECK_FINANCIAL_SERVICES,
            self::CHECK_IDEMPOTENCY,
        ],
    ];

    /**
     * The money tables. A migration that silently failed leaves the application
     * bootable and every monetary write fatal; naming the tables turns that
     * into one readable line before a player ever reaches it.
     *
     * @var list<string>
     */
    private const REQUIRED_TABLES = [
        'wallets',
        'ledger_accounts',
        'ledger_entries',
        'financial_transactions',
        'financial_holds',
        'deposits',
        'withdrawals',
        'payments',
        'payment_intents',
        'audit_logs',
    ];

    /**
     * Disks the KYC / upload-custody lane writes to. `UploadQuarantineService`
     * and `UploadScanService` resolve these by name; a disk that is not declared
     * in `config/filesystems.php` throws at the moment a document is uploaded,
     * which is the moment a withdrawal is waiting on KYC.
     *
     * @var list<string>
     */
    private const CUSTODY_DISKS = ['quarantine', 'private'];

    /**
     * Session drivers that can hold a transactional session. The password-logout
     * policy and the bound-session middleware both need a driver whose store
     * participates in a transaction; `array` and `file` do not.
     *
     * @var list<string>
     */
    private const TRANSACTIONAL_SESSION_DRIVERS = ['database', 'redis'];

    /**
     * Environments that may never open live money operations, whatever any
     * switch says. This is a property of the environment, not of configuration:
     * `REAL_MONEY_ENABLED=true` in a testing container is a mistake, and the
     * report says so instead of moving money.
     *
     * @var list<string>
     */
    private const NON_PRODUCTION_ENVIRONMENTS = ['local', 'testing', 'staging'];

    /**
     * The rule each environment is held to, published alongside the verdict so
     * an operator can see the standard being applied and not only the score.
     *
     * @var array<string, string>
     */
    private const ENVIRONMENT_RULES = [
        'local' => 'no real provider money movement; sandbox and test doubles only',
        'testing' => 'no real provider money movement; deterministic fixtures only',
        'staging' => 'no production credentials; sandbox integration only; no real financial settlement',
        'production' => 'technical readiness is required but never sufficient; live activation is separately controlled and deliberately unavailable in this phase',
    ];

    /**
     * The readiness flags this evaluator publishes, and the checks that decide
     * each one.
     *
     * A flag is TRUE only when every check in its group was evaluated and none
     * of them failed. SKIP therefore reads as "not demonstrated" rather than
     * "fine": while the master switch is closed the licence and provider-lane
     * checks are deliberately not evaluated, and claiming a provider lane is
     * ready on the strength of an unevaluated check is how a launch gets
     * approved on evidence that was never gathered.
     *
     * WARN does not clear a flag to false. An advisory finding is something an
     * operator should read, not a reason to declare an area unready - the same
     * distinction `technical_ready` already draws, kept so the two answers can
     * never contradict each other.
     *
     * @var array<string, list<string>>
     */
    private const READINESS_GROUPS = [
        'configuration_valid' => [
            self::CHECK_CONFIGURATION_STRICTNESS,
            self::CHECK_APPLICATION_KEY,
            self::CHECK_FIELD_ENCRYPTION_KEY,
            self::CHECK_SESSION_DRIVER,
        ],
        'database_ready' => [
            self::CHECK_DATABASE_SCHEMA,
        ],
        'ledger_schema_ready' => [
            self::CHECK_LEDGER_INVARIANTS,
            self::CHECK_CHART_OF_ACCOUNTS,
        ],
        'financial_services_ready' => [
            self::CHECK_FINANCIAL_SERVICES,
            self::CHECK_UPLOAD_CUSTODY_DISKS,
        ],
        'provider_configuration_ready' => [
            self::CHECK_PROVIDER_LANES,
            self::CHECK_ENVIRONMENT_ISOLATION,
        ],
        'currency_configuration_ready' => [
            self::CHECK_CURRENCY_CONTRACT,
        ],
        'queue_configuration_ready' => [
            self::CHECK_QUEUE_DRIVER,
        ],
        'webhook_security_ready' => [
            self::CHECK_WEBHOOK_SECURITY,
        ],
        'idempotency_ready' => [
            self::CHECK_IDEMPOTENCY,
        ],
    ];

    /**
     * Classes that must exist for money to move at all.
     *
     * Checked with `class_exists()` and never instantiated: a readiness
     * evaluator that resolves a financial service can open a transaction, bind a
     * provider client or write a log, and this one is required to be read-only.
     *
     * @var list<class-string>
     */
    private const FINANCIAL_SERVICE_CLASSES = [
        'App\Services\Finance\Money',
        'App\Services\Finance\WalletService',
        'App\Services\Finance\LedgerPostingService',
        'App\Services\Finance\DepositService',
        'App\Services\Finance\WithdrawalService',
        'App\Services\Deposit\DepositProcessingService',
        'App\Services\Payment\PaymentIntentService',
        'App\Services\Payment\DepositCheckoutService',
        'App\Services\Payment\PayoutTransferService',
        'App\Services\Compliance\FinancialComplianceGateService',
        'App\Services\Operations\ProviderReadinessService',
    ];

    /**
     * Configuration switches that govern money, and must therefore hold a real
     * boolean rather than a value that a loose cast would happily read as one.
     *
     * Gateway switches are discovered from `payment.gateways` at evaluation time
     * rather than listed here, so a gateway added to configuration is audited
     * without this list having to be remembered.
     *
     * @var list<string>
     */
    private const MONEY_SWITCH_PATHS = [
        'real_money.enabled',
        'real_money.require_readiness_pass',
        'glo.l6.purchase.enabled',
        'payment.deposit.enabled',
        'payment.deposit.auto_confirm',
        'payment.withdrawal.enabled',
        'payment.withdrawal_routing.prefer_automated_payout',
        'payment.idempotency.enabled',
        'payment.webhook.verify_signature',
        'payment.webhook.replay_protection',
        'payment.webhook.persist_receipts',
        'payment.webhook.require_https',
    ];

    public function __construct(
        private readonly RealMoneyGate $realMoneyGate,
        private readonly LicenseService $license,
        private readonly ProviderReadinessService $providers,
        private readonly MoneyShutdownControlService $shutdownControl,
    ) {}

    /**
     * Every check, keyed by its identifier, in `CHECKS` order.
     *
     * @return array<string, CheckResult>
     */
    public function checks(): array
    {
        $gateOpen = $this->realMoneyGate->isOpen();
        $production = app()->environment('production');

        $results = [
            self::CHECK_GATE_SWITCH => $this->checkGateSwitch(),
            self::CHECK_LICENSE_CONTRACT => $this->checkLicenseContract($gateOpen),
            self::CHECK_ENVIRONMENT_ISOLATION => $this->checkEnvironmentIsolation(),
            self::CHECK_CONFIGURATION_STRICTNESS => $this->checkConfigurationStrictness(),
            self::CHECK_APPLICATION_KEY => $this->checkApplicationKey(),
            self::CHECK_FIELD_ENCRYPTION_KEY => $this->checkFieldEncryptionKey($production),
            self::CHECK_CURRENCY_CONTRACT => $this->checkCurrencyContract(),
            self::CHECK_LEDGER_INVARIANTS => $this->checkLedgerInvariants(),
            self::CHECK_DATABASE_SCHEMA => $this->checkDatabaseSchema(),
            self::CHECK_CHART_OF_ACCOUNTS => $this->checkChartOfAccounts(),
            self::CHECK_FINANCIAL_SERVICES => $this->checkFinancialServices(),
            self::CHECK_UPLOAD_CUSTODY_DISKS => $this->checkUploadCustodyDisks($gateOpen),
            self::CHECK_QUEUE_DRIVER => $this->checkQueueDriver($production),
            self::CHECK_SESSION_DRIVER => $this->checkSessionDriver($production),
            self::CHECK_WEBHOOK_SECURITY => $this->checkWebhookSecurity($gateOpen, $production),
            self::CHECK_IDEMPOTENCY => $this->checkIdempotency($production),
            self::CHECK_PROVIDER_LANES => $this->checkProviderLanes($gateOpen),
        ];

        // Re-key into the published order so the report cannot depend on the
        // order the private methods happened to be called in.
        $ordered = [];
        foreach (self::CHECKS as $id) {
            if (isset($results[$id])) {
                $ordered[$id] = $results[$id];
            }
        }

        return $ordered;
    }

    /**
     * The full report, optionally scoped to one operation.
     *
     * The shape mirrors `ProductionPreflight::run()` (`status`, `environment`,
     * `checks`, `summary`) so an operator tool that already parses one can parse
     * the other. `operation` and `blocking` are added, never substituted.
     *
     * The keys `status`, `environment`, `operation`, `real_money_enabled`,
     * `ready`, `checks`, `summary` and `blocking` are the original contract and
     * are unchanged. Everything else was ADDED, never substituted, so a caller
     * written against the earlier shape keeps working:
     *
     *   technical_ready             same verdict as `ready`, named for the
     *                               launch decision it feeds;
     *   live_operations_open        always false in this phase, and false in
     *                               local/testing/staging whatever any switch
     *                               says. Technical readiness is evidence, not
     *                               authority: a PASS here never opens anything;
     *   live_operation_blockers     the ordered reasons it is closed, so the
     *                               answer carries its own justification;
     *   environment_rule            the rule this environment is held to;
     *   <area>_ready / _valid       one flag per readiness area, each true only
     *                               when every check deciding it was evaluated
     *                               and none failed;
     *   missing_requirements        every non-PASS check as an actionable line;
     *   blocking_reasons            the failure messages, as a plain list.
     *
     * @return array{
     *     status: string,
     *     environment: string,
     *     operation: string|null,
     *     real_money_enabled: bool,
     *     ready: bool,
     *     technical_ready: bool,
     *     live_operations_open: bool,
     *     live_operation_blockers: list<string>,
     *     environment_rule: string,
     *     configuration_valid: bool,
     *     database_ready: bool,
     *     ledger_schema_ready: bool,
     *     financial_services_ready: bool,
     *     provider_configuration_ready: bool,
     *     currency_configuration_ready: bool,
     *     queue_configuration_ready: bool,
     *     webhook_security_ready: bool,
     *     idempotency_ready: bool,
     *     missing_requirements: list<string>,
     *     blocking_reasons: list<string>,
     *     checks: list<array{id: string, status: string, detail: string}>,
     *     summary: array{pass: int, warn: int, fail: int, skip: int},
     *     blocking: array<string, string>
     * }
     */
    public function report(?string $operation = null): array
    {
        $operation = $this->normaliseOperation($operation);
        $checks = $this->checks();

        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];
        $lines = [];
        foreach ($checks as $id => $check) {
            $summary[strtolower($check->status)]++;
            $lines[] = $check->toArray();
        }

        $blocking = $this->blockingFailures($operation);
        $scoped = $this->scopedIds($operation);

        // A null scope means "the whole estate", so every check is in scope. The
        // list is still built explicitly rather than reusing `$blocking`, because
        // the two must stay in the published check order and `$blocking` is
        // already keyed by whichever checks actually failed.
        $scopedFailures = [];
        foreach ($scoped ?? array_keys($checks) as $id) {
            if (isset($checks[$id]) && $checks[$id]->isFailure()) {
                $scopedFailures[$id] = $checks[$id]->message;
            }
        }

        $environment = strtolower(trim((string) config('app.env', '')));
        $flags = $this->readinessFlags($checks);

        // `live_operations_open` is a fact about the DEPLOYMENT, not about the
        // operation this report happens to be scoped to, so it is always derived
        // from the unscoped verdict. When the report is already unscoped that is
        // the value in hand and nothing is recomputed.
        $deploymentBlocking = $scoped === null ? $blocking : $this->blockingFailures();
        $liveBlockers = $this->describeLiveOperationBlockers($deploymentBlocking === [], count($deploymentBlocking));

        return [
            'status' => $summary['fail'] > 0 ? CheckResult::FAIL : ($summary['warn'] > 0 ? CheckResult::WARN : CheckResult::PASS),
            'environment' => (string) config('app.env'),
            'operation' => $operation,
            'real_money_enabled' => $this->realMoneyGate->isOpen(),
            'ready' => $blocking === [],
            'technical_ready' => $blocking === [],
            'live_operations_open' => $liveBlockers === [],
            'live_operation_blockers' => $liveBlockers,
            'environment_rule' => self::ENVIRONMENT_RULES[$environment]
                ?? 'unrecognised environment; treated as untrusted and never permitted to open live money operations',
            'configuration_valid' => $flags['configuration_valid'],
            'database_ready' => $flags['database_ready'],
            'ledger_schema_ready' => $flags['ledger_schema_ready'],
            'financial_services_ready' => $flags['financial_services_ready'],
            'provider_configuration_ready' => $flags['provider_configuration_ready'],
            'currency_configuration_ready' => $flags['currency_configuration_ready'],
            'queue_configuration_ready' => $flags['queue_configuration_ready'],
            'webhook_security_ready' => $flags['webhook_security_ready'],
            'idempotency_ready' => $flags['idempotency_ready'],
            'missing_requirements' => $this->missingRequirements($checks),
            'blocking_reasons' => array_values($scoped === null ? $blocking : $scopedFailures),
            'checks' => $lines,
            'summary' => $summary,
            'blocking' => $scoped === null ? $blocking : $scopedFailures,
        ];
    }

    /**
     * Is the platform technically able to move money at all?
     *
     * True only when no evaluated check is FAIL. WARN and SKIP do not block:
     * a dark lane and an advisory finding are things an operator should read,
     * not reasons to refuse a player.
     */
    public function ready(): bool
    {
        return $this->blockingFailures() === [];
    }

    /**
     * Is the platform technically able to carry THIS operation?
     *
     * An unknown operation name refuses rather than silently answering for the
     * whole estate: a caller that misspells 'deposit' as 'deposits' must not
     * receive a broader, weaker answer than it asked for.
     *
     * @throws FinancialException real_money_operation_unknown
     */
    public function readyForOperation(string $operation): bool
    {
        return $this->blockingFailures($operation) === [];
    }

    /**
     * The FAIL verdicts that block, keyed by check id.
     *
     * @return array<string, string>
     *
     * @throws FinancialException real_money_operation_unknown
     */
    public function blockingFailures(?string $operation = null): array
    {
        $operation = $this->normaliseOperation($operation);
        $checks = $this->checks();
        $scoped = $this->scopedIds($operation);

        $blocking = [];
        foreach ($checks as $id => $check) {
            if (! $check->isFailure()) {
                continue;
            }
            if ($scoped !== null && ! in_array($id, $scoped, true)) {
                continue;
            }
            $blocking[$id] = $check->message;
        }

        return $blocking;
    }

    /**
     * Fail-closed assertion form, for callers that must not continue.
     *
     * Throws BEFORE any balance is read or written, matching the contract
     * `RealMoneyGate::assertOpen()` already publishes, so a refused operation
     * leaves balances, locks and the ledger exactly as it found them.
     *
     * @throws FinancialException real_money_technical_readiness_failed
     * @throws FinancialException real_money_operation_unknown
     */
    public function assertTechnicallyReady(string $operation): void
    {
        $blocking = $this->blockingFailures($operation);

        if ($blocking !== []) {
            throw FinancialException::withCode(
                'real_money_technical_readiness_failed',
                trans('real_money.technical.assert_failed', ['operation' => $operation]),
                [
                    'operation' => $operation,
                    'checks' => array_keys($blocking),
                    'reasons' => $blocking,
                ],
            );
        }
    }

    /**
     * The check ids that decide one operation, or null for "all of them".
     *
     * @return list<string>|null
     */
    public function scopedIds(?string $operation): ?array
    {
        $operation = $this->normaliseOperation($operation);

        return $operation === null ? null : (self::OPERATION_CHECKS[$operation] ?? self::CHECKS);
    }

    // =====================================================================
    // The checks. Each one is private, returns a CheckResult, and reads only.
    // =====================================================================

    /**
     * The master switch must be a real boolean.
     *
     * `RealMoneyGate::isOpen()` accepts only the literal `true`, so a truthy
     * string arriving through a mis-typed override leaves the gate closed — safe,
     * but invisible: the operator believes they enabled real money and nothing
     * happens. Naming that state is the whole point of this check.
     */
    private function checkGateSwitch(): CheckResult
    {
        $raw = config('real_money.enabled', false);

        if ($raw === true) {
            return CheckResult::pass(self::CHECK_GATE_SWITCH, 'real money is OPEN (real_money.enabled === true)');
        }

        if ($raw === false) {
            return CheckResult::pass(self::CHECK_GATE_SWITCH, 'real money is CLOSED (real_money.enabled === false); no monetary movement is possible');
        }

        // Neither boolean. isOpen() refuses it, so this is a configuration
        // defect rather than an open gate — reported as WARN because the safe
        // verdict already holds, and because an operator must still fix it.
        return CheckResult::warn(
            self::CHECK_GATE_SWITCH,
            sprintf('real_money.enabled holds %s, not a boolean; the gate treats it as CLOSED', get_debug_type($raw)),
        );
    }

    /**
     * The licence contract, asked of `LicenseService::status()` — the read, not
     * the assertion. Enforcement stays with `EnforceRealMoneyLicense`; this line
     * only tells an operator whether the fields behind that middleware are there.
     */
    private function checkLicenseContract(bool $gateOpen): CheckResult
    {
        if (! $gateOpen) {
            return CheckResult::skip(self::CHECK_LICENSE_CONTRACT, 'real money is closed; no licence obligation is evaluated');
        }

        try {
            $status = $this->license->status();
        } catch (Throwable) {
            // Fail closed on doubt: an unreadable licence state is not a licence.
            return CheckResult::fail(self::CHECK_LICENSE_CONTRACT, 'licence status could not be read');
        }

        if (($status['licensed'] ?? false) === true) {
            return CheckResult::pass(self::CHECK_LICENSE_CONTRACT, 'licence contract is complete and unexpired');
        }

        $reason = (string) ($status['reason'] ?? 'unknown');

        return CheckResult::fail(self::CHECK_LICENSE_CONTRACT, 'licence gate refuses: '.$reason);
    }

    /**
     * APP_KEY shape. Mirrors `ProductionPreflight::checkApplicationKey()` so the
     * two reports cannot disagree about the same key.
     */
    private function checkApplicationKey(): CheckResult
    {
        $key = (string) config('app.key', '');

        if ($key === '') {
            return CheckResult::fail(self::CHECK_APPLICATION_KEY, 'APP_KEY is empty; encrypted sessions, cookies and custodied checkout responses cannot be produced');
        }

        $decoded = Str::startsWith($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($decoded === false || strlen((string) $decoded) !== 32) {
            return CheckResult::fail(self::CHECK_APPLICATION_KEY, 'APP_KEY is present but is not a 32-byte key (base64: or raw)');
        }

        // The key being well shaped is not the same as the encrypter working.
        // DepositCheckoutService calls Crypt::getKey() before it commits
        // permission to dispatch, so proving it here proves the same thing.
        try {
            Crypt::getKey();
        } catch (Throwable) {
            return CheckResult::fail(self::CHECK_APPLICATION_KEY, 'APP_KEY is well shaped but the encrypter could not derive a key');
        }

        return CheckResult::pass(self::CHECK_APPLICATION_KEY, 'APP_KEY is a usable 32-byte key');
    }

    /**
     * FIELD_ENCRYPTION_KEY must exist and differ from APP_KEY. Same severity
     * split as `ProductionPreflight::checkFieldEncryptionKey()`: blocking in
     * production, advisory before it.
     */
    private function checkFieldEncryptionKey(bool $production): CheckResult
    {
        $fieldKey = $this->encryptionKeyMaterial(config('app.field_encryption_key'));
        $appKey = $this->encryptionKeyMaterial(config('app.key'));
        $missing = $fieldKey === null;
        $sameAsAppKey = $fieldKey !== null && $appKey !== null && hash_equals($fieldKey, $appKey);

        if (! $missing && ! $sameAsAppKey) {
            return CheckResult::pass(self::CHECK_FIELD_ENCRYPTION_KEY, 'a distinct FIELD_ENCRYPTION_KEY is configured');
        }

        $detail = $missing
            ? 'FIELD_ENCRYPTION_KEY is empty; custodied payment fields cannot be encrypted at rest'
            : 'FIELD_ENCRYPTION_KEY is identical to APP_KEY; rotating one silently rotates the other';

        return $production
            ? CheckResult::fail(self::CHECK_FIELD_ENCRYPTION_KEY, $detail)
            : CheckResult::warn(self::CHECK_FIELD_ENCRYPTION_KEY, $detail.' (required before production)');
    }

    /**
     * The currency contract.
     *
     * Money in this platform is a decimal string plus a `Currency`. Three things
     * must hold or a movement is refused deep inside the posting layer with a
     * message that does not name the real cause:
     *   1. the default currency resolves to a supported `Currency` case;
     *   2. `finance.currency.default` and `payment.currency.default` agree —
     *      both read FINANCE_DEFAULT_CURRENCY, so a split means a runtime
     *      override moved one of them, which is exactly the drift that makes a
     *      deposit admit in THB and post in BDT;
     *   3. every declared supported currency resolves to a `Currency` case, so
     *      no wallet can be opened in a code the enum does not know.
     */
    private function checkCurrencyContract(): CheckResult
    {
        $financeDefault = strtoupper(trim((string) config('finance.currency.default', '')));
        $paymentDefault = strtoupper(trim((string) config('payment.currency.default', '')));
        $financeSupported = $this->normaliseCodes(config('finance.currency.supported'));
        $paymentSupported = $this->normaliseCodes(config('payment.currency.supported'));

        $problems = [];

        if (Currency::tryFromCode($financeDefault) === null) {
            $problems[] = 'finance.currency.default is not a supported currency';
        }

        if ($financeDefault !== $paymentDefault) {
            $problems[] = sprintf('finance.currency.default (%s) and payment.currency.default (%s) disagree', $financeDefault, $paymentDefault);
        }

        if ($financeSupported !== [] && ! in_array($financeDefault, $financeSupported, true)) {
            $problems[] = 'the default currency is absent from finance.currency.supported';
        }

        foreach ($financeSupported as $code) {
            if (Currency::tryFromCode($code) === null) {
                $problems[] = 'finance.currency.supported lists '.$code.', which is not a Currency case';
            }
        }

        foreach ($paymentSupported as $code) {
            if (Currency::tryFromCode($code) === null) {
                $problems[] = 'payment.currency.supported lists '.$code.', which is not a Currency case';
            }
        }

        // Two supported lists that disagree mean the finance lane and the payment
        // lane offer different currencies for the same wallet.
        if ($financeSupported !== [] && $paymentSupported !== [] && $financeSupported !== $paymentSupported) {
            $problems[] = 'finance.currency.supported and payment.currency.supported list different currencies';
        }

        $problems = array_values(array_unique($problems));

        return $problems === []
            ? CheckResult::pass(self::CHECK_CURRENCY_CONTRACT, 'currency contract is consistent (default '.$financeDefault.', supported '.implode('/', $financeSupported).')')
            : CheckResult::fail(self::CHECK_CURRENCY_CONTRACT, implode('; ', $problems));
    }

    /**
     * The double-entry invariants `config/finance.php` declares.
     *
     * These are booleans, so turning one off is a single-character edit that no
     * migration, no test and no provider credential would notice — and it is the
     * difference between a ledger that must balance and one that merely records.
     */
    private function checkLedgerInvariants(): CheckResult
    {
        $required = [
            'finance.ledger.double_entry' => true,
            'finance.ledger.immutable_entries' => true,
            'finance.ledger.require_balanced_entries' => true,
        ];

        $problems = [];
        foreach ($required as $key => $expected) {
            if (config($key) !== $expected) {
                $problems[] = $key.' must be '.var_export($expected, true);
            }
        }

        // A tolerance above zero lets an unbalanced posting settle quietly.
        $tolerance = (string) config('finance.ledger.balance_tolerance', '0.00');
        if (bccomp($tolerance, '0.00', 8) !== 0) {
            $problems[] = 'finance.ledger.balance_tolerance must be 0.00, found '.$tolerance;
        }

        $precision = (int) config('finance.precision.amount', 0);
        if ($precision < 2) {
            $problems[] = 'finance.precision.amount must be at least 2, found '.$precision;
        }

        $problems = array_values(array_unique($problems));

        return $problems === []
            ? CheckResult::pass(self::CHECK_LEDGER_INVARIANTS, 'double-entry, immutability and balanced-posting invariants are all enforced')
            : CheckResult::fail(self::CHECK_LEDGER_INVARIANTS, implode('; ', $problems));
    }

    /**
     * The money tables exist.
     *
     * An unreachable database is FAIL, not SKIP: "we could not tell" is not a
     * readiness answer, and a deployment whose database is down must not report
     * itself ready to move money.
     */
    private function checkDatabaseSchema(): CheckResult
    {
        $missing = [];

        try {
            foreach (self::REQUIRED_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            }
        } catch (Throwable) {
            return CheckResult::fail(self::CHECK_DATABASE_SCHEMA, 'the database could not be reached; schema readiness is unknown and therefore refused');
        }

        return $missing === []
            ? CheckResult::pass(self::CHECK_DATABASE_SCHEMA, 'all '.count(self::REQUIRED_TABLES).' monetary tables are present')
            : CheckResult::fail(self::CHECK_DATABASE_SCHEMA, 'missing monetary tables: '.implode(', ', $missing));
    }

    /**
     * The chart of accounts holds every code the application posts to, for every
     * supported currency.
     *
     * The codes are read from `WalletService::ACCOUNT_*` by reflection rather
     * than listed again here, for the same reason `LedgerAccountSeeder` keys its
     * entries on those constants: two spellings of the same chart drift apart
     * silently, and the failure surfaces as `ledger_account_missing` on the first
     * real deposit. Multi-currency codes come from
     * `LedgerPostingService::accountCodeFor()`, the resolver the posting layer
     * itself calls, so this check and the posting layer cannot disagree.
     *
     * Soft-deleted accounts are counted as missing: `LedgerAccount` uses
     * SoftDeletes, and a deleted account cannot be posted to.
     */
    private function checkChartOfAccounts(): CheckResult
    {
        $codes = $this->walletAccountCodes();

        if ($codes === []) {
            return CheckResult::fail(self::CHECK_CHART_OF_ACCOUNTS, 'no WalletService::ACCOUNT_* constants were found; the posting layer has no chart to resolve');
        }

        $supported = $this->supportedCurrencies();

        if ($supported === []) {
            return CheckResult::fail(self::CHECK_CHART_OF_ACCOUNTS, 'no supported currency resolves to a Currency case; the chart cannot be enumerated');
        }

        $expected = [];
        foreach ($supported as $currency) {
            foreach ($codes as $code) {
                $expected[] = LedgerPostingService::accountCodeFor($code, $currency);
            }
        }
        $expected = array_values(array_unique($expected));

        try {
            /** @var list<string> $present */
            $present = LedgerAccount::query()
                ->whereIn('code', $expected)
                ->where('is_active', true)
                ->pluck('code')
                ->all();
        } catch (Throwable) {
            return CheckResult::fail(self::CHECK_CHART_OF_ACCOUNTS, 'the chart of accounts could not be read; ledger readiness is unknown and therefore refused');
        }

        $missing = array_values(array_diff($expected, array_map('strval', $present)));

        return $missing === []
            ? CheckResult::pass(self::CHECK_CHART_OF_ACCOUNTS, sprintf('all %d chart-of-accounts codes are seeded and active across %d currencies', count($expected), count($supported)))
            : CheckResult::fail(self::CHECK_CHART_OF_ACCOUNTS, sprintf('%d chart-of-accounts code(s) missing or inactive: %s. Seed the chart of accounts before opening real money.', count($missing), $this->truncateList($missing)));
    }

    /**
     * The KYC / upload-custody disks are declared.
     *
     * `UploadQuarantineService` and `UploadScanService` resolve
     * `Storage::disk('quarantine')` and fall back to `'private'`. Neither name is
     * a Laravel default, so a `config/filesystems.php` that does not declare them
     * throws `Disk [quarantine] does not have a configured driver` at the moment a
     * document is uploaded — which, on the payout lane, is the moment a withdrawal
     * is waiting on KYC. Blocking only once the gate is open: a deployment that
     * moves no money has no custody obligation yet, and reporting that as SKIP
     * would hide it, so it is WARN.
     */
    private function checkUploadCustodyDisks(bool $gateOpen): CheckResult
    {
        $disks = array_keys((array) config('filesystems.disks', []));
        $missing = array_values(array_diff(self::CUSTODY_DISKS, $disks));

        if ($missing === []) {
            return CheckResult::pass(self::CHECK_UPLOAD_CUSTODY_DISKS, 'upload custody disks are declared ('.implode(', ', self::CUSTODY_DISKS).')');
        }

        $detail = 'upload custody disk(s) not declared in config/filesystems.php: '.implode(', ', $missing)
            .' — the KYC document quarantine and scan lane cannot resolve them';

        return $gateOpen
            ? CheckResult::fail(self::CHECK_UPLOAD_CUSTODY_DISKS, $detail)
            : CheckResult::warn(self::CHECK_UPLOAD_CUSTODY_DISKS, $detail.' (blocking once real money is open)');
    }

    /**
     * The queue driver can carry deferred financial work.
     *
     * `routes/console.php` schedules payout batches, prize-tax calculation,
     * wallet-reservation expiry, AML reassessment and reconciliation jobs. On a
     * `sync` driver every one of those runs inline inside the scheduler process,
     * so a slow provider call blocks the whole schedule — and a job that throws
     * takes the scheduler with it. Blocking in production, advisory before it,
     * because `sync` is the correct local and test driver.
     */
    private function checkQueueDriver(bool $production): CheckResult
    {
        $driver = strtolower(trim((string) config('queue.default', '')));

        if ($driver === '') {
            return CheckResult::fail(self::CHECK_QUEUE_DRIVER, 'no default queue connection is configured');
        }

        if ($driver !== 'sync') {
            return CheckResult::pass(self::CHECK_QUEUE_DRIVER, 'deferred financial work runs on the '.$driver.' queue connection');
        }

        $detail = 'the queue connection is sync; scheduled payout, reconciliation and reservation jobs would run inline in the scheduler process';

        return $production
            ? CheckResult::fail(self::CHECK_QUEUE_DRIVER, $detail)
            : CheckResult::warn(self::CHECK_QUEUE_DRIVER, $detail.' (acceptable outside production)');
    }

    /**
     * The session driver is transactional.
     *
     * The password-logout policy and the bound-session middleware both need a
     * session store that participates in a database transaction, so a session
     * invalidated inside a rolled-back money boundary is invalidated for real.
     * `array` and `file` cannot provide that.
     */
    private function checkSessionDriver(bool $production): CheckResult
    {
        $driver = strtolower(trim((string) config('session.driver', '')));

        if (in_array($driver, self::TRANSACTIONAL_SESSION_DRIVERS, true)) {
            return CheckResult::pass(self::CHECK_SESSION_DRIVER, 'sessions are held by the transactional '.$driver.' driver');
        }

        $detail = $driver === ''
            ? 'no session driver is configured'
            : 'the session driver is '.$driver.', which cannot hold a transactional session';

        return $production
            ? CheckResult::fail(self::CHECK_SESSION_DRIVER, $detail)
            : CheckResult::warn(self::CHECK_SESSION_DRIVER, $detail.' (a transactional driver is required before production)');
    }

    /**
     * Is there a provider lane able to carry money in the configured market?
     *
     * The verdict is `ProviderReadinessService`'s, not this class's: the same
     * object answers the console command, the ops JSON endpoint, the boot refusal
     * and the deposit page, and a second evaluator that disagrees is the exact bug
     * that consolidation removed. What this adds is the scope — the answer is asked
     * for the configured site currency — and the rule
     * `PaymentsReadinessCommand` already applies when real money is enabled: an
     * open switch with no enabled-and-ready gateway is a failure, not a pass.
     *
     * While the gate is closed this is SKIP: no lane is required to move nothing,
     * and calling a dark lane FAIL would fail every release that has not launched.
     */
    private function checkProviderLanes(bool $gateOpen): CheckResult
    {
        if (! $gateOpen) {
            return CheckResult::skip(self::CHECK_PROVIDER_LANES, 'real money is closed; no provider lane is required');
        }

        $currency = Currency::tryFromCode((string) config('payment.currency.default', ''));

        if ($currency === null) {
            return CheckResult::fail(self::CHECK_PROVIDER_LANES, 'the configured site currency is not supported, so no provider lane can be evaluated');
        }

        $gateways = $this->providers->gateways();
        $enabled = [];
        $ready = [];
        foreach ($gateways as $name => $result) {
            // Currency-scoped: asking the gateway about the site currency makes
            // the BDT/THB market guards part of this answer rather than a
            // separate opinion about it.
            $scoped = $this->providers->gateway((string) $name, null, $currency);
            if ($scoped->enabled) {
                $enabled[] = (string) $name;
                if ($scoped->ready) {
                    $ready[] = (string) $name;
                }
            }
        }

        if ($enabled === []) {
            return CheckResult::fail(self::CHECK_PROVIDER_LANES, sprintf('no payment gateway is enabled for %s; real money is open with no lane to carry it', $currency->value));
        }

        if ($ready === []) {
            return CheckResult::fail(self::CHECK_PROVIDER_LANES, sprintf('enabled gateway(s) for %s are not ready: %s', $currency->value, $this->truncateList($enabled)));
        }

        return CheckResult::pass(self::CHECK_PROVIDER_LANES, sprintf('%d gateway(s) enabled and ready for %s: %s', count($ready), $currency->value, $this->truncateList($ready)));
    }

    // =====================================================================
    // Helpers. All pure or read-only.
    // =====================================================================

    /**
     * Normalise an operation name. Null means "the whole estate"; an unknown
     * non-null name refuses, because guessing which operation a caller meant
     * would answer a narrower question with a broader, weaker verdict.
     *
     * @throws FinancialException real_money_operation_unknown
     */
    private function normaliseOperation(?string $operation): ?string
    {
        if ($operation === null) {
            return null;
        }

        $normalised = strtolower(trim($operation));

        if ($normalised === '') {
            return null;
        }

        if (! in_array($normalised, self::OPERATIONS, true)) {
            throw FinancialException::withCode(
                'real_money_operation_unknown',
                trans('real_money.technical.operation_unknown', ['operation' => $operation]),
                ['operation' => $operation, 'known' => implode(', ', self::OPERATIONS)],
            );
        }

        return $normalised;
    }

    /**
     * The `WalletService::ACCOUNT_*` constants, by reflection.
     *
     * @return list<string>
     */
    private function walletAccountCodes(): array
    {
        try {
            $reflection = new ReflectionClass(WalletService::class);
        } catch (Throwable) {
            return [];
        }

        $codes = [];
        foreach ($reflection->getConstants() as $name => $value) {
            if (is_string($value) && $value !== '' && str_starts_with($name, 'ACCOUNT_')) {
                $codes[] = $value;
            }
        }

        sort($codes);

        return array_values(array_unique($codes));
    }

    /**
     * Every supported currency that resolves to a `Currency` case, primary first.
     *
     * @return list<Currency>
     */
    private function supportedCurrencies(): array
    {
        $resolved = [];

        foreach ($this->normaliseCodes(config('finance.currency.supported')) as $code) {
            $currency = Currency::tryFromCode($code);
            if ($currency !== null) {
                $resolved[$currency->value] = $currency;
            }
        }

        // The primary currency always gets a chart even if a supported list was
        // emptied at runtime: THB is where every existing book is denominated.
        $primary = Currency::primary();
        $resolved = [$primary->value => $primary] + $resolved;

        return array_values($resolved);
    }

    /**
     * @return list<string>
     */
    private function normaliseCodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $codes = [];
        foreach ($value as $code) {
            if (is_string($code)) {
                $code = strtoupper(trim($code));
                if ($code !== '') {
                    $codes[] = $code;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * The decoded key material, or null when there is none. Same rule as
     * `ProductionPreflight::encryptionKeyMaterial()`.
     */
    private function encryptionKeyMaterial(mixed $key): ?string
    {
        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    // =====================================================================
    // Environment isolation, configuration strictness and the inbound-security
    // checks. All of them read configuration and the route table only: none of
    // them writes, resolves a financial service or contacts a provider.
    // =====================================================================

    /**
     * Does THIS environment permit the lanes that are switched on?
     *
     * A lane that is explicitly out of sandbox (`sandbox === false`) while the
     * environment is local, testing or staging is a breach: those environments
     * must move no real provider money, and the switch that put a live lane
     * there is the defect.
     *
     * A lane with NO sandbox marker is reported as advisory rather than as a
     * breach. Most lanes in this repository predate the marker, so failing them
     * would fail every release that has not launched - and it would bury the one
     * finding that matters (a lane that was deliberately taken out of sandbox)
     * under a wall of identical failures. What the warning says is precise: for
     * those lanes isolation rests on the credentials that happen to be
     * configured, not on anything configuration can prove.
     */
    private function checkEnvironmentIsolation(): CheckResult
    {
        $environment = strtolower(trim((string) config('app.env', '')));
        $production = $environment === 'production';
        $rule = self::ENVIRONMENT_RULES[$environment] ?? 'unrecognised environment; treated as untrusted';

        $enabled = $this->enabledGateways();

        if ($production) {
            // Production is allowed to run live lanes - that is what production
            // is for. What it is not allowed to do is open live operations on
            // the strength of this check; `liveOperationsOpen()` answers that
            // separately and answers no in this phase.
            $sandboxed = [];
            foreach ($enabled as $name => $gateway) {
                if (($gateway['sandbox'] ?? null) === true) {
                    $sandboxed[] = $name;
                }
            }

            return $sandboxed === []
                ? CheckResult::pass(self::CHECK_ENVIRONMENT_ISOLATION, 'no enabled lane in production is held inside a sandbox flag ('.$rule.')')
                : CheckResult::warn(self::CHECK_ENVIRONMENT_ISOLATION, 'these lanes are enabled in production but still flagged sandbox, so they will not settle real money: '.$this->truncateList($sandboxed));
        }

        $liveLanes = [];
        $unprovenLanes = [];

        foreach ($enabled as $name => $gateway) {
            $sandbox = $gateway['sandbox'] ?? null;

            if ($sandbox === false) {
                $liveLanes[] = $name;
            } elseif ($sandbox === null) {
                $unprovenLanes[] = $name;
            }
        }

        if ($liveLanes !== []) {
            return CheckResult::fail(
                self::CHECK_ENVIRONMENT_ISOLATION,
                'the '.$environment.' environment must move no real provider money ('.$rule.'), but these lanes are enabled and explicitly out of sandbox: '.$this->truncateList($liveLanes),
            );
        }

        if ($unprovenLanes !== []) {
            return CheckResult::warn(
                self::CHECK_ENVIRONMENT_ISOLATION,
                'these lanes are enabled in '.$environment.' and declare no sandbox marker, so isolation rests on the credentials configured rather than on configuration: '.$this->truncateList($unprovenLanes),
            );
        }

        return CheckResult::pass(
            self::CHECK_ENVIRONMENT_ISOLATION,
            $enabled === []
                ? 'no provider lane is enabled in the '.$environment.' environment ('.$rule.')'
                : 'every lane enabled in '.$environment.' is inside its sandbox ('.$rule.')',
        );
    }

    /**
     * Are the switches that govern money real booleans?
     *
     * `RealMoneyGate::isOpen()` already refuses anything that is not the literal
     * `true`, so a mis-typed switch fails closed. This check exists because
     * failing closed silently is a bad outcome too: an operator who wrote
     * `REAL_MONEY_ENABLED=yes` sees a platform that refuses money and no
     * explanation. Naming the defect - with its TYPE and never its contents,
     * because a line that was meant to hold a boolean may have been pasted with
     * a secret in it - turns that into a one-line fix.
     */
    private function checkConfigurationStrictness(): CheckResult
    {
        $defects = [];

        foreach ($this->moneySwitchPaths() as $path) {
            $value = config($path);

            if (! is_bool($value)) {
                $defects[] = $path.' holds '.StrictBoolean::describe($value).' instead of a boolean';
            }
        }

        foreach ([
            'real_money.enabled_source' => 'REAL_MONEY_ENABLED',
            'real_money.require_readiness_pass_source' => 'REAL_MONEY_REQUIRE_READINESS_PASS',
        ] as $path => $variable) {
            $source = config($path);

            // 'missing' is the normal, safe state of a switch nobody set: the
            // master switch is CLOSED and that is the shipped default. Only a
            // value that WAS written and could not be parsed is a defect.
            if (is_string($source) && ($source === 'empty' || str_starts_with($source, 'malformed'))) {
                $defects[] = $variable.' was written as '.$source.', which is not a strict boolean, so the switch is CLOSED';
            }
        }

        if ($defects !== []) {
            return CheckResult::fail(
                self::CHECK_CONFIGURATION_STRICTNESS,
                count($defects).' money '.(count($defects) === 1 ? 'switch is' : 'switches are').' not a strict boolean: '.$this->truncateList($defects, 3),
            );
        }

        $source = config('real_money.enabled_source');

        return CheckResult::pass(
            self::CHECK_CONFIGURATION_STRICTNESS,
            'every money switch holds a real boolean; REAL_MONEY_ENABLED parsed as '.(is_string($source) ? $source : 'unknown')
                .' and the master switch is '.($this->realMoneyGate->isOpen() ? 'OPEN' : 'CLOSED'),
        );
    }

    /**
     * Do the classes that move money exist?
     *
     * Existence only. Resolving a financial service can open a transaction, bind
     * a provider client or write a log, and a readiness evaluator must be
     * read-only; a renamed or deleted class leaves the application bootable and
     * every monetary write fatal at the first request, which is what this names
     * in advance.
     */
    private function checkFinancialServices(): CheckResult
    {
        $missing = [];

        foreach (self::FINANCIAL_SERVICE_CLASSES as $class) {
            if (! class_exists($class)) {
                $missing[] = $class;
            }
        }

        if ($missing !== []) {
            return CheckResult::fail(
                self::CHECK_FINANCIAL_SERVICES,
                count($missing).' financial service '.(count($missing) === 1 ? 'class is' : 'classes are').' missing: '.$this->truncateList($missing, 4),
            );
        }

        return CheckResult::pass(
            self::CHECK_FINANCIAL_SERVICES,
            'all '.count(self::FINANCIAL_SERVICE_CLASSES).' financial service classes exist and are loadable',
        );
    }

    /**
     * Can an inbound provider notification be trusted?
     *
     * Signature verification, replay protection, receipt persistence and an age
     * bound are what stand between an attacker and a free deposit, and every one
     * of them is configuration - so every one of them can be switched off by a
     * deploy manifest nobody reads. Those platform-wide invariants are checked
     * FIRST and are not conditional on the master switch, because a closed gate
     * with replay protection disabled is one configuration change away from a
     * replayable deposit.
     *
     * Per-lane secrets are only required once the gate is open: while it is
     * closed no lane is receiving notifications, so an unconfigured secret is
     * reported as SKIP rather than as a failure that would block every release
     * that has not launched.
     */
    private function checkWebhookSecurity(bool $gateOpen, bool $production): CheckResult
    {
        $webhook = config('payment.webhook');
        $webhook = is_array($webhook) ? $webhook : [];

        $defects = [];

        if (($webhook['verify_signature'] ?? null) !== true) {
            $defects[] = 'payment.webhook.verify_signature is not true, so a notification is accepted on its own claim';
        }

        if (($webhook['replay_protection'] ?? null) !== true) {
            $defects[] = 'payment.webhook.replay_protection is not true, so a captured notification can be replayed';
        }

        if (($webhook['persist_receipts'] ?? null) !== true) {
            $defects[] = 'payment.webhook.persist_receipts is not true, so a delivery can be neither proved nor reconciled afterwards';
        }

        $maxAge = $webhook['max_age_seconds'] ?? null;

        if (! is_int($maxAge) || $maxAge < 1) {
            $defects[] = 'payment.webhook.max_age_seconds must be a positive number of seconds';
        }

        $retention = $webhook['replay_guard_retention_days'] ?? null;

        if (! is_int($retention) || $retention < 1) {
            $defects[] = 'payment.webhook.replay_guard_retention_days must be at least one day, or the replay window reopens';
        }

        if ($production && ($webhook['require_https'] ?? null) !== true) {
            $defects[] = 'payment.webhook.require_https is not true in production, so a signature could arrive over a readable transport';
        }

        if ($defects !== []) {
            return CheckResult::fail(self::CHECK_WEBHOOK_SECURITY, $this->truncateList($defects, 3));
        }

        if (! $gateOpen) {
            return CheckResult::skip(
                self::CHECK_WEBHOOK_SECURITY,
                'the platform webhook contract holds (signature verification, replay protection, receipt persistence, age bound), but real money is closed so no lane is receiving notifications',
            );
        }

        $unprotected = [];
        $undemonstrated = [];

        foreach ($this->enabledGateways() as $name => $gateway) {
            $declaresCallback = ($gateway['supports_webhook'] ?? null) === true
                || array_key_exists('webhook_secret', $gateway)
                || array_key_exists('signature_header', $gateway);

            if (! $declaresCallback) {
                continue;
            }

            $secret = $gateway['webhook_secret'] ?? null;
            $header = $gateway['signature_header'] ?? null;

            if (is_string($secret) && trim($secret) !== '' && is_string($header) && trim($header) !== '') {
                continue;
            }

            if (($gateway['sandbox'] ?? null) === false || $production) {
                $unprotected[] = $name;
            } else {
                $undemonstrated[] = $name;
            }
        }

        if ($unprotected !== []) {
            return CheckResult::fail(
                self::CHECK_WEBHOOK_SECURITY,
                'these live lanes accept provider callbacks without a usable signature secret and header: '.$this->truncateList($unprotected),
            );
        }

        if ($undemonstrated !== []) {
            return CheckResult::warn(
                self::CHECK_WEBHOOK_SECURITY,
                'the platform webhook contract holds, but these sandbox lanes have no signature secret configured, so callback authentication is not demonstrated: '.$this->truncateList($undemonstrated),
            );
        }

        return CheckResult::pass(
            self::CHECK_WEBHOOK_SECURITY,
            'signature verification, replay protection, receipt persistence and an age bound are configured, and every enabled callback lane carries a secret and a signature header',
        );
    }

    /**
     * Can a retried request double-credit a player?
     *
     * Providers retry, and idempotency is the only thing that makes a retry
     * safe. It needs a switch, a header a client can present, a TTL - and a
     * cache store that outlives the request. An `array` store in production
     * discards every key when the worker recycles, which turns one duplicate
     * delivery into two deposits, so that combination fails while the same
     * store outside production is advisory: it is how the test suite runs.
     */
    private function checkIdempotency(bool $production): CheckResult
    {
        $idempotency = config('payment.idempotency');
        $idempotency = is_array($idempotency) ? $idempotency : [];

        $defects = [];

        if (($idempotency['enabled'] ?? null) !== true) {
            $defects[] = 'payment.idempotency.enabled is not true, so a retried request is a second operation';
        }

        $header = $idempotency['header'] ?? null;

        if (! is_string($header) || trim($header) === '') {
            $defects[] = 'payment.idempotency.header names no request header, so a client cannot present a key';
        }

        $ttl = $idempotency['cache_ttl'] ?? null;

        if (! is_int($ttl) || $ttl < 1) {
            $defects[] = 'payment.idempotency.cache_ttl must be a positive number of seconds';
        }

        if ($defects !== []) {
            return CheckResult::fail(self::CHECK_IDEMPOTENCY, $this->truncateList($defects, 3));
        }

        $store = strtolower(trim((string) config('cache.default', '')));
        $volatile = in_array($store, ['array', 'null'], true);

        if ($volatile && $production) {
            return CheckResult::fail(
                self::CHECK_IDEMPOTENCY,
                'the cache store is '.$store.', which discards every idempotency key when the process ends, so a provider retry would be processed twice',
            );
        }

        if ($volatile) {
            return CheckResult::warn(
                self::CHECK_IDEMPOTENCY,
                'idempotency is configured, but the '.$store.' cache store does not survive the request (acceptable outside production, required before it)',
            );
        }

        return CheckResult::pass(
            self::CHECK_IDEMPOTENCY,
            'idempotency keys are read from '.(is_string($header) ? $header : 'the configured header')
                .' and held for '.(is_int($ttl) ? $ttl : 0).' seconds in the '.$store.' store',
        );
    }

    // =====================================================================
    // The published readiness contract.
    // =====================================================================

    /**
     * The launch decision, in the exact shape the technical-readiness contract
     * requires: one boolean per area, the environment it was evaluated in, what
     * is missing, and why live operations are closed.
     *
     * This is a projection of `report()`, never a second evaluation - two
     * evaluators that can disagree are the bug this class exists to remove. The
     * keys are fixed and ordered so a `--json` consumer can diff two runs.
     *
     * A `technical_ready` of true is EVIDENCE, not authority: it never opens the
     * master switch and never opens live operations. `live_operations_open`
     * answers that separately, and in this phase it answers false always.
     *
     * @return array{
     *     technical_ready: bool,
     *     live_operations_open: bool,
     *     environment: string,
     *     configuration_valid: bool,
     *     database_ready: bool,
     *     ledger_schema_ready: bool,
     *     financial_services_ready: bool,
     *     provider_configuration_ready: bool,
     *     currency_configuration_ready: bool,
     *     queue_configuration_ready: bool,
     *     webhook_security_ready: bool,
     *     idempotency_ready: bool,
     *     missing_requirements: list<string>,
     *     blocking_reasons: list<string>
     * }
     *
     * @throws FinancialException real_money_operation_unknown
     */
    public function assessment(?string $operation = null): array
    {
        $report = $this->report($operation);

        return [
            'technical_ready' => $report['technical_ready'],
            'live_operations_open' => $report['live_operations_open'],
            'environment' => $report['environment'],
            'configuration_valid' => $report['configuration_valid'],
            'database_ready' => $report['database_ready'],
            'ledger_schema_ready' => $report['ledger_schema_ready'],
            'financial_services_ready' => $report['financial_services_ready'],
            'provider_configuration_ready' => $report['provider_configuration_ready'],
            'currency_configuration_ready' => $report['currency_configuration_ready'],
            'queue_configuration_ready' => $report['queue_configuration_ready'],
            'webhook_security_ready' => $report['webhook_security_ready'],
            'idempotency_ready' => $report['idempotency_ready'],
            'missing_requirements' => $report['missing_requirements'],
            'blocking_reasons' => $report['blocking_reasons'],
        ];
    }

    /**
     * May this deployment run live money operations?
     *
     * ALWAYS FALSE IN THIS PHASE, and that is the requirement rather than an
     * unfinished edge: technical readiness and legal authorisation are separate
     * concerns, and activation is an independently controlled mechanism this
     * phase deliberately does not provide. Nothing in configuration, in the
     * database or in the environment can make this answer true, so a readiness
     * PASS cannot activate real-money operations.
     *
     * The refusal is published as an ordered list of reasons rather than a bare
     * `false`, because "no" without "why" is what makes an operator go looking
     * for a switch to flip.
     */
    public function liveOperationsOpen(): bool
    {
        return $this->liveOperationBlockers() === [];
    }

    /**
     * Why live money operations are closed, in the order an operator would have
     * to clear them.
     *
     * @return list<string>
     *
     * @throws FinancialException real_money_operation_unknown
     */
    public function liveOperationBlockers(): array
    {
        $blocking = $this->blockingFailures();

        return $this->describeLiveOperationBlockers($blocking === [], count($blocking));
    }

    /**
     * The blocker list, built from a verdict the caller has already computed.
     *
     * `report()` uses this so that publishing the launch decision does not run
     * the whole check estate a second time.
     *
     * @return list<string>
     */
    private function describeLiveOperationBlockers(bool $technicallyReady, int $blockingCount): array
    {
        $blockers = [];
        $environment = strtolower(trim((string) config('app.env', '')));

        if (in_array($environment, self::NON_PRODUCTION_ENVIRONMENTS, true)) {
            $blockers[] = 'the '.$environment.' environment never opens live money operations: '
                .(self::ENVIRONMENT_RULES[$environment] ?? 'unrecognised environment');
        }

        if (! $this->realMoneyGate->isOpen()) {
            $blockers[] = 'the real-money master switch is closed';
        }

        // The runtime latch is reported separately from the master switch even
        // though both refuse: one is configuration and changes with a deploy, the
        // other was written by an administrator during an incident and carries a
        // reason and an actor in the audit log.
        if (! $this->shutdownControl->isOpen()) {
            $blockers[] = $this->shutdownControl->status()['storage_available']
                ? 'the runtime money operation control is closed'
                : 'the runtime money operation control could not be read, so it is treated as closed';
        }

        if (! $technicallyReady) {
            $blockers[] = 'technical readiness has '.$blockingCount.' blocking '
                .($blockingCount === 1 ? 'failure' : 'failures');
        }

        // Unconditional by design. This is the line that keeps a technical
        // readiness PASS from ever becoming an activation: there is no
        // configuration key, table row or environment value that removes it in
        // this phase.
        $blockers[] = 'live activation is separately controlled and deliberately unavailable in this phase: '
            .'a technical readiness PASS never opens live money operations';

        return $blockers;
    }

    /**
     * One boolean per readiness area.
     *
     * True only when every check deciding the area was evaluated and none of
     * them failed. SKIP counts as "not demonstrated", so a closed deployment
     * honestly reports that its licence and provider lanes were never examined;
     * WARN does not clear a flag, keeping these answers consistent with
     * `technical_ready`, which WARN also does not block.
     *
     * @param  array<string, CheckResult>  $checks
     * @return array<string, bool>
     */
    private function readinessFlags(array $checks): array
    {
        $flags = [];

        foreach (self::READINESS_GROUPS as $flag => $ids) {
            $demonstrated = true;

            foreach ($ids as $id) {
                $check = $checks[$id] ?? null;

                if ($check === null || $check->isFailure() || $check->status === CheckResult::SKIP) {
                    $demonstrated = false;
                    break;
                }
            }

            $flags[$flag] = $demonstrated;
        }

        return $flags;
    }

    /**
     * Every non-PASS check as one actionable line, in published check order.
     *
     * A FAIL says what must be fixed, a SKIP says what was never evaluated and a
     * WARN says what is advisory. Keeping the three distinguishable matters:
     * "14 missing requirements" is not actionable, but "real_money.chart_of_accounts
     * must be fixed: 2000:BDT is absent - Seed the chart of accounts" is.
     *
     * @param  array<string, CheckResult>  $checks
     * @return list<string>
     */
    private function missingRequirements(array $checks): array
    {
        $missing = [];

        foreach ($checks as $id => $check) {
            if ($check->status === CheckResult::PASS) {
                continue;
            }

            $missing[] = match ($check->status) {
                CheckResult::FAIL => $id.' must be fixed: '.$check->message,
                CheckResult::SKIP => $id.' was not evaluated: '.$check->message,
                default => $id.' is advisory: '.$check->message,
            };
        }

        return $missing;
    }

    /**
     * The gateway blocks whose `enabled` flag is exactly true.
     *
     * `=== true` rather than a cast: a gateway switch that holds the string
     * 'yes' is a configuration defect, and `checkConfigurationStrictness()`
     * reports it as one. This method must not quietly treat it as an enabled
     * lane and then evaluate the rest of the estate against a lane that the
     * application itself would refuse to use.
     *
     * @return array<string, array<string, mixed>>
     */
    private function enabledGateways(): array
    {
        $gateways = config('payment.gateways');

        if (! is_array($gateways)) {
            return [];
        }

        $enabled = [];

        foreach ($gateways as $name => $gateway) {
            if (! is_string($name) || ! is_array($gateway)) {
                continue;
            }

            if (($gateway['enabled'] ?? null) === true) {
                $enabled[$name] = $gateway;
            }
        }

        return $enabled;
    }

    /**
     * Every switch path that governs money, including the gateway switches
     * discovered from configuration.
     *
     * Gateways are discovered rather than listed so that a lane added to
     * `config/payment.php` is audited without anyone having to remember to add
     * it here too - an audit that has to be maintained by hand is an audit that
     * quietly stops covering new lanes.
     *
     * @return list<string>
     */
    private function moneySwitchPaths(): array
    {
        $paths = self::MONEY_SWITCH_PATHS;

        $gateways = config('payment.gateways');

        if (is_array($gateways)) {
            foreach ($gateways as $name => $gateway) {
                if (! is_string($name) || ! is_array($gateway)) {
                    continue;
                }

                // Only a block that DECLARES a switch has a switch to audit.
                // `payment.gateways` is not a clean list of lanes: ThaiRailsServiceProvider
                // writes `payment.gateways.scb.signature_header` and the kbank
                // equivalent at boot, which brings those two keys into existence
                // as blocks holding a callback identity and no `enabled` flag at
                // all. Demanding a boolean from a block that never declared one
                // would report a defect that does not exist - and would bury the
                // real ones, which is worse than not auditing.
                if (array_key_exists('enabled', $gateway)) {
                    $paths[] = 'payment.gateways.'.$name.'.enabled';
                }

                if (array_key_exists('sandbox', $gateway)) {
                    $paths[] = 'payment.gateways.'.$name.'.sandbox';
                }
            }
        }

        return $paths;
    }

    /**
     * A bounded, credential-free rendering of a name list. Check messages end up
     * in CI transcripts, deploy logs and incident tickets, so a long list is
     * counted and cut rather than printed in full.
     *
     * @param  list<string>  $items
     */
    private function truncateList(array $items, int $limit = 8): string
    {
        $items = array_values(array_map('strval', $items));
        $shown = array_slice($items, 0, $limit);
        $rest = count($items) - count($shown);

        return implode(', ', $shown).($rest > 0 ? sprintf(' (+%d more)', $rest) : '');
    }
}
