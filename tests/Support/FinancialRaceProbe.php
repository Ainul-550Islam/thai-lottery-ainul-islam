<?php

declare(strict_types=1);
use App\Contracts\Wallet\WalletConversionServiceContract;
use App\DTOs\BetPurchaseContext;
use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BetAmendmentData;
use App\DTOs\Betting\BetCancellationData;
use App\DTOs\Payment\WebhookPayload;
use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\BetCancellationReason;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\QueueName;
use App\Enums\WalletConversionChannel;
use App\Enums\WebhookEventType;
use App\Exceptions\BetDomainException;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\FinancialException;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\RiskException;
use App\Exceptions\SelfExclusionException;
use App\Exceptions\SettlementSimulationException;
use App\Exceptions\Wallet\ConversionLimitExceededException;
use App\Models\Agent;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\SettlementRun;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Models\Withdrawal;
use App\Models\WithdrawalReconciliation;
use App\Policies\WalletConversionPolicy;
use App\Repositories\Wallet\WalletConversionQuoteRepository;
use App\Services\Agent\AgentCommissionSettlementService;
use App\Services\Agent\AgentSettlementService;
use App\Services\Betting\BetAmendmentService;
use App\Services\Betting\BetCancellationService;
use App\Services\Betting\BetPurchaseAdmission;
use App\Services\Betting\BetPurchaseService;
use App\Services\Betting\BetPurchaseTransactionService;
use App\Services\Betting\BetPurchaseValidator;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\DrawCancellationService;
use App\Services\Draw\RealPrizeSettlementService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalRequestWithHoldService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\Drivers\BankTransferGateway;
use App\Services\Payment\Drivers\StripeGateway;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Payment\PaymentWebhookService;
use App\Services\Payment\StripeDepositReviewService;
use App\Services\Payment\StripeDepositSettlementService;
use App\Services\Payment\StripeFollowupRoutingService;
use App\Services\Payment\StripeFollowupSweepService;
use App\Services\Payment\StripeRefundRecoveryService;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalReconciliationService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\ResponsibleGaming\SelfExclusionService;
use App\Services\Risk\NumberLimitEngine;
use App\Services\Risk\RiskAssessmentService;
use App\Services\Security\ResponsibleGamingService;
use App\Services\Wallet\Conversion\ConversionLimitService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Tests\Support\LifecycleBoundaryOperation;
use Tests\Support\ReversalBoundaryOperation;
use Tests\Support\SyntheticDepositCheckoutGateway;
use Tests\Support\SyntheticWithdrawalGateway;
use Tests\Support\WalletBoundaryOperation;

// Standalone test child, never a registered application/console entry point.
$root = dirname(__DIR__, 2);
if (getenv('FINANCIAL_RACE_PROBE') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'thai_lottery_test' || ! in_array(getenv('DB_CONNECTION'), ['mysql', 'mariadb'], true)
    || getenv('DB_URL') || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, 'Financial race probe refused unsafe environment.');
    exit(2);
}
$socket = getenv('DB_SOCKET');
if (! is_string($socket) || ! str_starts_with($socket, '/') || @filetype($socket) !== 'socket') {
    fwrite(STDERR, 'Financial race probe requires a local Unix socket.');
    exit(2);
}
$input = realpath($argv[1] ?? '');
$scratch = realpath($root.'/storage/framework/testing/financial-races');
if (! $input || ! $scratch || ! str_starts_with($input, $scratch.DIRECTORY_SEPARATOR) || filesize($input) > 32768) {
    fwrite(STDERR, 'Financial race probe refused its input path.');
    exit(2);
}
try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = $app->make('db')->connection();
    if ($db->getDatabaseName() !== 'thai_lottery_test' || $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Unsafe connection.');
    }
    $payload = json_decode(file_get_contents($input), true, 32, JSON_THROW_ON_ERROR);
    $allowed = ['conversions.currency', 'fees.categories.cash_to_win.currency', 'fees.categories.win_to_cash.currency',
        'conversions.gates.require_verified_mobile', 'conversions.limits.daily_limit', 'conversions.limits.monthly_limit', 'conversions.quotes.max_open_per_user', 'finance.prize_payout.safety_mode',
        'finance.prize_payout.auto_approve_below', 'finance.prize_payout.require_approval_at_or_above',
        'agent.commission.paid_cancellation.enabled', 'agent.commission.paid_cancellation.policy_reference'];
    foreach ($payload['config'] as $key => $value) {
        $typed = in_array($key, ['agent.commission.paid_cancellation.enabled', 'conversions.gates.require_verified_mobile'], true) ? is_bool($value) : is_string($value);
        if (! in_array($key, $allowed, true) || ! $typed
            || ($key === 'agent.commission.paid_cancellation.policy_reference' && ! preg_match('/^synthetic-[a-z0-9-]{8,100}$/D', $value))) {
            throw new RuntimeException('Unsafe test configuration override.');
        }
        $app->make('config')->set($key, $value);
    }
    $app->make('config')->set('queue.default', 'database');
    $app->make('config')->set('cache.default', 'array');
    $id = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $barrier = function (bool $accepted = false, ?int $snapshotOwners = null, ?int $snapshotReversals = null) use ($input, $id, &$conversionProofSnapshot, &$conversionAuthoritySnapshot, &$conversionLimitSnapshot, &$conversionQuoteSlotSnapshot, &$conversionMemberSnapshot, &$conversionWalletSnapshot, &$walletBoundarySnapshot, &$reversalBoundarySnapshot, &$lifecycleBoundarySnapshot): void {
        $marker = json_encode(['connection_id' => $id, 'accepted_before_barrier' => $accepted, 'snapshot_owner_count' => $snapshotOwners, 'snapshot_reversal_count' => $snapshotReversals, 'conversion_proof_snapshot' => $conversionProofSnapshot ?? null, 'conversion_authority_snapshot' => $conversionAuthoritySnapshot ?? null, 'conversion_limit_snapshot' => $conversionLimitSnapshot ?? null, 'conversion_quote_slot_snapshot' => $conversionQuoteSlotSnapshot ?? null, 'conversion_member_snapshot' => $conversionMemberSnapshot ?? null, 'conversion_wallet_snapshot' => $conversionWalletSnapshot ?? null, 'wallet_boundary_snapshot' => $walletBoundarySnapshot ?? null, 'reversal_boundary_snapshot' => $reversalBoundarySnapshot ?? null, 'lifecycle_boundary_snapshot' => $lifecycleBoundarySnapshot ?? null], JSON_THROW_ON_ERROR);
        // Publish a complete marker atomically; existence of a just-opened file is not a readiness barrier.
        if (file_put_contents($input.'.ready.tmp', $marker, LOCK_EX) !== strlen($marker)
            || ! rename($input.'.ready.tmp', $input.'.ready')) {
            throw new RuntimeException('Test readiness publication failed.');
        }
        $deadline = microtime(true) + 20;
        while (! is_file(dirname($input).'/go') && microtime(true) < $deadline) {
            usleep(5000);
        }
        if (! is_file(dirname($input).'/go')) {
            throw new RuntimeException('Test barrier timed out.');
        }
    };
    if ($payload['action'] === 'lifecycle_transaction_boundary') {
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $deposit = Deposit::query()->findOrFail((int) $payload['deposit_id']);
        $withdrawal = Withdrawal::query()->findOrFail((int) $payload['withdrawal_id']);
        $transaction = FinancialTransaction::query()->findOrFail((int) $payload['transaction_id']);
        $db->beginTransaction();
        $table = $payload['table'];
        if (! in_array($table, ['deposits', 'withdrawals', 'financial_transactions'], true)) {
            throw new RuntimeException('Unsafe lifecycle test table.');
        }
        $lifecycleBoundarySnapshot = ['table' => $table, 'status' => $db->table($table)->where('id', $payload['entity_id'])->value('status')];
        $barrier();
        $code = null;
        $current = null;
        try {
            $result = LifecycleBoundaryOperation::run($payload['operation'], $deposit, $withdrawal, $transaction);
            $current = $result->status->value;
            $outcome = 'locked';
        } catch (FinancialException $exception) {
            $outcome = 'refused';
            $code = $exception->errorCode();
        }
        $answer = ['outcome' => $outcome, 'code' => $code, 'snapshot' => $lifecycleBoundarySnapshot,
            'current' => $current, 'level' => $db->transactionLevel(), 'physical' => $db->getPdo()->inTransaction()];
        $db->rollBack(0);
        echo json_encode($answer, JSON_THROW_ON_ERROR);

        return;
    }
    if ($payload['action'] === 'dispatch_accepted') {
        if (($payload['mode'] ?? null) !== 'pending') {
            throw new RuntimeException('Unsafe accepted-dispatch probe mode.');
        }
        $app->make('config')->set('payment.gateways.bank_transfer.enabled', true);
        $app->make('config')->set('payment.gateways.bank_transfer.supports_withdrawal', true);
        $app->instance(BankTransferGateway::class, new SyntheticWithdrawalGateway($app['config'], dirname($input), 'pending', fn () => $barrier(true)));
        $response = $app->make(WithdrawalDisbursementService::class)->disburse(Withdrawal::query()->findOrFail((int) $payload['withdrawal_id']));
        echo json_encode(['outcome' => 'dispatched', 'unknown' => $response->outcomeUnknown, 'pending' => $response->isPending,
            'synthetic_calls' => count(file(dirname($input).'/accepted.jsonl', FILE_IGNORE_NEW_LINES))], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if ($payload['action'] === 'reversal_transaction_boundary') {
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $original = FinancialTransaction::query()->findOrFail((int) $payload['original_id']);
        $db->beginTransaction();
        $reversalBoundarySnapshot = ReversalBoundaryOperation::snapshot((int) $payload['wallet_id'], $original->id);
        $barrier();
        $finish = $payload['finish'];
        $current = null;
        if ($finish === 'owned-commit') {
            $db->rollBack();
        } else {
            $wallet = $app->make(WalletLockService::class)->lock((int) $payload['wallet_id']);
            $current = $wallet->balance;
            if ($finish === 'commit') {
                $db->getPdo()->commit();
            } elseif ($finish === 'rollback') {
                $db->getPdo()->rollBack();
            }
        }
        $level = $db->transactionLevel();
        $physical = $db->getPdo()->inTransaction();
        $db->enableQueryLog();
        $db->flushQueryLog();
        $code = null;
        $result = null;
        try {
            $result = ReversalBoundaryOperation::run($payload['operation'], $original);
            $outcome = $result === null ? 'empty' : 'completed';
        } catch (FinancialException $exception) {
            $outcome = 'refused';
            $code = $exception->errorCode();
        }
        $queries = count($db->getQueryLog());
        $db->disableQueryLog();
        $answer = ['outcome' => $outcome, 'code' => $code, 'snapshot' => $reversalBoundarySnapshot,
            'current' => $current, 'level_before' => $level, 'physical_before' => $physical,
            'level_after' => $db->transactionLevel(), 'physical_after' => $db->getPdo()->inTransaction(),
            'queries' => $queries, 'compensation_id' => $result?->id];
        if ($finish === 'managed-commit') {
            $db->commit();
        } elseif ($finish !== 'owned-commit') {
            $db->rollBack(0);
        }
        echo json_encode($answer, JSON_THROW_ON_ERROR);

        return;
    }
    if ($payload['action'] === 'wallet_transaction_boundary') {
        // Exercise classic MySQL RR on this disposable MariaDB child only.
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $db->beginTransaction();
        $snapshot = (string) $db->table('wallets')->where('id', (int) $payload['wallet_id'])->value('balance');
        $walletBoundarySnapshot = bcadd($snapshot, '0', 2);
        $barrier();
        $wallet = $app->make(WalletLockService::class)->lockForDebit((int) $payload['wallet_id']);
        $current = $wallet->balance;
        $finish = $payload['finish'];
        if ($finish === 'commit') {
            $db->getPdo()->commit();
        } elseif ($finish === 'rollback') {
            $db->getPdo()->rollBack();
        }
        $level = $db->transactionLevel();
        $physical = $db->getPdo()->inTransaction();
        $db->enableQueryLog();
        $db->flushQueryLog();
        $code = null;
        try {
            WalletBoundaryOperation::run($payload['operation'], $wallet);
            $outcome = 'completed';
        } catch (FinancialException $exception) {
            $outcome = 'refused';
            $code = $exception->errorCode();
        }
        $queries = count($db->getQueryLog());
        $db->disableQueryLog();
        $answer = ['outcome' => $outcome, 'code' => $code, 'snapshot' => $walletBoundarySnapshot,
            'current' => $current, 'level_before' => $level, 'physical_before' => $physical,
            'level_after' => $db->transactionLevel(), 'physical_after' => $db->getPdo()->inTransaction(),
            'queries' => $queries];
        if ($finish === 'managed-commit') {
            $db->commit();
        } else {
            $db->rollBack(0);
        }
        echo json_encode($answer, JSON_THROW_ON_ERROR);

        return;
    }
    $snapshotUnlock = $payload['action'] === 'wallet_floor' && in_array(($payload['operation'] ?? null), ['snapshot_unlock', 'initial_snapshot_unlock'], true);
    $snapshotReversal = $payload['action'] === 'compensate' && ($payload['snapshot'] ?? false) === true;
    $snapshotOwners = null;
    $snapshotReversals = null;
    if ($snapshotUnlock || $snapshotReversal) {
        // MariaDB 11.8's optional snapshot-conflict abort would stop at the
        // wallet lock before exercising the classic MySQL RR stale-read case.
        // Dedicated disposable child/session ONLY; preserve and restore its mode.
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $db->beginTransaction();
        if ($snapshotUnlock) {
            $snapshotOwners = (int) $db->table(($payload['operation'] ?? null) === 'initial_snapshot_unlock' ? 'withdrawal_initial_holds' : 'withdrawal_reservations')->where('wallet_id', (int) $payload['wallet_id'])->count();
        } else {
            $snapshotReversals = (int) $db->table('financial_transactions')->where('type', 'reversal')->count();
        }
    }
    $conversionProofSnapshot = null;
    if (in_array($payload['action'], ['conversion_proof_replay_snapshot', 'conversion_proof_reverse_snapshot'], true)) {
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $db->beginTransaction();
        $conversionProofSnapshot = [
            'conversion_count' => (int) $db->table('wallet_conversions')->where('user_id', (int) $payload['owner_id'])->count(),
            'transaction_amount' => ($amount = $db->table('financial_transactions')->where('id', (int) ($payload['transaction_id'] ?? 0))->value('amount')) === null ? null : bcadd((string) $amount, '0', 2),
        ];
    }
    $conversionWalletSnapshot = null;
    if ($payload['action'] === 'conversion_wallet_snapshot') {
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $db->beginTransaction();
        $cachedWallets = Wallet::withTrashed()->where('user_id', (int) $payload['owner_id'])->where('currency', 'THB')
            ->whereIn('type', ['primary', 'win'])->orderBy('type')->get();
        $conversionWalletSnapshot = $cachedWallets->map(fn ($w) => [
            'type' => $w->getRawOriginal('type'), 'status' => $w->getRawOriginal('status'),
            'balance' => bcadd((string) $w->balance, '0', 2), 'held' => bcadd((string) $w->locked_balance, '0', 2),
            'locked' => $w->getRawOriginal('locked_at') !== null, 'deleted' => $w->getRawOriginal('deleted_at') !== null,
        ])->all();
    }
    $conversionMemberSnapshot = null;
    if ($payload['action'] === 'conversion_member_snapshot') {
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $db->beginTransaction();
        $cachedMember = User::query()->findOrFail((int) $payload['owner_id']);
        $cachedMember->load('kycDocuments', 'responsibleGamingLimit');
        $legacy = $cachedMember->responsibleGamingLimit;
        $document = $cachedMember->kycDocuments->sortByDesc('id')->first();
        $conversionMemberSnapshot = [
            'status' => $cachedMember->getRawOriginal('status'),
            'self_excluded_until' => $legacy?->getRawOriginal('self_excluded_until'),
            'cool_off_until' => $legacy?->getRawOriginal('cool_off_until'),
            'canonical_active_count' => $db->table('self_exclusions')->where('user_id', $cachedMember->id)->where('status', 'active')->count(),
            'kyc_status' => $cachedMember->kycStatus()->value,
            'kyc_expires_at' => $document?->getRawOriginal('expires_at'),
            'phone_verified' => $cachedMember->getRawOriginal('phone_verified_at') !== null,
        ];
    }
    $conversionQuoteSlotSnapshot = null;
    if ($payload['action'] === 'conversion_quote_slot_snapshot') {
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $db->beginTransaction();
        $conversionQuoteSlotSnapshot = $app->make(WalletConversionQuoteRepository::class)
            ->countOpenForUser((int) $payload['owner_id']);
    }
    $conversionLimitSnapshot = null;
    if ($payload['action'] === 'conversion_limit_snapshot') {
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $db->beginTransaction();
        $limitService = $app->make(ConversionLimitService::class);
        $conversionLimitSnapshot = [
            'daily' => $limitService->dailyTotal((int) $payload['owner_id'], Currency::THB),
            'monthly' => $limitService->monthlyTotal((int) $payload['owner_id'], Currency::THB),
        ];
    }
    $conversionAuthoritySnapshot = null;
    if ($payload['action'] === 'conversion_authority_snapshot') {
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $db->beginTransaction();
        $cachedActor = User::query()->findOrFail((int) $payload['actor_id']);
        $cachedActor->load('roles', 'permissions');
        $conversionAuthoritySnapshot = [
            'actor_status' => $cachedActor->status->value,
            'policy_allowed' => (new WalletConversionPolicy)->reverse($cachedActor,
                WalletConversion::query()->findOrFail((int) $payload['conversion_id'])),
        ];
    }
    // Synthetic context is prepared BEFORE the contention barrier. Only this isolated
    // test child can select a secondary wallet; public requests still cannot name one.
    if (in_array($payload['action'], ['refund_wager_context', 'refund_wager_snapshot', 'refund_withdrawal_request', 'refund_withdrawal_snapshot', 'refund_withdrawal_approve', 'refund_conversion', 'refund_conversion_snapshot'], true)) {
        $app->make('config')->set('payment.gateways.stripe.secret', 'sk_test_mock_secret_key_12345');
        $app->make('config')->set('payment.gateways.stripe.webhook_secret', 'whsec_mock_stripe_webhook_secret_67890');
        Http::preventStrayRequests();
    }
    if (in_array($payload['action'], ['refund_wager_snapshot', 'refund_withdrawal_snapshot', 'refund_conversion_snapshot'], true)) {
        if (str_contains(strtolower((string) $db->selectOne('SELECT VERSION() AS v')->v), 'mariadb')) {
            $snapshotMode = (int) $db->selectOne('SELECT @@SESSION.innodb_snapshot_isolation AS v')->v;
            $db->statement('SET SESSION innodb_snapshot_isolation = OFF');
        }
        $db->beginTransaction();
        $snapshotOwners = (int) $db->table(StripeRefundRecoveryService::INTENTS)->count();
    }
    if (in_array($payload['action'], ['wager_context', 'refund_wager_context', 'refund_wager_snapshot'], true)) {
        $context = $app->make(BetPurchaseValidator::class)->validate(
            new BetPurchaseData((int) $payload['user_id'], (int) $payload['draw_id'], '3d_direct', '007', '10.00', $payload['key']));
        $context = new BetPurchaseContext(...[...get_object_vars($context),
            'wallet' => Wallet::query()->findOrFail((int) $payload['wallet_id'])]);
    }
    if (str_starts_with($payload['action'], 'refund_withdrawal_')) {
        $app->make('config')->set('finance.withdrawal.min', '1.00');
    }
    if (str_starts_with($payload['action'], 'initial_withdrawal_')) {
        $app->make('config')->set('finance.withdrawal.min', '1.00');
        Http::preventStrayRequests();
    }
    $barrier(false, $snapshotOwners, $snapshotReversals);
    $engine = $app->make(WalletConversionServiceContract::class);
    $orchestrator = $app->make(ChunkedSettlementOrchestrator::class);
    switch ($payload['action']) {
        case 'commission_draw':
            $result = $app->make(AgentCommissionSettlementService::class)->settleForDraw((int) $payload['draw_id']);
            $answer = ['outcome' => 'completed', 'settled' => $result->commissionsSettled];
            break;
        case 'commission_agent':
            $result = $app->make(AgentSettlementService::class)->settleForAgent(Agent::query()->findOrFail((int) $payload['agent_id']));
            $answer = ['outcome' => 'completed', 'settled' => $result['settled_count']];
            break;
        case 'stripe_sweep_claim':
        case 'stripe_sweep_run':
        case 'stripe_sweep_retry':
            $app->make('config')->set('payment.gateways.stripe.enabled', true);
            $app->make('config')->set('payment.gateways.stripe.secret', 'sk_test_mock_secret_key_12345');
            $app->make('config')->set('payment.gateways.stripe.webhook_secret', 'whsec_mock_stripe_webhook_secret_67890');
            $app->make('config')->set('payment.deposit_review.stripe.enabled', true);
            $app->make('config')->set('payment.deposit_review.stripe.webhook_secret', 'whsec_SYNTHETIC_FOLLOWUP_ENDPOINT_12345678');
            $app->make('config')->set('payment.deposit_followup_routing.stripe.enabled', true);
            $app->make('config')->set('payment.deposit_followup_sweep.stripe.enabled', true);
            Http::preventStrayRequests();
            $actor = User::query()->findOrFail((int) $payload['actor_id']);
            $sweep = $app->make(StripeFollowupSweepService::class);
            if ($payload['action'] === 'stripe_sweep_claim') {
                $lease = $sweep->claimNext($actor);
                $answer = ['outcome' => $lease === null ? 'empty' : (isset($lease['token']) ? 'claimed' : 'held'),
                    'generation' => $lease['generation'] ?? null];
            } elseif ($payload['action'] === 'stripe_sweep_retry') {
                $answer = ['outcome' => 'requeued', 'work' => $sweep->retryHeld((int) $payload['inbox_id'], $actor, 'operational_retry')];
            } else {
                $answer = ['outcome' => 'processed', 'work' => $sweep->runLease($payload['lease'], $actor)];
            }
            break;
        case 'stripe_followup_route':
            $app->make('config')->set('payment.gateways.stripe.enabled', true);
            $app->make('config')->set('payment.gateways.stripe.secret', 'sk_test_mock_secret_key_12345');
            $app->make('config')->set('payment.gateways.stripe.webhook_secret', 'whsec_mock_stripe_webhook_secret_67890');
            $app->make('config')->set('payment.deposit_review.stripe.enabled', true);
            $app->make('config')->set('payment.deposit_review.stripe.webhook_secret', 'whsec_SYNTHETIC_FOLLOWUP_ENDPOINT_12345678');
            $app->make('config')->set('payment.deposit_followup_routing.stripe.enabled', true);
            Http::preventStrayRequests();
            $actor = User::query()->findOrFail((int) $payload['actor_id']);
            $result = $app->make(StripeFollowupRoutingService::class)->route((int) $payload['inbox_id'], $actor);
            $answer = ['outcome' => 'routed', 'routing' => $result];
            break;
        case 'stripe_refund_recovery':
            $app->make('config')->set('payment.gateways.stripe.enabled', true);
            $app->make('config')->set('payment.gateways.stripe.secret', 'sk_test_mock_secret_key_12345');
            $app->make('config')->set('payment.gateways.stripe.webhook_secret', 'whsec_mock_stripe_webhook_secret_67890');
            $app->make('config')->set('payment.deposit_review.stripe.enabled', true);
            $app->make('config')->set('payment.deposit_review.stripe.webhook_secret', 'whsec_SYNTHETIC_FOLLOWUP_ENDPOINT_12345678');
            $app->make('config')->set('payment.refund_recovery.stripe.enabled', true);
            $gets = 0;
            Http::preventStrayRequests();
            Http::fake(['api.stripe.com/*' => function ($request) use ($payload, $db, $input, &$gets) {
                if ($request->method() !== 'GET' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
                    throw new RuntimeException('Unsafe synthetic refund lookup.');
                }
                $gets++;
                $path = parse_url($request->url(), PHP_URL_PATH);
                $key = str_contains($path, '/refunds/') ? 'refund' : (str_contains($path, '/charges/') ? 'charge' : (str_contains($path, '/payment_intents/') ? 'intent' : 'list'));
                if ($key === 'list' && ($payload['two_http_arrivals'] ?? false)) {
                    file_put_contents($input.'.refund-ready', json_encode(['gets' => $gets, 'pdo_transaction' => $db->getPdo()->inTransaction()], JSON_THROW_ON_ERROR));
                    $deadline = microtime(true) + 10;
                    while (count(glob(dirname($input).'/*.refund-ready')) !== 2 && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    if (count(glob(dirname($input).'/*.refund-ready')) !== 2) {
                        throw new RuntimeException('Two independent refund response arrivals were not observed.');
                    }
                }

                return Http::response($payload['proof'][$key]);
            }]);
            $result = $app->make(StripeRefundRecoveryService::class)->recover(
                Deposit::query()->findOrFail((int) $payload['deposit_id']), User::query()->findOrFail((int) $payload['actor_id']), (int) $payload['inbox_id']);
            $answer = ['outcome' => 'refund_recovered', 'result' => $result, 'gets' => $gets];
            break;
        case 'stripe_review_inspect':
        case 'stripe_review_ack':
            $app->make('config')->set('payment.gateways.stripe.enabled', true);
            $app->make('config')->set('payment.gateways.stripe.secret', 'sk_test_mock_secret_key_12345');
            $app->make('config')->set('payment.gateways.stripe.webhook_secret', 'whsec_mock_stripe_webhook_secret_67890');
            $app->make('config')->set('payment.deposit_review.stripe.enabled', true);
            $app->make('config')->set('payment.deposit_review.stripe.webhook_secret', 'whsec_SYNTHETIC_FOLLOWUP_ENDPOINT_12345678');
            Http::preventStrayRequests();
            $review = $app->make(StripeDepositReviewService::class);
            $deposit = Deposit::query()->findOrFail((int) $payload['deposit_id']);
            $actor = User::query()->findOrFail((int) $payload['actor_id']);
            $result = $payload['action'] === 'stripe_review_inspect' ? $review->inspect($deposit, $actor)
                : $review->acknowledge($deposit, $actor, $payload['case_uuid'], (int) $payload['version'], $payload['evidence_hash'], $payload['note']);
            $answer = ['outcome' => 'reviewed', 'case' => $result];
            break;
        case 'stripe_deposit_settlement':
            $app->make('config')->set('payment.gateways.stripe.enabled', true);
            $app->make('config')->set('payment.gateways.stripe.secret', 'sk_test_mock_secret_key_12345');
            $app->make('config')->set('payment.gateways.stripe.webhook_secret', 'whsec_mock_stripe_webhook_secret_67890');
            $app->make('config')->set('payment.deposit_settlement.stripe.enabled', true);
            $gets = 0;
            Http::preventStrayRequests();
            Http::fake(['api.stripe.com/*' => function ($request) use ($payload, $db, $input, &$gets) {
                if ($request->method() !== 'GET' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
                    throw new RuntimeException('Unsafe synthetic capture lookup.');
                }
                $gets++;
                $path = parse_url($request->url(), PHP_URL_PATH);
                $key = str_contains($path, '/checkout/sessions/') ? 'session' : (str_contains($path, '/payment_intents/') ? 'intent' : (str_contains($path, '/charges/') ? 'charge' : 'refunds'));
                if ($key === 'refunds') {
                    file_put_contents($input.'.capture-ready', json_encode(['gets' => $gets, 'pdo_transaction' => $db->getPdo()->inTransaction()], JSON_THROW_ON_ERROR));
                    $deadline = microtime(true) + 10;
                    while (count(glob(dirname($input).'/*.capture-ready')) !== 2 && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    if (count(glob(dirname($input).'/*.capture-ready')) !== 2) {
                        throw new RuntimeException('Two provider-response arrivals were not observed.');
                    }
                }

                return Http::response($payload['proof'][$key]);
            }]);
            $result = $app->make(StripeDepositSettlementService::class)->reconcile(
                Deposit::query()->findOrFail((int) $payload['deposit_id']), User::query()->findOrFail((int) $payload['actor_id']));
            $answer = ['outcome' => 'capture_reconciled', 'credited' => $result['credited'], 'gets' => $gets];
            break;
        case 'checkout_lookup':
            $app->make('config')->set('payment.gateways.stripe.enabled', true);
            $app->make('config')->set('payment.gateways.stripe.secret', 'sk_test_mock_secret_key_12345');
            $app->make('config')->set('payment.gateways.stripe.webhook_secret', 'whsec_mock_stripe_webhook_secret_67890');
            $app->make('config')->set('payment.checkout_lookup.stripe.enabled', true);
            Http::preventStrayRequests();
            Http::fake(['api.stripe.com/*' => function ($request) use ($payload, $db) {
                if ($request->method() !== 'GET' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
                    throw new RuntimeException('Unsafe synthetic provider lookup.');
                }
                $d = Deposit::query()->findOrFail((int) $payload['deposit_id']);
                $a = $db->table('deposit_checkout_attempts')->where('deposit_id', $d->id)->first();
                $session = ['object' => 'checkout.session', 'id' => 'cs_test_NATIVELOOKUP123', 'mode' => 'payment',
                    'status' => 'open', 'livemode' => false, 'amount_total' => 10000, 'currency' => 'thb',
                    'client_reference_id' => $d->reference_number, 'created' => time(), 'expires_at' => time() + 3600,
                    'url' => 'https://checkout.stripe.com/c/pay/SYNTHETIC',
                    'metadata' => ['checkout_attempt' => $a->uuid, 'deposit_id' => (string) $d->id,
                        'deposit_reference' => $d->reference_number, 'user_id' => (string) $d->user_id, 'wallet_id' => (string) $d->wallet_id]];

                return Http::response(str_contains(explode('?', $request->url())[0], '/sessions/')
                    ? $session : ['object' => 'list', 'data' => [$session], 'has_more' => false]);
            }]);
            $result = $app->make(DepositCheckoutService::class)->reconcileStripe(
                Deposit::query()->findOrFail((int) $payload['deposit_id']), User::query()->findOrFail((int) $payload['actor_id']));
            $answer = ['outcome' => 'lookup_recovered', 'deposit_id' => $result['deposit']->id];
            break;
        case 'deposit_checkout':
            $app->make('config')->set('payment.gateways.stripe.enabled', true);
            $app->make('config')->set('payment.gateways.stripe.secret', 'SYNTHETIC-RACE-ONLY-KEY');
            SyntheticDepositCheckoutGateway::$logPath = dirname($input).'/checkout-acceptances.jsonl';
            SyntheticDepositCheckoutGateway::$loseAcknowledgement = (bool) ($payload['lose_acknowledgement'] ?? false);
            $app->bind(StripeGateway::class, SyntheticDepositCheckoutGateway::class);
            $wallet = Wallet::query()->findOrFail((int) $payload['wallet_id']);
            $checkout = $app->make(PaymentInitiationService::class)->initiateDeposit($wallet,
                Money::of('100.00', $wallet->currency), PaymentMethod::Stripe, $payload['key'], $payload['options'] ?? []);
            $answer = ['outcome' => 'checkout_projected', 'deposit_id' => $checkout['deposit']->id];
            break;
        case 'deposit_admission':
            if ($payload['operation'] === 'request') {
                $wallet = Wallet::query()->findOrFail((int) $payload['wallet_id']);
                $row = $app->make(DepositService::class)->request($wallet,
                    Money::of('100.00', $wallet->currency), PaymentMethod::BankTransfer, $payload['key']);
                $answer = ['outcome' => 'requested', 'deposit_id' => $row->id];
            } elseif ($payload['operation'] === 'complete') {
                $row = Deposit::query()->findOrFail((int) $payload['deposit_id']);
                $result = $app->make(DepositCompletionService::class)->complete($row,
                    options: ['provider_reference' => 'SYNTHETIC-DEPOSIT-'.$row->id]);
                $answer = ['outcome' => $result['replayed'] ? 'deposit_replayed' : 'credited', 'transaction_id' => $result['transaction']->id];
            } elseif ($payload['operation'] === 'lower') {
                $app->make(ResponsibleGamingLimitService::class)->choose(new ResponsibleGamingLimitData(
                    (int) $payload['user_id'], 'daily_deposit', '50.00', 'THB'));
                $answer = ['outcome' => 'lowered'];
            } else {
                throw new RuntimeException('Unknown synthetic deposit admission operation.');
            }
            break;
        case 'limit_lifecycle':
            $limits = $app->make(ResponsibleGamingLimitService::class);
            if ($payload['operation'] === 'activate') {
                $answer = ['outcome' => 'swept', 'activated' => $limits->activateDuePending()];
            } elseif ($payload['operation'] === 'summary') {
                $row = $app->make(ResponsibleGamingService::class)->setLimits(
                    User::query()->findOrFail((int) $payload['user_id']), singleBetLimit: '5.00');
                $answer = ['outcome' => 'summary', 'binding' => $row->single_bet_limit];
            } elseif ($payload['operation'] === 'pronounce') {
                $result = $limits->pronounce(new ResponsibleGamingLimitData(
                    (int) $payload['user_id'], 'single_bet', $payload['amount'], 'THB', requestKey: $payload['key']));
                $answer = ['outcome' => $result['replayed'] ? 'policy_replayed' : 'policy_created', 'version_id' => $result['version']->id];
            } else {
                throw new RuntimeException('Unknown synthetic limit lifecycle operation.');
            }
            break;
        case 'initial_withdrawal_request':
            $selected = Wallet::query()->findOrFail((int) $payload['wallet_id']);
            $row = $app->make(WithdrawalRequestWithHoldService::class)->request(
                $selected, Money::of('10', $selected->currency), PaymentMethod::Manual, $payload['key']);
            $answer = ['outcome' => 'requested', 'withdrawal_id' => $row->id];
            break;
        case 'initial_withdrawal_approve':
            $app->make(WithdrawalApprovalService::class)->approve(Withdrawal::query()->findOrFail((int) $payload['withdrawal_id']));
            $answer = ['outcome' => 'reserved'];
            break;
        case 'initial_withdrawal_cancel':
            $app->make(WithdrawalService::class)->cancel(Withdrawal::query()->findOrFail((int) $payload['withdrawal_id']));
            $answer = ['outcome' => 'cancelled'];
            break;
        case 'refund_withdrawal_request':
        case 'refund_withdrawal_snapshot':
            $selected = Wallet::query()->findOrFail((int) $payload['wallet_id']);
            $row = $app->make(WithdrawalService::class)->request(
                $selected, Money::of('10', $selected->currency), PaymentMethod::Manual, $payload['key']);
            if ($payload['action'] === 'refund_withdrawal_snapshot') {
                $db->commit();
            }
            $answer = ['outcome' => 'requested', 'withdrawal_id' => $row->id];
            break;
        case 'refund_withdrawal_approve':
            $row = Withdrawal::query()->findOrFail((int) $payload['withdrawal_id']);
            $app->make(WithdrawalApprovalService::class)->approve($row);
            $answer = ['outcome' => 'reserved', 'withdrawal_id' => $row->id];
            break;
        case 'refund_wager_snapshot':
            $wallet = $app->make(WalletLockService::class)->lock((int) $payload['wallet_id']);
            $app->make(BetPurchaseAdmission::class)->assertAllowed($context, $wallet);
            $answer = ['outcome' => 'admission_allowed'];
            $db->rollBack();
            break;
        case 'refund_wager_context':
        case 'wager_context':
            $result = $app->make(BetPurchaseTransactionService::class)->execute($context);
            $answer = ['outcome' => 'purchased', 'transaction_id' => $result->transaction->id];
            break;
        case 'wager_policy':
            if ($payload['policy'] === 'limit') {
                $app->make(ResponsibleGamingLimitService::class)
                    ->setSingleBetLimit((int) $payload['user_id'], '5.00');
            } elseif ($payload['policy'] === 'exclude') {
                $app->make(SelfExclusionService::class)->activate((int) $payload['exclusion_id']);
            } else {
                throw new RuntimeException('Unknown synthetic wager policy.');
            }
            $answer = ['outcome' => 'policy_changed'];
            break;
        case 'risk_reserve':
            $db->transaction(function () use ($app, $payload): void {
                $id = (int) $payload['draw_id'];
                $entry = ['number' => '007', 'stake' => Money::of('10.00', Currency::THB), 'multiplier' => '1'];
                match ($payload['path']) {
                    'single' => $app->make(NumberLimitEngine::class)->reserve($id, BetType::ThreeD, '007', $entry['stake'], '1'),
                    'many' => $app->make(NumberLimitEngine::class)->reserveMany($id, BetType::ThreeD, [$entry]),
                    'facade' => $app->make(RiskAssessmentService::class)->reserveBet($id, BetType::ThreeD,
                        [['number' => '007', 'stake' => '10.00', 'multiplier' => '1']]),
                    default => throw new RuntimeException('Unknown synthetic risk path.'),
                };
            }, 1);
            $answer = ['outcome' => 'risk_reserved'];
            break;
        case 'draw_cancel':
            $result = $app->make(DrawCancellationService::class)->cancel((int) $payload['actor_id'], (int) $payload['draw_id'], 'Synthetic local operator cancellation');
            $answer = ['outcome' => $result['replayed'] ? 'draw_replayed' : 'draw_cancelled', 'ownership_id' => $result['ownership_id'],
                'transaction_ids' => $result['refund_transaction_ids'], 'newly_refunded_count' => $result['newly_refunded_count']];
            break;
        case 'purchase_replay':
            $result = $app->make(BetPurchaseService::class)->purchase(
                new BetPurchaseData((int) $payload['user_id'], (int) $payload['draw_id'], '3d_direct', '007', '10.00', $payload['key']));
            $answer = ['outcome' => $result->isReplay() ? 'replayed' : 'purchased', 'transaction_id' => $result->transaction->id];
            break;
        case 'bet_amend':
            $result = $app->make(BetAmendmentService::class)->amend(
                new BetAmendmentData((int) $payload['user_id'], (string) $payload['bet_id'], '008', '12.00', $payload['key']));
            $answer = ['outcome' => $result->isApplied() ? 'applied' : 'failed', 'replacement_id' => $result->replacementBet?->id];
            break;
        case 'bet_cancel':
            $result = $app->make(BetCancellationService::class)->cancel(
                new BetCancellationData((int) $payload['user_id'], (string) $payload['bet_id'], BetCancellationReason::PlayerRequest));
            $answer = ['outcome' => $result->isReplay() ? 'replayed' : 'cancelled', 'transaction_id' => (int) $result->refund->id];
            break;
        case 'compensate':
            $tx = $app->make(FinancialReversalService::class)->reverse(
                FinancialTransaction::query()->findOrFail((int) $payload['transaction_id']),
                idempotencyKey: $payload['key'] ?? null,
            );
            if ($snapshotReversal) {
                $db->commit();
            }
            $answer = ['outcome' => 'reversed', 'transaction_id' => (int) $tx->id];
            break;
        case 'compensate_spend':
            $wallet = Wallet::query()->findOrFail((int) $payload['wallet_id']);
            $app->make(WalletService::class)->debit($wallet, Money::of('100.00', $wallet->currency),
                FinancialTransactionType::Adjustment, 'synthetic-compensation-spend');
            $answer = ['outcome' => 'spent'];
            break;
        case 'wallet_floor':
            $wallet = Wallet::query()->findOrFail((int) $payload['wallet_id']);
            $amount = Money::of('100.00', $wallet->currency);
            $wallets = $app->make(WalletService::class);
            $outcome = match ($payload['operation']) {
                'initial_snapshot_unlock' => $wallets->unlockFunds($wallet, Money::of('1', $wallet->currency)),
                'unlock', 'snapshot_unlock' => $wallets->unlockFunds($wallet, $amount),
                'hold' => $wallets->lockFunds($wallet, $amount),
                'consume' => $db->transaction(fn () => $app->make(WalletHoldService::class)->consume($wallet, $amount)),
                'credit' => $wallets->credit($wallet, $amount, FinancialTransactionType::Deposit, 'synthetic-floor-credit'),
                'debit' => $wallets->debit($wallet, Money::of('900.00', $wallet->currency),
                    FinancialTransactionType::Adjustment, 'synthetic-floor-debit'),
                'complete' => $app->make(WithdrawalCompletionService::class)->complete(
                    Withdrawal::query()->findOrFail((int) $payload['withdrawal_id'])),
                default => throw new RuntimeException('Unknown floor probe operation.'),
            };
            if ($snapshotUnlock) {
                $db->commit();
            }
            $answer = ['outcome' => 'completed'];
            break;
        case 'reconcile':
            $case = WithdrawalReconciliation::query()->findOrFail((int) $payload['case_id']);
            $checker = User::query()->findOrFail((int) $payload['checker_id']);
            $result = $app->make(WithdrawalReconciliationService::class)->decide(
                $checker, $case, 'apply', 'Independently verified synthetic server-race evidence.', true,
            );
            $answer = ['outcome' => $result->status];
            break;
        case 'dispatch':
            if (! in_array($payload['mode'] ?? null, ['pending', 'timeout'], true)) {
                throw new RuntimeException('Unsafe synthetic dispatch mode.');
            }
            $app->make('config')->set('payment.gateways.bank_transfer.enabled', true);
            $app->make('config')->set('payment.gateways.bank_transfer.supports_withdrawal', true);
            $app->instance(BankTransferGateway::class,
                new SyntheticWithdrawalGateway($app['config'], dirname($input), $payload['mode']));
            $response = $app->make(WithdrawalDisbursementService::class)->disburse(
                Withdrawal::query()->findOrFail((int) $payload['withdrawal_id']),
            );
            $journal = dirname($input).'/accepted.jsonl';
            $answer = ['outcome' => 'dispatched', 'unknown' => $response->outcomeUnknown, 'pending' => $response->isPending,
                'synthetic_calls' => is_file($journal) ? count(file($journal, FILE_IGNORE_NEW_LINES)) : 0];
            break;
        case 'webhook':
            $facts = $payload['webhook'];
            $result = $app->make(PaymentWebhookService::class)->processWebhookPayload(
                new WebhookPayload(
                    gateway: $facts['gateway'], eventId: $facts['event'],
                    eventType: WebhookEventType::from($facts['type']),
                    providerReference: $facts['provider'], internalReference: $facts['internal'],
                    amount: $facts['amount'], currency: Currency::from($facts['currency']),
                    isSuccess: $facts['success'],
                ),
            );
            $answer = ['outcome' => $result->actionTaken, 'success' => $result->success, 'replayed' => $result->replayed,
                'message' => $result->message, 'code' => $result->metadata['error_code'] ?? null];
            break;
        case 'conversion_wallet_snapshot':
        case 'conversion_member_snapshot':
            $request = ConversionRequestData::fromArray($payload['request']);
            $result = ($payload['mode'] ?? null) === 'quote' ? $engine->quote($request) : $engine->convert($request);
            $db->commit();
            $answer = ['outcome' => ($payload['mode'] ?? null) === 'quote' ? 'quoted' : ($result->replayed ? 'replayed' : 'completed'),
                'reference' => $result->reference];
            break;
        case 'conversion_quote_slot_snapshot':
            $result = $engine->quote(ConversionRequestData::fromArray($payload['request']));
            $db->commit();
            $answer = ['outcome' => 'quoted', 'reference' => $result->reference];
            break;
        case 'refund_conversion':
        case 'refund_conversion_snapshot':
        case 'conversion_limit_snapshot':
        case 'conversion_proof_replay_snapshot':
        case 'convert':
            $result = $engine->convert(ConversionRequestData::fromArray($payload['request']));
            if (in_array($payload['action'], ['refund_conversion_snapshot', 'conversion_proof_replay_snapshot', 'conversion_limit_snapshot'], true)) {
                $db->commit();
            }
            $answer = ['outcome' => $result->replayed ? 'replayed' : 'completed', 'reference' => $result->reference];
            break;
        case 'conversion_authority_snapshot':
        case 'conversion_proof_reverse_snapshot':
        case 'reverse':
            $result = $engine->reverse((int) $payload['conversion_id'],
                new ConversionAuditContextData(WalletConversionChannel::Admin, (int) $payload['actor_id']),
                'Synthetic authorized reversal race.', $payload['key']);
            if (in_array($payload['action'], ['conversion_proof_reverse_snapshot', 'conversion_authority_snapshot'], true)) {
                $db->commit();
            }
            $answer = ['outcome' => $result->replayed ? 'replayed' : 'completed', 'reference' => $result->reference];
            break;
        case 'start':
            $run = $orchestrator->start((int) $payload['draw_id'], 2);
            $answer = ['outcome' => 'started', 'run_id' => (int) $run->id];
            break;
        case 'chunk':
            $run = SettlementRun::query()->findOrFail((int) $payload['run_id']);
            $run = $orchestrator->runChunk($run, (int) $payload['cursor']);
            $answer = ['outcome' => 'chunked', 'run_id' => (int) $run->id, 'bets_settled' => (int) $run->bets_settled];
            break;
        case 'inline_settlement':
            $app->make(RealPrizeSettlementService::class)->settle((int) $payload['draw_id']);
            $answer = ['outcome' => 'inline_settled'];
            break;
        case 'settlement_reopen':
            $run = $orchestrator->reopen(SettlementRun::query()->findOrFail((int) $payload['run_id']));
            $answer = ['outcome' => 'reopened_or_read', 'status' => (string) $run->status];
            break;
        case 'settlement_finalize':
            $run = $orchestrator->finalize(SettlementRun::query()->findOrFail((int) $payload['run_id']));
            $answer = ['outcome' => 'finalized', 'status' => (string) $run->status];
            break;
        case 'worker':
            $app->make('queue.worker')->runNextJob('database', QueueName::workerQueueString(),
                new WorkerOptions(sleep: 0, maxTries: 2, timeout: 180));
            $answer = ['outcome' => 'worked'];
            break;
        default:
            throw new RuntimeException('Unknown test action.');
    }
    echo json_encode($answer, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (isset($db) && $db->transactionLevel() > 0) {
        $db->rollBack();
    }
    // Do not print SQL, keys, exception context or arbitrary exception messages.
    echo json_encode(['outcome' => 'refused', 'code' => $exception instanceof ResponsibleGamingLimitException || $exception instanceof SelfExclusionException || $exception instanceof FinancialException || $exception instanceof BetDomainException || $exception instanceof DrawLifecycleException || $exception instanceof RiskException || $exception instanceof SettlementSimulationException
        ? $exception->errorCode() : null, 'attempted' => $exception instanceof ConversionLimitExceededException ? $exception->attempted() : null, 'exception' => $exception::class, 'sqlstate' => $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null,
        'table' => $exception instanceof QueryException ? (preg_match('/from `?(wallets|withdrawals|deposits|payments)`?/', $exception->getSql(), $diagnostic) ? $diagnostic[1] : null) : null,
        'statement' => $exception instanceof QueryException ? strtok($exception->getSql(), ' ') : null,
        'driver_code' => $exception instanceof QueryException ? ($exception->errorInfo[1] ?? null) : null], JSON_THROW_ON_ERROR);
} finally {
    if (isset($snapshotMode, $db)) {
        try {
            $db->statement('SET SESSION innodb_snapshot_isolation = '.($snapshotMode === 1 ? 'ON' : 'OFF'));
        } catch (Throwable) {
            fwrite(STDERR, 'Disposable snapshot probe session restoration failed.');
            exit(2);
        }
    }
}
