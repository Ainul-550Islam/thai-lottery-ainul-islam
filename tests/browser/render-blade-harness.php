<?php

declare(strict_types=1);

/**
 * Frontend-only Blade view harness for browser/responsive/a11y verification.
 *
 * Compiles real repository Blade templates, Blade anonymous components,
 * lang/{en,th,bn} translation files, and public/build/manifest.json Vite
 * asset tags WITHOUT booting Laravel's config/transfer.php (which is
 * currently blocked by backend-owned WalletType::Win).
 *
 * Never executes database queries, network calls, or financial mutations.
 */

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/../../app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, true, true);

$vendorAutoload = is_file(__DIR__ . '/../../vendor/autoload.php')
    ? __DIR__ . '/../../vendor/autoload.php'
    : '/tmp/tl-vendor/autoload.php';
require $vendorAutoload;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\DynamicComponent;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\FileEngine;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;

$root = realpath(__DIR__ . '/../..');
$surface = $argv[1] ?? 'login';
$locale = $argv[2] ?? 'en';
$baseUrl = rtrim($argv[3] ?? 'http://127.0.0.1:43990', '/');

$manifest = json_decode((string) file_get_contents($root . '/public/build/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$container = new \Illuminate\Foundation\Application($root);
Container::setInstance($container);
Facade::setFacadeApplication($container);
\Illuminate\Foundation\AliasLoader::getInstance(\Illuminate\Support\Facades\Facade::defaultAliases()->toArray())->register();
$container->instance('router', new class {
    public function has(string $name): bool { return true; }
    public function currentRouteName(): ?string { return 'home'; }
});

$files = new Filesystem();
$events = new Dispatcher($container);
$loader = new FileLoader($files, $root . '/lang');
$translator = new Translator($loader, $locale);
$translator->setFallback('en');

$container->instance('files', $files);
$container->instance('events', $events);
$container->instance('translator', $translator);

$cacheDir = sys_get_temp_dir() . '/tl-blade-harness-' . getmypid();
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

$finder = new FileViewFinder($files, [$root . '/resources/views']);
$blade = new BladeCompiler($files, $cacheDir);

$emitVite = static function (array|string $entrypoints) use ($manifest): string {
    $entries = is_array($entrypoints) ? $entrypoints : [$entrypoints];
    $tags = [];
    $seenCss = [];
    foreach ($entries as $entry) {
        $rec = $manifest[$entry] ?? null;
        if (!$rec || empty($rec['file'])) {
            continue;
        }
        $file = '/build/' . ltrim($rec['file'], '/');
        if (str_ends_with($file, '.css')) {
            if (!isset($seenCss[$file])) {
                $seenCss[$file] = true;
                $tags[] = '<link rel="stylesheet" href="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '">';
            }
        } else {
            foreach ($rec['css'] ?? [] as $cssFile) {
                $href = '/build/' . ltrim($cssFile, '/');
                if (!isset($seenCss[$href])) {
                    $seenCss[$href] = true;
                    $tags[] = '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
                }
            }
            $tags[] = '<script type="module" src="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '"></script>';
        }
    }
    return implode("\n", $tags);
};

$GLOBALS['__tl_emit_vite'] = $emitVite;
$GLOBALS['__tl_base_url'] = $baseUrl;
$GLOBALS['__tl_auth_user'] = null;
$GLOBALS['__tl_locale'] = $locale;
$GLOBALS['__tl_translator'] = $translator;

$blade->directive('vite', static fn (string $expr): string => "<?php echo (\$GLOBALS['__tl_emit_vite'])($expr); ?>");
$blade->directive('cspNonce', static fn (): string => '');
$blade->directive('csrf', static fn (): string => '<input type="hidden" name="_token" value="harness-csrf-token">');
$blade->directive('auth', static fn (): string => '<?php if ($GLOBALS["__tl_auth_user"] !== null): ?>');
$blade->directive('endauth', static fn (): string => '<?php endif; ?>');
$blade->directive('guest', static fn (): string => '<?php if ($GLOBALS["__tl_auth_user"] === null): ?>');
$blade->directive('endguest', static fn (): string => '<?php endif; ?>');
$blade->directive('can', static fn (): string => '<?php if (true): ?>');
$blade->directive('endcan', static fn (): string => '<?php endif; ?>');

$blade->component('dynamic-component', DynamicComponent::class);

$resolver = new EngineResolver();
$resolver->register('file', static fn () => new FileEngine($files));
$resolver->register('php', static fn () => new PhpEngine($files));
$resolver->register('blade', static fn () => new CompilerEngine($blade, $files));

$viewFactory = new Factory($resolver, $finder, $events);
$viewFactory->setContainer($container);
$viewFactory->addNamespace('__components', $cacheDir);
$container->instance('view', $viewFactory);
$container->alias('view', \Illuminate\Contracts\View\Factory::class);

// Register minimal global helper shims if Laravel Foundation helpers defer to facades/container
$container->instance('config', new \Illuminate\Config\Repository([
    'app' => [
        'name' => 'Thai Lottery',
        'locale' => $locale,
        'fallback_locale' => 'en',
        'url' => $baseUrl,
    ],
    'chat' => [
        'enabled' => false,
        'widget' => ['show_on' => []],
    ],
    'contact' => [
        'support' => ['email' => 'support@example.test'],
    ],
    'finance' => [
        'default_currency' => 'THB',
    ],
    'payment' => [
        'currency' => ['default' => 'THB'],
        'supported_banks' => [
            'KBANK' => 'Kasikornbank',
            'SCB' => 'Siam Commercial Bank',
        ],
    ],
]));
$container->setLocale($locale);

$container->bind('auth', static fn () => new class implements \Illuminate\Contracts\Auth\Factory, \Illuminate\Contracts\Auth\Guard {
    public function check(): bool { return $GLOBALS['__tl_auth_user'] !== null; }
    public function guest(): bool { return $GLOBALS['__tl_auth_user'] === null; }
    public function user(): ?\Illuminate\Contracts\Auth\Authenticatable { return $GLOBALS['__tl_auth_user']; }
    public function id(): int|string|null { return $GLOBALS['__tl_auth_user']?->id ?? null; }
    public function validate(array $credentials = []): bool { return false; }
    public function hasUser(): bool { return $GLOBALS['__tl_auth_user'] !== null; }
    public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user): static { $GLOBALS['__tl_auth_user'] = $user; return $this; }
    public function guard($name = null): \Illuminate\Contracts\Auth\Guard { return $this; }
    public function shouldUse($name): void {}
});

$requestObj = \Illuminate\Http\Request::create($baseUrl . '/', 'GET');
$requestObj->setLaravelSession(new \Illuminate\Session\Store('tl_session', new \Illuminate\Session\NullSessionHandler()));
$container->instance('request', $requestObj);

$urlGen = new class($baseUrl) {
    public function __construct(private string $base) {}
    public function current(): string { return $this->base . '/'; }
    public function to(string $path = ''): string { return $this->base . '/' . ltrim($path, '/'); }
    public function asset(string $path): string { return $this->base . '/' . ltrim($path, '/'); }
    public function route(string $name, mixed $parameters = []): string {
        $map = [
            'home' => '/',
            'results.index' => '/results',
            'results.search' => '/results/search',
            'tickets.verify' => '/tickets/verify',
            'sales-points.index' => '/sales-points',
            'national-lottery.index' => '/national-lottery',
            'weekly-lottery.index' => '/weekly-lottery',
            'bingo-lottery.index' => '/bingo-lottery',
            'pcso-lottery.index' => '/pcso-lottery',
            'contact' => '/contact',
            'login' => '/login',
            'login.attempt' => '/login',
            'register' => '/register',
            'register.attempt' => '/register',
            'logout' => '/logout',
            'password.request' => '/forgot-password',
            'password.request.attempt' => '/forgot-password',
            'terms' => '/terms',
            'privacy' => '/privacy',
            'fees' => '/fees',
            'player.dashboard' => '/dashboard',
            'player.bet' => '/bet',
            'player.bets' => '/bets',
            'player.bets.create' => '/bet',
            'player.bets.purchase' => '/bet/purchase',
            'player.bets.purchase.alias' => '/player/bets/purchase',
            'player.tickets' => '/tickets',
            'player.wallet' => '/wallet',
            'player.wallet.conversion' => '/wallet/convert',
            'player.wallet.conversion.preview' => '/wallet/convert/preview',
            'player.wallet.conversion.quote' => '/wallet/convert/quote',
            'player.wallet.conversion.store' => '/wallet/convert',
            'player.deposit' => '/deposit',
            'player.deposit.store' => '/deposit',
            'player.deposit.submit' => '/deposit',
            'player.withdraw' => '/withdraw',
            'player.withdraw.store' => '/withdraw',
            'player.withdraw.submit' => '/withdraw',
            'player.profile' => '/profile',
            'player.profile.update' => '/profile',
            'player.password.update' => '/profile/password',
            'player.limits.update' => '/profile/limits',
            'account.verification' => '/account-verification',
            'account.grade' => '/account-grades',
            'support.index' => '/support',
            'support.store' => '/support',
            'notifications.index' => '/notifications',
            'agent.dashboard' => '/agent',
            'agent.commissions' => '/agent/commissions',
            'agent.settlements' => '/agent/settlements',
            'agent.statement' => '/agent/statement',
            'agent.referrals' => '/agent/referrals',
            'admin.dashboard' => '/admin-console',
            'admin.lottery.dashboard' => '/admin/lottery',
            'admin.wallet.index' => '/admin/wallet',
            'admin.reports.financial' => '/admin/reports/financial',
            'admin.compliance.kyc' => '/admin/compliance/kyc',
            'admin.security.ip-rules' => '/admin/security/ip-rules',
        ];
        $path = $map[$name] ?? ('/' . str_replace('.', '/', $name));
        if (is_array($parameters) && $parameters !== [] && array_is_list($parameters) === false && $name === 'player.wallet.conversion') {
            return $this->base . '/wallet/convert?' . http_build_query($parameters);
        }
        if (is_array($parameters) && isset($parameters['reference']) && $name === 'player.wallet.conversion.show') {
            return $this->base . '/wallet/convert/' . rawurlencode((string) $parameters['reference']);
        }
        if (is_array($parameters) && $parameters !== [] && array_is_list($parameters) === false && $name === 'locale.switch') {
            return $this->base . '/locale/' . reset($parameters);
        }
        if (is_string($parameters) && $name === 'locale.switch') {
            return $this->base . '/locale/' . $parameters;
        }
        return $this->base . $path;
    }
};
$container->instance('url', $urlGen);

$sessionStore = new class {
    public function get(string $key, mixed $default = null): mixed { return $default; }
    public function has(string $key): bool { return false; }
    public function token(): string { return 'harness-csrf-token'; }
    public function getOldInput(?string $key = null, mixed $default = null): mixed { return $default; }
};
$container->instance('session', $sessionStore);
$container->instance('session.store', $sessionStore);

$errors = new ViewErrorBag();
$errors->put('default', new MessageBag());
$viewFactory->share('errors', $errors);

$memberUser = new class implements \Illuminate\Contracts\Auth\Authenticatable {
    public int $id = 42;
    public string $name = 'Somchai Jaidee';
    public string $username = 'somchai';
    public string $email = 'somchai@example.test';
    public string $phone = '0812345678';
    public string $kyc_status = 'verified';
    public ?object $status = null;
    public function hasRole(string $role): bool { return false; }
    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthPasswordName(): string { return 'password'; }
    public function getAuthPassword(): string { return ''; }
    public function getRememberToken(): ?string { return null; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return 'remember_token'; }
};

$html = match ($surface) {
    'public-services' => $viewFactory->make('services.index', [
        'meta' => [
            'title' => $translator->get('services.meta_title'),
            'description' => $translator->get('services.meta_description'),
            'canonical' => $baseUrl . '/our-services',
            'robots' => 'index, follow',
        ],
    ])->render(),
    'login' => $viewFactory->make('auth.login')->render(),
    'register' => $viewFactory->make('auth.register')->render(),
    'forgot-password' => $viewFactory->make('auth.forgot-password', [
        'resetToken' => null,
        'captchaEnabled' => true,
        'captcha' => ['question' => '12 + 7 = ?', 'token' => 'tok-test'],
    ])->render(),
    'player-bet' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('player.bet', [
            'wallet' => null,
            'openDraw' => (object) [
                'id' => 99,
                'draw_number' => 'DRAW-2026-1016',
                'draw_date' => \Illuminate\Support\Carbon::parse('2026-11-01 15:30:00'),
                'betting_closes_at' => \Illuminate\Support\Carbon::parse('2026-11-01 14:30:00'),
                'closes_at' => \Illuminate\Support\Carbon::parse('2026-11-01 14:30:00'),
            ],
            'balance' => '1250.00',
            'walletBalance' => '1250.00',
            'currency' => \App\Enums\Currency::THB,
            'currencyCode' => 'THB',
            'marketOptions' => [
                'three_top' => ['label' => '3 Digits Top', 'digits' => 3, 'multiplier' => '900'],
                'two_bottom' => ['label' => '2 Digits Bottom', 'digits' => 2, 'multiplier' => '90'],
            ],
            'slipMaxItems' => 20,
        ])->render();
    })(),
    'player-deposit' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('player.deposit', [
            'user' => $memberUser,
            'wallet' => null,
            'availableBalance' => '฿1,250.00',
            'balance' => '1250.00',
            'currency' => \App\Enums\Currency::THB,
            'methods' => ['promptpay' => 'PromptPay QR', 'bank_transfer' => 'Bank Transfer'],
            'limits' => ['min' => '100.00', 'max' => '50000.00', 'processing_hours' => 24],
            'idempotencyKey' => 'idem-deposit-harness',
            'recentDeposits' => collect(),
        ])->render();
    })(),
    'player-withdraw' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('player.withdraw', [
            'user' => $memberUser,
            'wallet' => null,
            'availableBalance' => '฿1,250.00',
            'balance' => '1250.00',
            'currency' => \App\Enums\Currency::THB,
            'kycRequired' => false,
            'kycApproved' => true,
            'methods' => ['bank_transfer' => 'Bank Transfer', 'truemoney' => 'TrueMoney Wallet'],
            'supportedBanks' => ['KBANK' => 'Kasikornbank', 'SCB' => 'Siam Commercial Bank'],
            'limits' => ['min' => '300.00', 'max' => '100000.00', 'processing_hours' => 24],
            'idempotencyKey' => 'idem-withdraw-harness',
            'recentWithdrawals' => collect(),
        ])->render();
    })(),
    'player-profile' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('player.profile', [
            'user' => $memberUser,
            'limits' => (object) [
                'daily_deposit_limit' => '10000.00',
                'single_bet_limit' => '1000.00',
                'daily_wagering_limit' => '5000.00',
            ],
        ])->render();
    })(),
    'wallet-conversion' => (function () use ($viewFactory, $memberUser, $translator, $manifest, $baseUrl): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        $direction = \App\Enums\WalletConversionDirection::CashToWin;
        $currency = \App\Enums\Currency::THB;
        $limits = \App\ValueObjects\ConversionLimits::fromConfig([
            'min_amount' => '100.00',
            'max_amount' => '50000.00',
            'daily_limit' => '100000.00',
            'monthly_limit' => '500000.00',
            'enforce_daily' => true,
            'enforce_monthly' => true,
        ], $currency);
        $feeBreakdown = new \App\DTOs\Wallet\ConversionFeeBreakdownData(
            ruleKey: 'cash_to_win.standard',
            ruleVersion: '1',
            direction: $direction,
            rate: '0.020000',
            percentage: '2.00',
            basisPoints: 200,
            feeAmount: '2.00',
            netAmount: '98.00',
            currency: 'THB',
            calculation: 'percentage',
            bearer: \App\Enums\ConversionFeeBearer::Source,
            isConfigured: true,
        );
        $formHtml = $viewFactory->make('wallet.conversion.partials.quote-form', [
            'direction' => $direction,
            'currency' => $currency,
            'feeBreakdown' => $feeBreakdown,
            'limits' => $limits,
            'dailyRemaining' => '85000.00',
            'dailyTotal' => '15000.00',
            'monthlyTotal' => '40000.00',
            'quoteTtlSeconds' => 120,
            'sourceWallet' => (object) ['balance' => '5450.00'],
        ])->render();
        $wcCss = isset($manifest['resources/css/wallet-conversion.css']['file'])
            ? '<link rel="stylesheet" href="' . htmlspecialchars($baseUrl . '/build/' . $manifest['resources/css/wallet-conversion.css']['file'], ENT_QUOTES, 'UTF-8') . '">'
            : '';
        $wcJs = isset($manifest['resources/js/wallet-conversion.js']['file'])
            ? '<script type="module" src="' . htmlspecialchars($baseUrl . '/build/' . $manifest['resources/js/wallet-conversion.js']['file'], ENT_QUOTES, 'UTF-8') . '"></script>'
            : '';
        $viewFactory->startSection('title', $translator->get('wallet_conversion.page_title'));
        $viewFactory->startSection('meta_robots', 'noindex,nofollow');
        $viewFactory->startPush('styles');
        echo $wcCss;
        $viewFactory->stopPush();
        $viewFactory->startPush('scripts');
        echo $wcJs;
        $viewFactory->stopPush();
        $viewFactory->startSection('content');
        echo '<div class="wc-shell"><h1 class="wc-title">' . htmlspecialchars($translator->get('wallet_conversion.page_title'), ENT_QUOTES, 'UTF-8') . '</h1>' . $formHtml . '</div>';
        $viewFactory->stopSection();
        return $viewFactory->make('layouts.app')->render();
    })(),
    'support-portal' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('support.portal', [
            'surface' => 'index',
            'case' => null,
            'records' => [],
        ])->render();
    })(),
    'notifications' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('notifications.index', [
            'state' => 'ready',
            'records' => [],
        ])->render();
    })(),
    'admin-layout' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('admin.dashboard', [
            'panel' => 'dashboard',
            'kpis' => [],
            'transactions' => [],
        ])->render();
    })(),
    'home' => (function () use ($viewFactory, $translator, $baseUrl): string {
        $homeText = is_array($translator->get('home')) ? $translator->get('home') : [];
        return $viewFactory->make('home', [
            'home' => [
                'text' => [
                    'meta_title' => $translator->get('home.meta_title'),
                    'meta_description' => $translator->get('home.meta_description'),
                    'hero_title' => $translator->get('home.hero_title'),
                    'hero_lead' => $translator->get('home.hero_lead'),
                    'cta_results' => $translator->get('home.cta_results'),
                    'cta_check' => $translator->get('home.cta_check'),
                    'cta_sales' => $translator->get('home.sales_title'),
                    'cta_sign_in' => $translator->get('player.sign_in'),
                    'next_draw_title' => $translator->get('home.next_draw_title'),
                    'current_result_title' => $translator->get('home.current_result_title'),
                    'prize_1st_label' => $translator->get('home.prize_1st_label'),
                    'prize_2nd_label' => $translator->get('home.prize_2nd_label'),
                    'prize_last2_label' => $translator->get('home.prize_last2_label'),
                    'draw_label' => $translator->get('home.draw_label'),
                    'source_label' => $translator->get('home.source_label'),
                    'lane_results_title' => $translator->get('home.lane_results_title'),
                    'lane_results_none' => $translator->get('home.lane_results_none'),
                    'check_title' => $translator->get('home.check_title'),
                    'check_help' => $translator->get('home.check_help'),
                    'check_label' => $translator->get('home.check_label'),
                    'check_placeholder' => '000000',
                    'check_button' => $translator->get('home.check_button'),
                    'sales_title' => $translator->get('home.sales_title'),
                    'sales_help' => $translator->get('home.sales_help'),
                    'live_title' => $translator->get('home.live_title'),
                    'products_title' => $translator->get('home.products_title'),
                    'prize_title' => $translator->get('home.prize_title'),
                    'stats_title' => $translator->get('home.stats_title'),
                    'generated_label' => $translator->get('home.generated_label'),
                    'stats_database' => $translator->get('home.stats_database'),
                    'bonuses_title' => $translator->get('home.bonuses_title'),
                    'payments_title' => $translator->get('home.payments_title'),
                    'support_title' => $translator->get('home.support_title'),
                    'support_contact' => $translator->get('home.support_contact'),
                    'email_label' => $translator->get('home.email_label'),
                    'phone_label' => $translator->get('home.phone_label'),
                    'hours_label' => $translator->get('home.hours_label'),
                    'app_title' => $translator->get('home.app_title'),
                    'trust_title' => $translator->get('home.trust_title'),
                    'independent_disclaimer' => $translator->get('home.independent_disclaimer'),
                ] + $homeText,
                'navigation' => [
                    ['label' => $translator->get('home.cta_results'), 'url' => $baseUrl . '/results', 'active' => false],
                    ['label' => $translator->get('home.cta_check'), 'url' => $baseUrl . '/check', 'active' => false],
                    ['label' => $translator->get('home.sales_title'), 'url' => $baseUrl . '/sales-points', 'active' => false],
                    ['label' => $translator->get('public_pages.footer_how_to_play'), 'url' => $baseUrl . '/how-to-play', 'active' => false],
                ],
                'hero' => ['timezone' => 'Asia/Bangkok', 'next_draw_iso' => '2026-11-01T15:30:00+07:00'],
                'next_draw' => [
                    'status' => 'AVAILABLE',
                    'scheduled_at_iso' => '2026-11-01T15:30:00+07:00',
                    'scheduled_at_display' => '01 Nov 2026 · 15:30 ICT',
                    'timezone' => 'Asia/Bangkok',
                ],
                'countdown' => [
                    'scheduled_at_iso' => '2026-11-01T15:30:00+07:00',
                    'timezone' => 'Asia/Bangkok',
                ],
                'current_result' => [
                    'status' => 'VERIFIED',
                    'has_result' => true,
                    'first_prize' => '012345',
                    'draw_number' => 'DRAW-2026-1016',
                    'source_state' => 'OFFICIAL_SOURCE_VERIFIED',
                    'second_prize' => ['049812', '731004'],
                    'last_two' => ['08'],
                    'result_version' => 'v1-sha256-verified',
                ],
                'lane_results' => [
                    'lanes' => [
                        [
                            'title_key' => 'public_pages.footer_lane_l6',
                            'route' => $baseUrl . '/national-lottery',
                            'status' => 'AVAILABLE',
                            'available' => true,
                            'reference' => 'GLO-2026-10-16',
                            'date_iso' => '2026-10-16',
                            'date_display_en' => '16 Oct 2026',
                            'date_display_th' => '16 ต.ค. 2569',
                            'date_display_bn' => '১৬ অক্টোবর ২০২৬',
                            'fields' => [
                                ['label_key' => 'home.prize_1st_label', 'value' => '012345'],
                                ['label_key' => 'home.prize_last2_label', 'value' => '08'],
                            ],
                        ],
                        [
                            'title_key' => 'public_pages.footer_lane_weekly',
                            'route' => $baseUrl . '/weekly-lottery',
                            'status' => 'AVAILABLE',
                            'available' => true,
                            'reference' => 'WKL-2026-42',
                            'date_iso' => '2026-10-19',
                            'date_display_en' => '19 Oct 2026',
                            'date_display_th' => '19 ต.ค. 2569',
                            'date_display_bn' => '১৯ অক্টোবর ২০২৬',
                            'fields' => [
                                ['label_key' => 'home.prize_1st_label', 'value' => '009412'],
                                ['label_key' => 'home.prize_last2_label', 'value' => '12'],
                            ],
                        ],
                    ],
                ],
                'live_draw' => [
                    'status' => 'NOT_CONFIGURED',
                    'live_status' => 'not_configured',
                    'replay_status' => 'not_configured',
                ],
                'products' => [
                    'status' => 'CATALOGUE_ONLY',
                    'products' => [
                        ['code' => 'L6', 'name' => 'Lottery 6-Digit', 'ticket_price' => '80.00', 'currency' => 'THB', 'digits' => 6, 'notes' => 'Official 6-digit format'],
                        ['code' => 'N3', 'name' => 'Numbers 3-Digit', 'ticket_price' => '20.00', 'currency' => 'THB', 'digits' => 3, 'notes' => 'Official 3-digit format'],
                    ],
                ],
                'prize_highlight' => [
                    'status' => 'CATALOGUE_ONLY',
                    'amount' => '6,000,000.00 THB',
                    'draw_number' => 'DRAW-2026-1016',
                    'message' => '',
                ],
                'stats' => [
                    'status' => 'AVAILABLE',
                    'metrics' => [
                        ['label' => 'Registered accounts', 'value' => '1,248', 'available' => true],
                        ['label' => 'Published draws', 'value' => '64', 'available' => true],
                    ],
                    'generated_at' => '2026-10-22T12:00:00+07:00',
                ],
                'bonuses' => ['campaigns' => []],
                'payment_methods' => [
                    'status' => 'CONFIGURED',
                    'methods' => [
                        ['code' => 'promptpay', 'label' => 'PromptPay QR', 'currencies' => ['THB'], 'status' => 'AVAILABLE'],
                        ['code' => 'bank_transfer', 'label' => 'Thai Bank Transfer', 'currencies' => ['THB'], 'status' => 'AVAILABLE'],
                    ],
                ],
                'support' => [
                    'status' => 'CONFIGURED',
                    'email' => 'support@example.test',
                    'hours' => '09:00–21:00 ICT',
                    'route_url' => $baseUrl . '/contact',
                ],
                'app_links' => ['status' => 'NOT_CONFIGURED'],
                'trust' => [
                    'bullets' => [
                        'All lottery results display explicit source provenance states.',
                        'Leading zeroes in 6-digit, 3-digit, and 2-digit numbers are preserved verbatim.',
                    ],
                ],
            ],
        ])->render();
    })(),
    'results-search' => $viewFactory->make('results.search', [
        'query' => '012345',
        'results' => [
            [
                'draw_number' => 'DRAW-2026-1016',
                'draw_date' => '2026-10-16',
                'first_prize' => '012345',
                'bottom_two' => '08',
                'fixture_sample' => false,
                'source_state' => 'OFFICIAL_SOURCE_VERIFIED',
            ],
        ],
    ])->render(),
    'player-dashboard' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('player.dashboard', [
            'user' => $memberUser,
            'wallet' => null,
            'openDraw' => (object) [
                'id' => 99,
                'draw_number' => 'DRAW-2026-1016',
                'scheduled_at' => \Illuminate\Support\Carbon::parse('2026-11-01 15:30:00'),
                'betting_close_at' => \Illuminate\Support\Carbon::parse('2026-11-01 14:30:00'),
            ],
            'upcomingDraw' => null,
            'latestCompletedDraw' => null,
            'recentBets' => [],
        ])->render();
    })(),
    'player-wallet' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('player.wallet', [
            'wallet' => null,
            'selectedCurrency' => 'THB',
            'totalDeposited' => '0.00',
            'totalPrizesWon' => '0.00',
            'transactions' => [],
        ])->render();
    })(),
    'player-rg' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('player.responsible-gaming', [
            'activeExclusion' => null,
            'limits' => (object) [
                'daily_deposit_limit' => '10000.00',
                'single_bet_limit' => '1000.00',
                'daily_wagering_limit' => '5000.00',
            ],
        ])->render();
    })(),
    'player-security' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('player.security', [
            'kycStatus' => 'VERIFIED',
            'sessions' => collect(),
            'activeExclusion' => null,
        ])->render();
    })(),
    'legal-age' => (function () use ($viewFactory, $memberUser): string {
        $GLOBALS['__tl_auth_user'] = $memberUser;
        return $viewFactory->make('legal.age-verification', [
            'dateOfBirthOnFile' => false,
            'status' => ['minimum_age' => 20],
        ])->render();
    })(),
    'admin-withdrawal-reconciliation' => $viewFactory->make('admin.withdrawal-reconciliation', [
        'item' => null,
        'cases' => new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15, 1),
    ])->render(),
    'error-401' => $viewFactory->make('errors.401')->render(),
    'error-403' => $viewFactory->make('errors.403')->render(),
    'error-404' => $viewFactory->make('errors.404')->render(),
    'error-419' => $viewFactory->make('errors.419')->render(),
    'error-429' => $viewFactory->make('errors.429')->render(),
    'error-500' => $viewFactory->make('errors.500')->render(),
    'error-503' => $viewFactory->make('errors.503')->render(),
    default => throw new InvalidArgumentException("Unknown surface: {$surface}"),
};

// Cleanup ephemeral compiled views
foreach (glob($cacheDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($cacheDir);

echo $html;
