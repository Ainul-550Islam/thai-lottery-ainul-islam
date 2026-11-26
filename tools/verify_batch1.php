<?php

declare(strict_types=1);

/**
 * Batch 1 static verifier. Run from the repository root:
 *
 *     php tools/verify_batch1.php            # auto-detects zip-only vs full repo
 *     php tools/verify_batch1.php --repo     # force full-repo mode (externals MUST resolve)
 *
 * Exit code 0 = every check passed. Exit code 1 = at least one FAIL.
 * It needs only PHP; it never boots Laravel and never touches a database.
 */

$root = getcwd();
$forceRepo = in_array('--repo', $argv, true);
$repoMode = $forceRepo || is_file($root.'/app/Models/Bet.php');

$pass = 0;
$fail = 0;
$notes = [];

function ok(string $msg): void
{
    global $pass;
    $pass++;
    echo "  PASS  $msg\n";
}

function bad(string $msg): void
{
    global $fail;
    $fail++;
    echo "  FAIL  $msg\n";
}

function section(string $title): void
{
    echo "\n== $title\n";
}

function check(bool $cond, string $okMsg, string $badMsg): void
{
    $cond ? ok($okMsg) : bad($badMsg);
}

function src(string $rel): string
{
    global $root;
    $p = $root.'/'.$rel;

    return is_file($p) ? (string) file_get_contents($p) : '';
}

/** Strip comments so greps never match docblocks. */
function code(string $source): string
{
    $out = '';
    foreach (PhpToken::tokenize($source) as $t) {
        if ($t->id === T_COMMENT || $t->id === T_DOC_COMMENT) {
            $out .= str_repeat("\n", substr_count($t->text, "\n"));

            continue;
        }
        $out .= $t->text;
    }

    return $out;
}

function allPhp(string $dir): array
{
    global $root;
    $base = $root.'/'.$dir;
    if (! is_dir($base)) {
        return [];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = substr($f->getPathname(), strlen($root) + 1);
        }
    }
    sort($files);

    return $files;
}

// ---------------------------------------------------------------------------
// 1. File tree required by the Batch 1 prompt
// ---------------------------------------------------------------------------
section('1. Required file tree (prompt section 2)');

$required = [
    'config/rewards.php',
    'database/seeders/CashbackPolicySeeder.php', 'database/seeders/RewardCatalogSeeder.php',
    'app/Enums/CashbackAccrualStatus.php', 'app/Enums/RewardType.php', 'app/Enums/RewardClaimStatus.php',
    'app/Enums/FreeBetVoucherStatus.php', 'app/Enums/BankAccountStatus.php', 'app/Enums/LoginEventOutcome.php',
    'app/Models/CashbackPolicy.php', 'app/Models/CashbackAccrual.php', 'app/Models/RewardCatalogItem.php',
    'app/Models/RewardClaim.php', 'app/Models/FreeBetVoucher.php', 'app/Models/MemberBankAccount.php', 'app/Models/LoginEvent.php',
    'app/Services/Cashback/CashbackCalculator.php', 'app/Services/Cashback/CashbackAccrualService.php',
    'app/Services/Cashback/CashbackClaimService.php', 'app/Services/Cashback/CashbackBalanceReader.php',
    'app/Services/Rewards/RewardCatalogService.php', 'app/Services/Rewards/RewardRedemptionService.php',
    'app/Services/Rewards/RewardFulfillerRegistry.php', 'app/Services/Rewards/Fulfillers/BonusCashFulfiller.php',
    'app/Services/Rewards/Fulfillers/CashbackBoostFulfiller.php', 'app/Services/Rewards/Fulfillers/FreeBetFulfiller.php',
    'app/Services/Rewards/Fulfillers/VipUpgradeFulfiller.php', 'app/Services/Rewards/FreeBetVoucherService.php',
    'app/Services/Rewards/VipProgressService.php',
    'app/Services/Security/LoginHistory/LoginEventRecorder.php', 'app/Services/Security/LoginHistory/LoginHistoryQueryService.php',
    'app/Services/Security/LoginHistory/NewDeviceDetector.php', 'app/Services/Security/LoginHistory/GeoIp/GeoIpResolver.php',
    'app/Services/Security/LoginHistory/GeoIp/NullGeoIpResolver.php', 'app/Services/Security/LoginHistory/GeoIp/GeoIpResolverManager.php',
    'app/Services/Account/MemberBankAccountService.php', 'app/Services/Affiliate/ReferralCenterService.php',
    'app/Services/Affiliate/ReferralPayoutRequestService.php', 'app/Services/Home/PlayerDashboardSummaryService.php',
    'app/Providers/RewardsServiceProvider.php', 'app/Jobs/Cashback/AccrueCashbackJob.php',
    'app/Jobs/Rewards/ExpireRewardArtifactsJob.php', 'app/Console/Commands/Cashback/AccrueCashbackCommand.php',
    'app/Console/Commands/Security/PruneLoginEventsCommand.php', 'app/Events/Rewards/RewardClaimed.php',
    'app/Events/Security/NewDeviceLoginDetected.php', 'app/Listeners/Security/RecordLoginEvent.php',
    'app/Listeners/Security/NotifyOnNewDeviceLogin.php',
    'app/Http/Controllers/Api/V1/CashbackController.php', 'app/Http/Controllers/Api/V1/RewardController.php',
    'app/Http/Controllers/Api/V1/LoginHistoryController.php', 'app/Http/Controllers/Api/V1/BankAccountController.php',
    'app/Http/Controllers/Api/V1/ReferralCenterController.php', 'app/Http/Controllers/Api/V1/DashboardSummaryController.php',
    'app/Http/Requests/Api/V1/ClaimCashbackRequest.php', 'app/Http/Requests/Api/V1/ClaimRewardRequest.php',
    'app/Http/Requests/Api/V1/StoreBankAccountRequest.php', 'app/Http/Requests/Api/V1/ReferralPayoutRequest.php',
    'app/Http/Resources/CashbackSummaryResource.php', 'app/Http/Resources/RewardCatalogItemResource.php',
    'app/Http/Resources/RewardClaimResource.php', 'app/Http/Resources/LoginEventResource.php',
    'app/Http/Resources/MemberBankAccountResource.php', 'app/Http/Resources/ReferralCenterResource.php',
    'app/Http/Resources/DashboardSummaryResource.php',
    'app/Policies/MemberBankAccountPolicy.php', 'app/Policies/RewardClaimPolicy.php',
    'app/Filament/Resources/CashbackPolicyResource.php', 'app/Filament/Resources/RewardCatalogItemResource.php',
    'app/Filament/Resources/MemberBankAccountResource.php',
    'tests/Unit/Cashback/CashbackCalculatorTest.php', 'tests/Feature/Cashback/CashbackAccrualIdempotencyTest.php',
    'tests/Feature/Cashback/CashbackClaimTest.php', 'tests/Feature/Cashback/CashbackClaimConcurrencyTest.php',
    'tests/Feature/Rewards/RewardRedemptionTest.php', 'tests/Feature/Rewards/RewardRedemptionAtomicityTest.php',
    'tests/Feature/Rewards/RewardIdempotencyTest.php', 'tests/Feature/Rewards/FreeBetVoucherServiceTest.php',
    'tests/Feature/Rewards/VipProgressTest.php', 'tests/Feature/Security/LoginHistoryTest.php',
    'tests/Feature/Security/NewDeviceDetectionTest.php', 'tests/Feature/Account/BankAccountTest.php',
    'tests/Feature/Account/WithdrawalBankAccountLinkTest.php', 'tests/Feature/Affiliate/ReferralCenterTest.php',
    'tests/Feature/Affiliate/ReferralPayoutRequestTest.php', 'tests/Feature/Home/DashboardSummaryTest.php',
    'tests/Architecture/Batch1OwnershipTest.php',
];
$missing = array_values(array_filter($required, static fn (string $f): bool => ! is_file($root.'/'.$f)));
check($missing === [], count($required).' required files present', 'missing: '.implode(', ', $missing));

$migrations = [
    'create_cashback_policies_table', 'create_cashback_accruals_table', 'create_reward_catalog_items_table',
    'create_reward_claims_table', 'create_free_bet_vouchers_table', 'create_member_bank_accounts_table',
    'create_login_events_table', 'add_member_bank_account_id_to_withdrawal_requests',
];
$migFiles = allPhp('database/migrations');
foreach ($migrations as $m) {
    $found = array_filter($migFiles, static fn (string $f): bool => str_ends_with($f, $m.'.php'));
    check($found !== [], "migration $m", "migration $m MISSING");
}

// ---------------------------------------------------------------------------
// 2. Syntax
// ---------------------------------------------------------------------------
section('2. php -l on every PHP file in the batch');

$batchDirs = ['app', 'config', 'database', 'routes', 'tests', 'bootstrap'];
$phpFiles = [];
foreach ($batchDirs as $d) {
    $phpFiles = array_merge($phpFiles, allPhp($d));
}
$lintFails = [];
if (! $repoMode) {
    foreach ($phpFiles as $f) {
        $out = [];
        $rc = 0;
        exec('php -l '.escapeshellarg($root.'/'.$f).' 2>&1', $out, $rc);
        if ($rc !== 0) {
            $lintFails[] = $f;
        }
    }
    check($lintFails === [], count($phpFiles).' files lint clean', 'syntax errors: '.implode(', ', $lintFails));
} else {
    // In a full repo only lint the batch files (linting 5k files is not the point).
    $batchFiles = array_merge($required, array_map(static fn (string $f): string => $f, $migFiles));
    foreach ($batchFiles as $f) {
        if (! is_file($root.'/'.$f)) {
            continue;
        }
        $out = [];
        $rc = 0;
        exec('php -l '.escapeshellarg($root.'/'.$f).' 2>&1', $out, $rc);
        if ($rc !== 0) {
            $lintFails[] = $f;
        }
    }
    check($lintFails === [], 'batch files lint clean', 'syntax errors: '.implode(', ', $lintFails));
}

// ---------------------------------------------------------------------------
// 3. Class map, duplicate definitions, `use` resolution
// ---------------------------------------------------------------------------
section('3. Class map and imports');

$classMap = [];
$dupes = [];
foreach (array_merge(allPhp('app'), allPhp('database')) as $f) {
    $s = src($f);
    if (! preg_match('/^namespace\s+([^;]+);/m', $s, $ns)) {
        continue;
    }
    if (preg_match_all('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|enum|trait)\s+(\w+)/m', $s, $m)) {
        foreach ($m[1] as $name) {
            $fqcn = $ns[1].'\\'.$name;
            if (isset($classMap[$fqcn])) {
                $dupes[] = $fqcn;
            }
            $classMap[$fqcn] = $f;
        }
    }
}
check($dupes === [], 'no class defined twice', 'duplicate classes: '.implode(', ', $dupes));

$external = [];
foreach (array_merge(allPhp('app'), allPhp('database'), allPhp('tests'), allPhp('routes'), ['bootstrap/providers.php']) as $f) {
    if (! is_file($root.'/'.$f)) {
        continue;
    }
    if (preg_match_all('/^use\s+((?:App|Database)\\\\[^;\s]+?)(?:\s+as\s+\w+)?;/m', src($f), $m)) {
        foreach ($m[1] as $fqcn) {
            if (! isset($classMap[$fqcn])) {
                $external[$fqcn][] = $f;
            }
        }
    }
}
if ($repoMode) {
    check($external === [], 'every App\\ import resolves in the repository', 'unresolved: '.implode(', ', array_keys($external)));
} else {
    ok(count($external).' App\\ classes live outside this zip (they must exist in your repo; run with --repo there)');
    file_put_contents($root.'/tools/external_dependencies.txt', implode("\n", array_keys($external))."\n");
}

// ---------------------------------------------------------------------------
// 4. config('rewards.*') keys exist; env keys documented
// ---------------------------------------------------------------------------
section('4. Config keys and env files');

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}
$cfg = require $root.'/config/rewards.php';
$flat = [];
$walk = static function (array $a, string $p) use (&$walk, &$flat): void {
    foreach ($a as $k => $v) {
        $path = $p === '' ? (string) $k : $p.'.'.$k;
        $flat[$path] = true;
        if (is_array($v) && $v !== [] && ! array_is_list($v)) {
            $walk($v, $path);
        }
    }
};
$walk($cfg, '');

$unknownKeys = [];
foreach (array_merge(allPhp('app'), allPhp('database')) as $f) {
    if (preg_match_all("/config\(\s*'rewards\.([A-Za-z0-9_.]+)'/", code(src($f)), $m)) {
        foreach ($m[1] as $key) {
            if (! isset($flat['rewards.'.$key]) && ! isset($flat[$key])) {
                $unknownKeys[$key] = $f;
            }
        }
    }
}
check($unknownKeys === [], 'every config(\'rewards.*\') key is defined in config/rewards.php', 'undefined keys: '.json_encode($unknownKeys));

$cfgSrc = src('config/rewards.php');
preg_match_all("/env\('(REWARDS_[A-Z0-9_]+)'/", $cfgSrc, $envm);
$envNames = array_unique($envm[1]);
foreach (['.env.example', '.env.ci', '.env.production.example'] as $envFile) {
    $text = src($envFile);
    $absent = array_values(array_filter($envNames, static fn (string $n): bool => ! preg_match('/^'.preg_quote($n, '/').'=/m', $text)));
    check($absent === [], "$envFile documents all ".count($envNames).' REWARDS_* variables', "$envFile lacks: ".implode(', ', $absent));
}

// ---------------------------------------------------------------------------
// 5. Real-money rules
// ---------------------------------------------------------------------------
section('5. Real-money rules (hard rules 2,3,4,5,8)');

$batchCode = array_merge(
    allPhp('app/Services/Cashback'), allPhp('app/Services/Rewards'), allPhp('app/Services/Security'),
    allPhp('app/Services/Account'), allPhp('app/Services/Affiliate'), allPhp('app/Services/Home'),
    allPhp('app/Http/Controllers/Api/V1'), allPhp('app/Http/Requests/Api/V1'), allPhp('app/Http/Resources'),
    allPhp('app/Jobs'), allPhp('app/Console/Commands'), allPhp('app/Listeners'), allPhp('app/Filament'),
    allPhp('app/Models'), allPhp('app/Providers'), allPhp('database/seeders'),
);
$batchCode = array_values(array_filter(
    $batchCode,
    static fn (string $f): bool => ! in_array(basename($f), ['User.php', 'Withdrawal.php', 'WithdrawalRequest.php', 'WithdrawalController.php'], true),
));

$forbidden = [
    '/\(\s*(float|double|real)\s*\)|\bfloatval\s*\(/i' => 'float cast (money must be string/bc)',
    "/->\s*(increment|decrement)\s*\(\s*['\"](balance|locked_balance)['\"]/" => 'direct balance increment/decrement',
    "/DB::table\(\s*['\"]wallets['\"]\s*\)/" => 'raw wallets table access',
    "/UPDATE\s+wallets|SET\s+balance/i" => 'raw SQL on balance',
    "/->\s*balance\s*=[^=]/" => 'assignment to ->balance',
    "/'in:THB,USD/" => 'hardcoded currency list',
];
foreach ($forbidden as $regex => $label) {
    $hits = [];
    foreach ($batchCode as $f) {
        if (preg_match($regex, code(src($f)))) {
            $hits[] = $f;
        }
    }
    check($hits === [], "none: $label", "$label in ".implode(', ', $hits));
}

// Every file that credits/debits a wallet must carry the full money pattern.
$moneyFiles = [];
foreach ($batchCode as $f) {
    $c = code(src($f));
    if (preg_match('/->\s*(credit|debit)\s*\(/', $c)) {
        $moneyFiles[] = $f;
    }
}
foreach ($moneyFiles as $f) {
    $c = code(src($f));
    $needs = [
        'idempotencyKey' => (bool) preg_match('/idempotencyKey\s*:/', $c),
        'wallet lock' => (bool) preg_match('/walletLocks->lock\(/', $c),
        'ledger/financial tx type' => (bool) preg_match('/FinancialTransactionType::/', $c),
        'Money value object' => (bool) preg_match('/Money::of\(/', $c),
    ];
    $lacking = array_keys(array_filter($needs, static fn (bool $v): bool => ! $v));
    check($lacking === [], "$f: money pattern complete", "$f lacks: ".implode(', ', $lacking));
}

// Audit + DB transaction on the services that move money or points.
foreach ([
    'app/Services/Cashback/CashbackClaimService.php', 'app/Services/Cashback/CashbackAccrualService.php',
    'app/Services/Rewards/RewardRedemptionService.php', 'app/Services/Rewards/RewardClaimReviewService.php',
    'app/Services/Account/MemberBankAccountService.php', 'app/Services/Rewards/FreeBetVoucherService.php',
] as $f) {
    $c = code(src($f));
    check(
        str_contains($c, 'DB::transaction') && str_contains($c, 'lockForUpdate') && str_contains($c, 'AuditLog::query()->create'),
        "$f: transaction + row lock + audit log",
        "$f lacks transaction/lockForUpdate/audit",
    );
}

// RealMoneyGate on the member-facing credit paths; never a second gate class.
foreach (['app/Services/Cashback/CashbackClaimService.php', 'app/Services/Rewards/RewardRedemptionService.php'] as $f) {
    check(str_contains(src($f), 'RealMoneyGate'), "$f calls RealMoneyGate", "$f does not call RealMoneyGate");
}
$gateDup = array_filter($batchCode, static fn (string $f): bool => (bool) preg_match('/class\s+\w*Gate\b/', src($f)));
check($gateDup === [], 'no second gate class invented', 'gate classes: '.implode(', ', $gateDup));

// Lock order in CashbackClaimService: member -> accrual rows -> wallet.
$claim = code(src('app/Services/Cashback/CashbackClaimService.php'));
$pUser = strpos($claim, "User::query()->whereKey(\$member->getKey())->lockForUpdate()");
$pRows = strpos($claim, '->claimable($now)');
$pWallet = strpos($claim, 'walletLocks->lock(');
check(
    $pUser !== false && $pRows !== false && $pWallet !== false && $pUser < $pRows && $pRows < $pWallet,
    'CashbackClaimService lock order: member -> accrual rows -> wallet',
    'CashbackClaimService lock order is wrong',
);
$accr = code(src('app/Services/Cashback/CashbackAccrualService.php'));
$rUser = strpos($accr, 'public function reverse');
$rBody = $rUser === false ? '' : substr($accr, $rUser);
check(
    $rBody !== '' && strpos($rBody, 'lockForUpdate') < strpos($rBody, 'walletLocks->lock('),
    'reverse(): accrual rows locked before the wallet (same order as claim)',
    'reverse() lock order differs from claim()',
);

// Calculator purity.
check(
    ! preg_match('/Illuminate\\\\Database|App\\\\Models|WalletType|DB::/', src('app/Services/Cashback/CashbackCalculator.php')),
    'CashbackCalculator is pure (no DB, models or wallets)',
    'CashbackCalculator touches DB/models/wallets',
);

// Single owner of each table.
$owners = [
    'CashbackAccrual' => 'app/Services/Cashback/CashbackAccrualService.php',
    'RewardClaim' => 'app/Services/Rewards/RewardRedemptionService.php',
    'LoginEvent' => 'app/Services/Security/LoginHistory/LoginEventRecorder.php',
];
foreach ($owners as $model => $owner) {
    $writers = [];
    foreach (allPhp('app') as $f) {
        if ($f === $owner || str_starts_with($f, 'app/Models/')) {
            continue;
        }
        if (preg_match('/\b'.$model.'::query\(\)\s*->\s*(create|insert|upsert|firstOrCreate|updateOrCreate|forceCreate)\s*\(/', code(src($f)))) {
            $writers[] = $f;
        }
    }
    check($writers === [], "$model rows are created only by its owner", "$model created outside owner: ".implode(', ', $writers));
}

// ---------------------------------------------------------------------------
// 6. Wiring: routes, provider, listeners, schedule
// ---------------------------------------------------------------------------
section('6. Routes, provider, listeners, schedule');

$routes = code(src('routes/api.php'));
$expectedRoutes = [
    ['get', '/me/cashback', 'CashbackController', 'show'], ['post', '/me/cashback/claim', 'CashbackController', 'claim'],
    ['get', '/me/rewards/catalog', 'RewardController', 'catalog'], ['post', '/me/rewards/{item}/claim', 'RewardController', 'claim'],
    ['get', '/me/rewards/history', 'RewardController', 'history'], ['get', '/me/vip/progress', 'RewardController', 'vipProgress'],
    ['get', '/me/security/login-history', 'LoginHistoryController', 'index'], ['get', '/me/security/login-summary', 'LoginHistoryController', 'summary'],
    ['get', '/me/bank-accounts', 'BankAccountController', 'index'], ['post', '/me/bank-accounts', 'BankAccountController', 'store'],
    ['patch', '/me/bank-accounts/{id}/default', 'BankAccountController', 'setDefault'], ['delete', '/me/bank-accounts/{id}', 'BankAccountController', 'destroy'],
    ['get', '/me/referral-center', 'ReferralCenterController', 'show'], ['post', '/me/referral-center/payout-requests', 'ReferralCenterController', 'requestPayout'],
    ['get', '/me/dashboard-summary', 'DashboardSummaryController', 'show'],
];
$routeMiss = [];
foreach ($expectedRoutes as [$verb, $uri, $ctl, $method]) {
    $re = '/Route::'.$verb.'\(\s*\''.preg_quote($uri, '/').'\'\s*,\s*\[\s*'.$ctl.'::class\s*,\s*\''.$method.'\'\s*\]/';
    if (! preg_match($re, $routes)) {
        $routeMiss[] = strtoupper($verb).' '.$uri;
    }
}
check($routeMiss === [], count($expectedRoutes).' routes registered (prompt lists 15; its "14" is a miscount)', 'routes missing: '.implode(', ', $routeMiss));

foreach ($expectedRoutes as [, , $ctl, $method]) {
    $f = "app/Http/Controllers/Api/V1/$ctl.php";
    if (! preg_match('/function\s+'.$method.'\s*\(/', src($f))) {
        bad("$ctl::$method() does not exist");
    }
}
ok('every routed controller method exists');

$prov = src('app/Providers/RewardsServiceProvider.php');
foreach (['RecordLoginEvent', 'NotifyOnNewDeviceLogin', 'Gate::policy(MemberBankAccount::class', 'Gate::policy(RewardClaim::class', 'cashback:accrue --period=daily', 'cashback:accrue --period=weekly', 'ExpireRewardArtifactsJob', 'security:prune-login-events', 'GeoIpResolver::class'] as $needle) {
    check(str_contains($prov, $needle), "RewardsServiceProvider wires $needle", "RewardsServiceProvider is missing $needle");
}
check(str_contains(src('bootstrap/providers.php'), 'RewardsServiceProvider::class'), 'provider registered in bootstrap/providers.php', 'provider NOT registered');

// ---------------------------------------------------------------------------
// 7. Injected-service method calls resolve (catches missing methods / gaps)
// ---------------------------------------------------------------------------
section('7. Calls between services resolve to real methods');

$methodsOf = static function (string $file): array {
    preg_match_all('/function\s+(\w+)\s*\(/', code(src($file)), $m);

    return array_unique($m[1]);
};
$gaps = [];
$checked = 0;
foreach ($batchCode as $f) {
    $s = src($f);
    $c = code($s);
    if (! preg_match('/^namespace\s+([^;]+);/m', $s)) {
        continue;
    }
    $uses = [];
    if (preg_match_all('/^use\s+([^;\s]+?)(?:\s+as\s+(\w+))?;/m', $s, $um, PREG_SET_ORDER)) {
        foreach ($um as $u) {
            $uses[$u[2] ?? substr(strrchr('\\'.$u[1], '\\'), 1)] = $u[1];
        }
    }
    preg_match('/^namespace\s+([^;]+);/m', $s, $nsm);
    // promoted ctor properties: private readonly Type $prop
    if (! preg_match_all('/(?:private|protected|public)\s+(?:readonly\s+)?([A-Z]\w+)\s+\$(\w+)/', $c, $pm, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($pm as [, $type, $prop]) {
        $fqcn = $uses[$type] ?? ($nsm[1].'\\'.$type);
        $target = $classMap[$fqcn] ?? null;
        if ($target === null) {
            continue; // external or framework class
        }
        $defined = $methodsOf($target);
        if (preg_match_all('/\$this->'.preg_quote($prop, '/').'->(\w+)\s*\(/', $c, $cm)) {
            foreach (array_unique($cm[1]) as $called) {
                $checked++;
                // Allow Eloquent/Model magic only for model-typed props (none are injected in the batch).
                if (! in_array($called, $defined, true)) {
                    $gaps[] = "$f: \$this->$prop->$called() is not defined in $target";
                }
            }
        }
    }
}
check($gaps === [], "$checked injected-service calls all resolve to defined methods", implode("\n        ", $gaps));

// ---------------------------------------------------------------------------
// 8. Filament pages and policies
// ---------------------------------------------------------------------------
section('8. Filament resources / policies');

foreach (allPhp('app/Filament/Resources') as $f) {
    if (preg_match_all("/::route\('([^']*)'\)/", src($f), $m) && preg_match_all('/(\w+)::route\(/', src($f), $pc)) {
        foreach ($pc[1] as $pageClass) {
            $hit = array_filter(array_keys($classMap), static fn (string $k): bool => str_ends_with($k, '\\'.$pageClass));
            if ($hit === []) {
                bad("$f registers page $pageClass which does not exist");
            }
        }
    }
}
ok('every Filament getPages() entry has a class');
foreach (['MemberBankAccountPolicy', 'RewardClaimPolicy'] as $p) {
    check(str_contains(src("app/Policies/$p.php"), 'member_id'), "$p is owner-based", "$p is not owner-based");
}

// ---------------------------------------------------------------------------
// 9. Migrations
// ---------------------------------------------------------------------------
section('9. Migrations');

$names = array_map('basename', $migFiles);
$sorted = $names;
sort($sorted);
check($names === $sorted, 'migrations are in timestamp order', 'migrations out of order');
foreach ($migFiles as $f) {
    $s = src($f);
    check(
        str_contains($s, 'function up()') && str_contains($s, 'function down()'),
        basename($f).' has up() and down()',
        basename($f).' lacks up()/down()',
    );
}

// ---------------------------------------------------------------------------
// 10. Tests
// ---------------------------------------------------------------------------
section('10. Tests');

$testFiles = array_merge(allPhp('tests/Unit/Cashback'), allPhp('tests/Feature'), allPhp('tests/Architecture'));
$totalTests = 0;
foreach ($testFiles as $f) {
    $n = preg_match_all('/function\s+test_\w+\s*\(/', src($f));
    $totalTests += $n;
    if ($n === 0) {
        bad("$f contains no test methods");
    }
}
ok(count($testFiles).' test files, '.$totalTests.' test methods, none empty');

$arch = src('tests/Architecture/Batch1OwnershipTest.php');
$notScanned = [];
foreach ($testFiles as $f) {
    if ($f !== 'tests/Architecture/Batch1OwnershipTest.php' && ! str_contains($arch, "'".$f."'")) {
        $notScanned[] = $f;
    }
}
// Only the batch's own prompt-listed tests must be scanned; extra regression files are optional.
$mustScan = array_filter($notScanned, static fn (string $f): bool => in_array($f, $required, true));
check($mustScan === [], 'architecture test scans every prompt-listed test file', 'not scanned: '.implode(', ', $mustScan));

// ---------------------------------------------------------------------------
// 11. Leftovers
// ---------------------------------------------------------------------------
section('11. Leftovers');

$bak = array_merge(glob($root.'/*.bak*') ?: [], glob($root.'/app/**/*.bak*') ?: []);
check($bak === [], 'no *.bak files', 'bak files: '.implode(', ', $bak));
$markers = [];
foreach ($batchCode as $f) {
    if (preg_match('/\b(TODO|FIXME|XXX)\b|rest of (the )?code|\.\.\. ?unchanged/i', src($f))) {
        $markers[] = $f;
    }
}
check($markers === [], 'no TODO/FIXME/"rest of code" markers', 'markers in: '.implode(', ', $markers));

// ---------------------------------------------------------------------------
echo "\n==============================\n";
echo "PASS: $pass   FAIL: $fail   MODE: ".($repoMode ? 'full repository' : 'batch zip')."\n";
echo $fail === 0 ? "RESULT: CLEAN\n" : "RESULT: NOT CLEAN\n";
exit($fail === 0 ? 0 : 1);
