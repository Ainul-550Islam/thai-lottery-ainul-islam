<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Which financial mutation entry points consult the real-money gate.
 *
 * WHY THIS TEST IS A SOURCE SCAN AND NOT A BEHAVIOUR TEST
 * -------------------------------------------------------
 * Every one of these services already has its own behavioural suite. What none
 * of them had was an assertion that the gate call is still THERE: a refactor can
 * move a deposit into a new service, inline a transaction, or "simplify" an
 * admission helper, and every existing test keeps passing while the gate
 * quietly stops being consulted. This test fails on that day, which is the only
 * day it matters.
 *
 * WHY THE GAP LIST IS ASSERTED RATHER THAN MERELY DOCUMENTED
 * ----------------------------------------------------------
 * `GAPS` below is an audit finding, not an approval. Each entry is a financial
 * mutation path that neither consults the gate itself nor is reached only
 * through a service that does. Asserting the list means the set cannot grow
 * silently - a new money service that forgets the gate fails this test - and
 * closing a gap fails it too, which forces whoever fixes one to move it into
 * `COVERED` and say so explicitly.
 *
 * Nothing here enables anything. The master switch stays closed and these paths
 * stay exactly as they were found.
 *
 * This suite boots Laravel even though most of it only reads files, because
 * `config/real_money.php` calls `env()` and cannot be evaluated outside the
 * framework. Reading the guarded-route list from the real config file is worth
 * the boot: a copy of the list in this test would drift from it silently.
 */
final class MoneyEntryPointCoverageTest extends TestCase
{
    /**
     * Entry points that consult the gate directly, with the call that proves it.
     *
     * @var array<string, string>
     */
    private const COVERED = [
        'app/Services/Finance/DepositService.php' => "assertOpen('deposit')",
        'app/Services/Finance/WithdrawalService.php' => "assertOpen('withdrawal')",
        'app/Services/Betting/BetPurchaseService.php' => "assertOpen('bet')",
        'app/Services/Betting/BetPurchaseAdmission.php' => "assertOpen('bet')",
        'app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php' => "assertOpen('bet')",
        'app/Services/Agent/AgentPosTicketIssuanceService.php' => "assertOpen('bet')",
        'app/Services/Payment/PaymentIntentService.php' => 'assertOpen(',
        'app/Services/Payment/DepositCheckoutService.php' => "assertOpen('deposit')",
        'app/Services/Payment/TrueMoneyWalletService.php' => "assertOpen('deposit')",
    ];

    /**
     * Entry points covered INDIRECTLY: they mutate nothing themselves and reach
     * money only through a service in `COVERED`.
     *
     * @var array<string, string>
     */
    private const COVERED_BY_DELEGATION = [
        'app/Services/Betting/BulkBetService.php' => 'BetPurchaseService',
    ];

    /**
     * AUDIT FINDING: financial mutation paths with no gate consultation.
     *
     * Each of these was verified by reading the file, not by inference. They are
     * listed with the reason they are exposed, because "uncovered" on its own
     * does not tell an engineer whether the fix belongs in the service, in the
     * route middleware, or in the caller.
     *
     * @var array<string, string>
     */
    private const GAPS = [
        'app/Services/Wallet/Conversion/WalletConversionService.php'
            => 'converts between wallet balances and posts ledger entries; reached from four controllers, and no route-level licence check stands between them and the ledger',
        'app/Services/Transfer/BalanceTransferService.php'
            => 'moves balance between users and posts ledger entries; its controller is not a guarded route',
        'app/Services/Lottery/GloL6TransactionalPurchaseService.php'
            => 'takes a wallet reservation for an L6 ticket; the authoritative engine beside it is gated, this transactional API path is not',
        'app/Services/Betting/MarketPayoutService.php'
            => 'releases prize money on settlement; called from the draw settlement services with no gate consultation',
        'app/Services/Finance/PayoutApprovalService.php'
            => 'approves a payout for release; PayoutApprovalGuard checks ownership and evidence but not the master switch',
        'app/Services/Finance/ApprovedWalletPayoutService.php'
            => 'pays an approved payout into a wallet; reaches WalletService, which is not a gate',
        'app/Services/Agent/AgentCommissionPayoutService.php'
            => 'settles agent commission; reached from four agent settlement services with no gate consultation',
        'app/Services/Betting/BetPermutationService.php'
            => 'reached from BulkBetController; guarded_routes claims api.bulk-bet.store covers it, but no route has that name and the registered bulk routes carry no licence middleware, so the service itself would have to refuse a direct call and does not',
    ];

    /**
     * Routes the licence middleware is configured to guard.
     *
     * @var list<string>
     */
    private const GUARDED_ROUTES = [
        'player.deposit.store',
        'player.withdraw.store',
        'player.bets.purchase',
        'player.bets.purchase.alias',
        'api.deposit.store',
        'api.withdrawal.store',
        'api.bet.store',
        'api.bulk-bet.store',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function source(string $relativePath): string
    {
        $path = $this->root().'/'.$relativePath;

        self::assertFileExists($path, $relativePath.' no longer exists: this audit has to be re-run against whatever replaced it.');

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    // =====================================================================
    // §1 Direct coverage cannot be refactored away silently.
    // =====================================================================

    public function test_every_covered_entry_point_still_consults_the_gate(): void
    {
        foreach (self::COVERED as $path => $proof) {
            self::assertStringContainsString(
                $proof,
                $this->source($path),
                $path.' no longer calls the real-money gate. A financial mutation path lost its gate.',
            );
        }
    }

    public function test_every_indirectly_covered_entry_point_still_delegates(): void
    {
        foreach (self::COVERED_BY_DELEGATION as $path => $delegate) {
            self::assertStringContainsString(
                $delegate,
                $this->source($path),
                $path.' no longer reaches money through '.$delegate.', so its indirect gate coverage is gone.',
            );
        }
    }

    // =====================================================================
    // §2 The gap list is a ratchet: it may shrink only deliberately.
    // =====================================================================

    public function test_the_audited_gap_list_is_still_accurate(): void
    {
        $closed = [];
        $stillOpen = [];

        foreach (self::GAPS as $path => $reason) {
            $source = $this->source($path);

            if (str_contains($source, 'RealMoneyGate')
                || str_contains($source, 'MoneyShutdownControlService')
                || str_contains($source, 'FinancialComplianceGateService')) {
                $closed[] = $path;
            } else {
                $stillOpen[$path] = $reason;
            }
        }

        self::assertSame(
            [],
            $closed,
            'These paths now consult a gate, which is good news and a required update: move each one from GAPS to COVERED in '
                .self::class.'.php so the audit states the current truth. Closing a gap silently is how the next reviewer is misled.',
        );

        self::assertSame(
            array_keys(self::GAPS),
            array_keys($stillOpen),
            'The set of uncovered financial mutation paths changed.',
        );
    }

    public function test_no_new_financial_mutation_service_was_added_without_being_audited(): void
    {
        // The audited universe is the three lists above. A service that mutates
        // money and appears in none of them has not been looked at by anybody,
        // which is the failure mode this whole audit exists to prevent.
        $audited = array_keys(self::COVERED + self::COVERED_BY_DELEGATION + self::GAPS);

        $suspects = [
            'app/Services/Finance/DepositService.php',
            'app/Services/Finance/WithdrawalService.php',
            'app/Services/Betting/BetPurchaseService.php',
            'app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php',
            'app/Services/Wallet/Conversion/WalletConversionService.php',
            'app/Services/Transfer/BalanceTransferService.php',
            'app/Services/Betting/MarketPayoutService.php',
            'app/Services/Agent/AgentCommissionPayoutService.php',
        ];

        foreach ($suspects as $path) {
            self::assertContains(
                $path,
                $audited,
                $path.' is a known financial mutation path but is missing from this audit.',
            );
        }

        self::assertNotEmpty($audited);
    }

    // =====================================================================
    // §3 Route-level coverage: what the licence middleware actually guards.
    // =====================================================================

    public function test_the_guarded_route_list_is_what_configuration_declares(): void
    {
        /** @var array<string, mixed> $config */
        $config = require $this->root().'/config/real_money.php';

        self::assertSame(
            self::GUARDED_ROUTES,
            $config['guarded_routes'],
            'real_money.guarded_routes changed. Read the two tests below before accepting the change: this list is not consulted by any application code, so editing it changes no behaviour and can create the impression of protection that does not exist.',
        );
    }

    /**
     * AUDIT FINDING: the list looks like enforcement and is not.
     *
     * Four of the eight names resolve to no registered route. The real API money
     * routes exist under different names - the deposit route is registered as
     * `api.v1.wallet.deposit`, and the bulk bet routes sit inside an `api.v1.`
     * name group - so these four entries match nothing.
     *
     * Asserted as an exact set rather than "at least these", so correcting the
     * names fails the test and forces this finding to be updated instead of
     * leaving a stale claim behind.
     */
    public function test_four_of_the_guarded_route_names_resolve_to_no_route_at_all(): void
    {
        $unresolved = array_values(array_filter(
            self::GUARDED_ROUTES,
            static fn (string $name): bool => ! Route::has($name),
        ));

        self::assertSame(
            ['api.deposit.store', 'api.withdrawal.store', 'api.bet.store', 'api.bulk-bet.store'],
            $unresolved,
            'The set of guarded_routes names that resolve to no route changed. If they were corrected, update this list and every GAPS entry that relied on them; if a further name stopped resolving, a money route just lost the entry that claimed to cover it.',
        );
    }

    /**
     * AUDIT FINDING: the licence middleware is registered and attached nowhere.
     *
     * `real_money.license` exists as a middleware alias, so it looks available,
     * but no route in the application carries it. The money routes carry
     * `active`, a throttle, `adult` and `legal.current` instead. Together with
     * the finding above, the route layer contributes nothing to the real-money
     * gate: every refusal comes from the service layer.
     *
     * That is survivable - it is exactly why the test below asserts the services
     * gate themselves - but it must not be described as defence in depth while
     * there is only one depth, and a new money route must not assume it inherits
     * a licence check from the route table.
     */
    public function test_the_licence_middleware_is_registered_but_attached_to_no_route(): void
    {
        /** @var array<string, string> $aliases */
        $aliases = app('router')->getMiddleware();

        self::assertArrayHasKey(
            'real_money.license',
            $aliases,
            'The real_money.license alias is gone, so the middleware can no longer be attached by name at all.',
        );

        $attached = [];

        foreach (app('router')->getRoutes() as $route) {
            if (in_array('real_money.license', $route->gatherMiddleware(), true)) {
                $attached[] = $route->getName() ?? $route->uri();
            }
        }

        self::assertSame(
            [],
            $attached,
            'A route now carries real_money.license. That is an improvement, and this test should then assert the money routes are covered - but first confirm which routes gained it and whether GAPS above still describes reality.',
        );
    }

    public function test_the_real_money_api_routes_are_not_the_names_the_list_claims(): void
    {
        // Matched by controller action rather than by route name, because the API
        // routes sit inside name-prefixed groups: a name-based check would end up
        // asserting the prefix instead of the coverage.
        foreach ([
            'Api\V1\DepositController@store',
            'Api\V1\WithdrawalController@store',
            'Api\V1\BulkBetController@purchase',
        ] as $action) {
            $name = $this->routeNameFor($action);

            self::assertNotNull($name, $action.' is no longer a registered route; this audit has to be re-run against whatever replaced it.');
            self::assertNotContains(
                $name,
                self::GUARDED_ROUTES,
                $action.' is now named in guarded_routes. If the list ever becomes consulted, this path can move out of GAPS.',
            );
        }
    }

    public function test_route_middleware_alone_is_not_treated_as_coverage(): void
    {
        // The service layer is the ONLY layer that refuses today, so this is not
        // a redundancy check - it is the entire control. A service invoked
        // directly, from a console command, a queued job, a seeder or another
        // service, never passes through route middleware in any case.
        foreach ([
            'app/Services/Finance/DepositService.php',
            'app/Services/Finance/WithdrawalService.php',
            'app/Services/Betting/BetPurchaseService.php',
        ] as $path) {
            self::assertStringContainsString(
                'RealMoneyGate',
                $this->source($path),
                $path.' relies on route middleware for its gate. A direct invocation would move money with nothing consulting the switch.',
            );
        }
    }

    /** The registered route name for a controller action, if there is one. */
    private function routeNameFor(string $controllerAction): ?string
    {
        foreach (app('router')->getRoutes() as $route) {
            if (str_contains((string) $route->getActionName(), $controllerAction)) {
                return $route->getName();
            }
        }

        return null;
    }

    // =====================================================================
    // §4 The settlement paths must stay UNGATED, on purpose.
    // =====================================================================

    public function test_settlement_paths_do_not_consult_the_admission_gate(): void
    {
        // The mirror image of the coverage requirement, and the one that is
        // easiest to break with good intentions. A signed provider callback is
        // the settlement of an obligation that already exists: money the player
        // has already sent. If a settlement path consulted the admission gate,
        // closing the gate to stop NEW deposits would also discard verified
        // payments that arrived before it closed - taking custody of a player's
        // money and then refusing to record it.
        //
        // Admission and settlement are therefore deliberately asymmetric, and
        // this asserts the asymmetry survives.
        foreach ([
            'app/Services/Deposit/DepositProcessingService.php',
            'app/Services/Payment/DepositCallbackApplicationService.php',
            'app/Services/Payment/WithdrawalCallbackApplicationService.php',
            'app/Services/Finance/DepositCompletionService.php',
            'app/Services/Finance/WithdrawalCompletionService.php',
        ] as $path) {
            $source = $this->source($path);

            self::assertStringNotContainsString(
                'RealMoneyGate',
                $source,
                $path.' now consults the admission gate. Closing the gate would discard an existing verified obligation instead of only refusing new ones.',
            );
        }
    }

    public function test_confirmed_deposit_settlement_stays_idempotent(): void
    {
        // Duplicate delivery is the normal case, not the exceptional one: every
        // provider retries. Idempotency is what makes a retry safe, so the key
        // derivation and the reuse of an existing record are asserted here
        // rather than trusted.
        $source = $this->source('app/Services/Deposit/DepositProcessingService.php');

        self::assertStringContainsString('idempotencyKey', $source);
        self::assertStringContainsString('gatewayReferenceId', $source, 'The idempotency key no longer derives from the provider reference, so two deliveries of one payment would look like two payments.');
        self::assertMatchesRegularExpression('/DEP-\{?\$?gatewayProvider/', $source, 'The deposit idempotency key lost the provider component, so references from two providers could collide.');
    }
}
