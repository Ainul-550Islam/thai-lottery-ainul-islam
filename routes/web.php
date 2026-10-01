<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\LottoFinExecutiveDashboardController;
use App\Http\Controllers\Admin\ReleaseOperationsController;
use App\Http\Controllers\Agent\AgentPortalController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\Support\SupportPortalController;
use App\Http\Controllers\GloL6Controller;
use App\Http\Controllers\GloResultsPageController;
use App\Http\Controllers\Player\PlayerSecuritySettingsController;
use App\Http\Controllers\Betting\ThaiLotteryBettingController;
use App\Http\Controllers\AccountGradeController;
use App\Http\Controllers\BingoLotteryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LottoDiscountController;
use App\Http\Controllers\LotteryHubController;
use App\Http\Controllers\LotteryPurchasePageController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NationalLotteryController;
use App\Http\Controllers\PcsoLotteryController;
use App\Http\Controllers\PrizeVerificationController;
use App\Http\Controllers\PublicAccountInfoController;
use App\Http\Controllers\PublicPagesController;
use App\Http\Controllers\PublicServicePagesController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\Auth\MemberAuthController;
use App\Http\Controllers\Verification\AccountVerificationController as MemberAccountVerificationController;
use App\Http\Controllers\Web\BetPurchaseController;
use App\Http\Controllers\Web\PaymentCallbackController;
use App\Http\Controllers\Web\PlayerWebController;
use App\Http\Controllers\WeeklyLotteryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The session-authenticated player web app. Laravel's default web middleware group is
| applied automatically (CSRF, session, cookies), plus the global security headers and
| correlation id middleware registered in bootstrap/app.php.
|
| Every route name here is what the Blade views and the player experience tests already
| reference, so the names are part of the contract:
|   login, login.attempt, register, register.attempt, logout,
|   player.dashboard, player.draws, player.draws.detail, player.bet, player.bets,
|   player.wallet, player.deposit, player.deposit.store, player.withdraw,
|   player.withdraw.store, player.profile, player.profile.update, player.profile.password,
|   player.profile.limits, player.bets.purchase, player.password.update, player.limits.update
|
*/

/*
| Operational endpoints. `/up` is the framework liveness probe registered in
| bootstrap/app.php; the structured health trio and the Prometheus metrics export live
| here against the same HealthController / MetricsController that the observability
| services back.
*/
// P0: /metrics is operator-only telemetry — never financial-public.
Route::middleware(['auth', 'can:access-metrics'])->group(function (): void {
    Route::get('/metrics', [MetricsController::class, 'metrics'])->name('metrics');
});
Route::get('/up/health', [HealthController::class, 'health'])->name('health');
Route::get('/up/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/up/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health', [HealthController::class, 'health'])->name('health.canonical');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready.canonical');
Route::get('/live', [HealthController::class, 'live'])->name('health.live.canonical');

Route::middleware('guest')->group(function (): void {
    // PROMPT 3: the member auth surface (login / registration /
    // password recovery) is served by MemberAuthController — thin
    // orchestration over LoginService / RegistrationService /
    // PasswordResetService (+ the server-authoritative CaptchaService
    // gate). Same route names as before, so every existing link,
    // redirect and test keeps resolving.
    Route::get('/login', [MemberAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [MemberAuthController::class, 'login'])->name('login.attempt')->middleware('throttle:login');
    Route::get('/register', [MemberAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [MemberAuthController::class, 'register'])->name('register.attempt')->middleware('throttle:login');

    // Password recovery: account no./email + CAPTCHA request, then the
    // token-gated new-password form. Throttled on both POSTs.
    Route::get('/forgot-password', [MemberAuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [MemberAuthController::class, 'requestReset'])
        ->middleware('throttle:password-reset')
        ->name('password.request.attempt');
    Route::get('/reset-password/{token}', [MemberAuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [MemberAuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('password.reset.attempt');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [MemberAuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [PlayerWebController::class, 'dashboard'])->name('player.dashboard');
    Route::get('/draws', [PlayerWebController::class, 'draws'])->name('player.draws');
    Route::get('/draws/{id}', [PlayerWebController::class, 'drawDetail'])->name('player.draws.detail');

    Route::get('/bet', [PlayerWebController::class, 'betSlip'])->name('player.bet');
    Route::post('/bet/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase');
    Route::post('/player/bets/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase.alias');
    Route::get('/bets', [PlayerWebController::class, 'bets'])->name('player.bets');

    Route::get('/wallet', [PlayerWebController::class, 'wallet'])->name('player.wallet');

    Route::get('/deposit', [PlayerWebController::class, 'deposit'])->name('player.deposit');
    Route::get('/deposit/status/{deposit}', [PlayerWebController::class, 'depositStatus'])
        ->where('deposit', '[A-Za-z0-9_\\-]{1,80}')
        ->name('player.deposit.status');
    Route::post('/deposit', [PlayerWebController::class, 'storeDeposit'])
        ->middleware('throttle:deposit')
        ->name('player.deposit.store');

    Route::get('/withdraw', [PlayerWebController::class, 'withdraw'])->name('player.withdraw');
    Route::get('/withdrawal/status/{withdrawal}', [PlayerWebController::class, 'withdrawalStatus'])
        ->where('withdrawal', '[A-Za-z0-9_\\-]{1,80}')
        ->name('player.withdrawal.status');
    Route::post('/withdraw', [PlayerWebController::class, 'storeWithdraw'])
        ->middleware('throttle:withdrawal')
        ->name('player.withdraw.store');

    Route::get('/profile', [PlayerWebController::class, 'profile'])->name('player.profile');
    Route::put('/profile', [PlayerWebController::class, 'updateProfile'])->name('player.profile.update');
    Route::put('/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.password.update');
    Route::put('/player/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.profile.password');
    Route::put('/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.limits.update');
    Route::put('/player/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.profile.limits');
    Route::post('/player/self-exclusion', [PlayerWebController::class, 'storeSelfExclusion'])
        ->middleware('throttle:account-grade')
        ->name('player.self-exclusion.store');

    // Authenticated compatibility paths retain the canonical handlers while
    // ensuring anonymous requests are stopped by auth before the redirect.
    Route::get('/player/bet', fn () => redirect()->route('player.bet'))->name('player.bet.legacy');
    Route::get('/player/draws', fn () => redirect()->route('player.draws'))->name('player.draws.legacy');
    Route::get('/player/bets', fn () => redirect()->route('player.bets'))->name('player.bets.legacy');
    Route::get('/player/deposit', fn () => redirect()->route('player.deposit'))->name('player.deposit.legacy');
    Route::get('/player/withdraw', fn () => redirect()->route('player.withdraw'))->name('player.withdraw.legacy');
    Route::get('/player/profile', fn () => redirect()->route('player.profile'))->name('player.profile.legacy');
});

/*
|---------------------------------------------------------------------------
| Account services (PROMPT 3): verification + grade — authenticated only
|---------------------------------------------------------------------------
| Ownership is always the session user. Rate limits: account-verification /
| account-grade (registered in AppServiceProvider).
*/
Route::middleware('auth')->group(function (): void {
    // PROMPT 3: the member Account Verify page is served by the
    // Verification controller (policy-authorized, self-scoped, the
    // immutable submission aggregate behind it). The reviewer decision
    // route is policy-walled (AccountVerificationPolicy::decide).
    Route::get('/account/verification', [MemberAccountVerificationController::class, 'show'])
        ->name('account.verification');
    Route::post('/account/verification', [MemberAccountVerificationController::class, 'submit'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.submit');
    Route::get('/account/verification/document/{documentToken}', [MemberAccountVerificationController::class, 'download'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.document');
    Route::post('/account/verification/{verification}/decision', [MemberAccountVerificationController::class, 'decide'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.decide');

    Route::get('/account/grade', [AccountGradeController::class, 'show'])
        ->middleware('throttle:account-grade')
        ->name('account.grade');
    Route::get('/account/grade/history', [AccountGradeController::class, 'history'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.history');
    Route::post('/account/grade/refresh', [AccountGradeController::class, 'refresh'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.refresh');
});

/*
|--------------------------------------------------------------------------
| Public Home + supporting public pages (anonymous by design)
|--------------------------------------------------------------------------
| Results are served from the verified projection only; fixture datasets are
| labeled FIXTURE_ONLY and are never called official.
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

// Legacy aliases deliberately redirect into the authenticated canonical player
// routes. They do not render a second wallet, deposit, withdrawal or dashboard
// implementation and therefore cannot expose presentation-only financial data.
Route::get('/player/dashboard', fn () => redirect()->route('player.dashboard'))
    ->middleware('auth')->name('player.dashboard.legacy');
Route::get('/player/wallet', fn () => redirect()->route('player.wallet'))
    ->middleware('auth')->name('player.wallet.legacy');
Route::get('/wallet/deposit', fn () => redirect()->route('player.deposit'))
    ->middleware('auth')->name('wallet.deposit');
Route::get('/withdrawal', fn () => redirect()->route('player.withdraw'))
    ->middleware('auth')->name('withdrawal.index');
Route::get('/wallet/withdrawal', fn () => redirect()->route('player.withdraw'))
    ->middleware('auth')->name('wallet.withdrawal');
Route::get('/betting', [ThaiLotteryBettingController::class, 'index'])->name('betting.index');
Route::get('/lotto/betting', [ThaiLotteryBettingController::class, 'index'])->name('lotto.betting');
// Dedicated GLO L6 home. It uses the canonical public GLO services and is
// intentionally separate from the legacy /results page, whose historical
// controller is not a source for live GLO data.
Route::get('/glo-l6', [GloL6Controller::class, 'index'])
    ->middleware('public.legal')
    ->name('glo-l6.index');
Route::get('/glo-l6/buy', [GloL6Controller::class, 'buy'])
    ->middleware('public.legal')
    ->name('glo-l6.buy');
Route::get('/glo-l6/latest', [GloL6Controller::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('glo-l6.latest');
Route::get('/glo-l6/history', [GloL6Controller::class, 'history'])
    ->middleware('public.legal')
    ->name('glo-l6.history');
Route::get('/glo-l6/year/{year}', [GloL6Controller::class, 'year'])
    ->where('year', '[0-9]{4}')
    ->middleware('public.legal')
    ->name('glo-l6.year');
Route::get('/glo-l6/draw/{draw}', [GloL6Controller::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9_\\-]{1,64}')
    ->middleware('public.legal')
    ->name('glo-l6.draw');
Route::get('/glo-l6/result/{draw}', [GloL6Controller::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9_\\-]{1,64}')
    ->middleware('public.legal')
    ->name('glo-l6.result');

Route::get('/results', [GloResultsPageController::class, 'index'])->name('results.index');

// Account and protection aliases are authenticated. They delegate to the
// canonical player/profile, responsible-gaming and security architecture;
// legacy guest pages are not allowed to invent account state.
Route::middleware('auth')->group(function (): void {
    Route::get('/player/security', [PlayerSecuritySettingsController::class, 'index'])->name('player.security');
    Route::get('/player/settings', [PlayerSecuritySettingsController::class, 'index'])->name('player.settings');
    Route::get('/settings', [PlayerWebController::class, 'responsibleGaming'])->name('settings.index');
    Route::get('/member/settings', [PlayerWebController::class, 'responsibleGaming'])->name('member.settings');
    Route::get('/player/settings-portal', [PlayerWebController::class, 'responsibleGaming'])->name('player.settings.portal');
    Route::get('/member/profile', fn () => redirect()->route('player.profile'))->name('member.profile');
    Route::get('/player/profile-portal', fn () => redirect()->route('player.profile'))->name('player.profile.portal');
});
Route::middleware('auth')->group(function (): void {
    Route::get('/history', fn () => redirect()->route('player.bets'))->name('history.index');
    Route::get('/member/history', fn () => redirect()->route('player.bets'))->name('member.history');
    Route::get('/player/history-portal', fn () => redirect()->route('player.bets'))->name('player.history.portal');
});
Route::get('/results/search', [ResultsController::class, 'search'])->name('results.search');

// Public ticket check UI (primary UX; the JSON API remains at /api/v1/glo/results/check/{n}).
Route::get('/check', [HomeController::class, 'checkForm'])->name('ticket-check');
Route::post('/check', [HomeController::class, 'checkSubmit'])
    ->middleware('throttle:home-check')
    ->name('ticket-check.submit');

// Public sales-point search UI (uses existing GloSalesPointService).
Route::get('/sales-points', [HomeController::class, 'salesPoints'])->name('sales-points');

// Public informational + legal pages (versioned Terms from config/legal.php).
// public.legal = PublicLegalHeaders middleware: safe guest GET cache only.
Route::get('/about', [PublicPagesController::class, 'about'])
    ->middleware('public.legal')
    ->name('about');
Route::get('/vision', [PublicPagesController::class, 'vision'])
    ->middleware('public.legal')
    ->name('vision');
Route::get('/terms', [PublicPagesController::class, 'terms'])
    ->middleware('public.legal')
    ->name('terms');

// Public Fees (PROMPT 3) — anonymous, config-driven, no user-specific fees.
Route::get('/fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('fees');
Route::get('/our-fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('our-fees');

// Public Prize Verification (PROMPT 4) — anonymous ticket / result checker.
Route::get('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('prize-verification');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.verify');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.submit');

// Public Discount Rules (PROMPT 4) — anonymous product/game matrix.
Route::get('/discounts', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('discounts');
Route::get('/lotto-discount', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotto-discount');

// Public How to Play Guide
Route::get('/how-to-play', [\App\Http\Controllers\PublicHowToPlayController::class, 'index'])
    ->middleware('public.legal')
    ->name('how-to-play');

// Public FAQ / Knowledge Base
Route::get('/faq', [\App\Http\Controllers\PublicFaqController::class, 'index'])
    ->middleware('public.legal')
    ->name('faq');

/*
|--------------------------------------------------------------------------
| PROMPT 5: public National Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These four routes serve national_lottery_* data and
| nothing else: not GLO L6/N3, not an operator market, not a lottery provider
| that has not published. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search, /buy, /latest, /history, /year/{year},
| /archive/{year}, /draw/{draw} and /result/{draw} are declared BEFORE /{draw}.
| Reversed, the wildcard would capture a literal page segment and turn it into
| a draw lookup.
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:national-result-search (registered in
| AppServiceProvider from config('national_lottery.rate_limit')): IP per
| minute, IP per hour, and a hashed query fingerprint per minute. robots.txt
| asks crawlers to stay out of the same path, but that is a request - this
| limiter is the control.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/lotteries', [LotteryHubController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotteries.index');

Route::get('/national-lottery', [NationalLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('national-lottery.index');

Route::get('/national-lottery/buy', [LotteryPurchasePageController::class, 'national'])
    ->middleware('public.legal')
    ->name('national-lottery.buy');

Route::get('/national-lottery/latest', [NationalLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('national-lottery.latest');

Route::get('/national-lottery/history', [NationalLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('national-lottery.history');

Route::get('/national-lottery/draw/{draw}', [NationalLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.draw-detail');

Route::get('/national-lottery/result/{draw}', [NationalLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.result-detail');

Route::get('/national-lottery/search', [NationalLotteryController::class, 'search'])
    ->middleware('throttle:national-result-search')
    ->name('national-lottery.search');

Route::get('/national-lottery/year/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year');

Route::get('/national-lottery/archive/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year-archive');

Route::get('/national-lottery/{draw}', [NationalLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 6: public Weekly Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| weekly_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:weekly-result-search (registered in
| AppServiceProvider from config('weekly_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. A public lookup over
| a 1,000,000-value space is an enumeration oracle without it.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/weekly-lottery', [WeeklyLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('weekly-lottery.index');

Route::get('/weekly-lottery/buy', [LotteryPurchasePageController::class, 'weekly'])
    ->middleware('public.legal')
    ->name('weekly-lottery.buy');

Route::get('/weekly-lottery/search', [WeeklyLotteryController::class, 'search'])
    ->middleware('throttle:weekly-result-search')
    ->name('weekly-lottery.search');

Route::get('/weekly-lottery/latest', [WeeklyLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('weekly-lottery.latest');

Route::get('/weekly-lottery/history', [WeeklyLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('weekly-lottery.history');

Route::get('/weekly-lottery/archive/{year}', [WeeklyLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.archive');

Route::get('/weekly-lottery/draw/{draw}', [WeeklyLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.draw');

Route::get('/weekly-lottery/result/{draw}', [WeeklyLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.result');

Route::get('/weekly-lottery/year/{year}', [WeeklyLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.year');

Route::get('/weekly-lottery/{draw}', [WeeklyLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 8: public Bingo / Mega Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| bingo_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not the Weekly lane, not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| /search carries throttle:bingo-result-search (registered in
| AppServiceProvider from config('bingo_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. robots.txt asks
| crawlers to stay out of the same path, but that is a request - this limiter
| is the control.
|
*/
Route::get('/bingo-lottery', [BingoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('bingo-lottery.index');

Route::get('/bingo-lottery/search', [BingoLotteryController::class, 'search'])
    ->middleware('throttle:bingo-result-search')
    ->name('bingo-lottery.search');

Route::get('/bingo-lottery/buy', [BingoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('bingo-lottery.buy');

Route::get('/bingo-lottery/latest', [BingoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('bingo-lottery.latest');

Route::get('/bingo-lottery/history', [BingoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('bingo-lottery.history');

Route::get('/bingo-lottery/archive/{year}', [BingoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.archive');

Route::get('/bingo-lottery/draw/{draw}', [BingoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.draw');

Route::get('/bingo-lottery/result/{draw}', [BingoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.result');

Route::get('/bingo-lottery/year/{year}', [BingoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.year');

Route::get('/bingo-lottery/{draw}', [BingoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 9: public PCSO Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. Four routes over pcso_lottery_* data: not GLO
| L6/N3, not National, not Weekly, not Mega, not an operator market. They
| read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| The {draw} pattern allows the longer PCSO reference, which carries a draw
| TIME as well as a date (PCSO-20260910-2100) because this lane publishes
| several draws per day.
|
| /search carries throttle:pcso-result-search. robots.txt asks crawlers to
| stay out of the same path, but that is a request - this limiter is the
| control.
|
*/

Route::get('/pcso-lottery', [PcsoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('pcso-lottery.index');

Route::get('/pcso-lottery/search', [PcsoLotteryController::class, 'search'])
    ->middleware('throttle:pcso-result-search')
    ->name('pcso-lottery.search');

Route::get('/pcso-lottery/buy', [PcsoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('pcso-lottery.buy');

Route::get('/pcso-lottery/latest', [PcsoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('pcso-lottery.latest');

Route::get('/pcso-lottery/history', [PcsoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('pcso-lottery.history');

// /year/{year} is canonical. /archive/{year} is retained as a compatibility
// alias and is declared before both detail wildcards.
Route::get('/pcso-lottery/year/{year}', [PcsoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.year');

Route::get('/pcso-lottery/archive/{year}', [PcsoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.archive');

Route::get('/pcso-lottery/draw/{draw}', [PcsoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.draw');

Route::get('/pcso-lottery/result/{draw}', [PcsoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.result');

// Original compatibility route; every named detail route above wins first.
Route::get('/pcso-lottery/{draw}', [PcsoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.show');

// Static pages used by footer/support CTAs when configured.
Route::get('/privacy', [PublicPagesController::class, 'privacy'])
    ->middleware('public.legal')
    ->name('privacy');

/*
|--------------------------------------------------------------------------
| PROMPT 10: public Contact / Support centre
|--------------------------------------------------------------------------
|
| The GET route KEEPS ITS NAME. About, both footers, the privacy page and the
| terms page all link to route('contact'), and existing tests assert those
| links resolve. Renaming it to something tidier would have broken five
| surfaces to gain nothing.
|
| The POST carries throttle:contact-submit. A public endpoint that sends mail
| is a relay without one. It is also inside the normal web middleware group,
| so Laravel's CSRF protection applies - deliberately not excluded to make an
| AJAX submission simpler.
|
*/

/*
|--------------------------------------------------------------------------
| Public account-programme explainers
|--------------------------------------------------------------------------
|
| SIGNED-OUT INFORMATION, NOT THE ACCOUNT PAGES. /account/grade and
| /account/verification stay behind auth and show a person their own figures.
| These two show the LADDER and the PROCESS to somebody who has not
| registered and therefore cannot see either.
|
| Separate paths on purpose: relaxing auth on the existing routes would have
| meant one URL answering differently depending on who asked, which is how a
| personal figure eventually renders for a guest.
|
*/

Route::get('/account-grades', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grades');

Route::get('/account-grade', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grade');

Route::get('/account-verification', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification');

Route::get('/account-verification-guide', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification-guide');

Route::get('/contact', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact');

Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact-us');

Route::get('/download', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download');

Route::get('/download-app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download-app');

Route::get('/app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('app');

// XML sitemap (FINAL AUDIT #15): canonical public URLs only — no auth,
// admin, API, search-form, payment-return or legacy .php duplicates.
// Read-only and cacheable.
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)
    ->name('sitemap');

/*
|--------------------------------------------------------------------------
| Browser payment-return pages (FINAL AUDIT #2)
|--------------------------------------------------------------------------
|
| Where a gateway drops the player's browser after checkout. PRESENTATION
| ONLY: the landing route is context, the displayed state is always the
| internal payment record (see PaymentCallbackController), and nothing on
| these pages can credit or change money. Paths come from the same
| config/payment.php callback block the gateway drivers build their
| success/cancel URLs from, so they can never drift apart.
|
*/

Route::middleware('auth')->group(function (): void {
    // The config values may be absolute URLs ("${APP_URL}/payment/success")
    // because the gateway drivers hand them to providers; route registration
    // only wants the path component, so normalize once here.
    $callbackPath = static function (string $key, string $default): string {
        $value = (string) config('payment.callback.'.$key, $default);
        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $default;
    };

    Route::get($callbackPath('success_url', '/payment/success'), [PaymentCallbackController::class, 'success'])
        ->name('payment.callback.success');

    Route::get($callbackPath('failure_url', '/payment/failure'), [PaymentCallbackController::class, 'failure'])
        ->name('payment.callback.failure');

    Route::get($callbackPath('cancel_url', '/payment/cancel'), [PaymentCallbackController::class, 'cancel'])
        ->name('payment.callback.cancel');

    Route::get($callbackPath('pending_url', '/payment/pending'), [PaymentCallbackController::class, 'pending'])
        ->name('payment.callback.pending');
});

// User-facing locale switch route (session & cookie persistence)
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'th'], true)) {
        session(['locale' => $locale]);
        cookie()->queue(cookie('locale', $locale, 60 * 24 * 365));
    }

    return redirect()->back();
})->name('locale.switch');

Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('throttle:contact-submit')
    ->name('contact.submit');

/*
|--------------------------------------------------------------------------
| LOTTOFIN ADMIN & Operations Console Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->middleware(['admin.auth', 'can:access-admin'])->group(function (): void {
    Route::get('/legacy', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/api/analytics', [LottoFinExecutiveDashboardController::class, 'analyticsApi'])
        ->middleware('throttle:admin-analytics')
        ->name('api.analytics');
    Route::get('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'reconciliationFeedApi'])
        ->middleware('throttle:admin-analytics')
        ->name('api.reconciliation');
    Route::post('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'runReconciliation'])
        ->middleware(['throttle:admin-analytics', 'can:access-admin'])
        ->name('api.reconciliation.run');

    // Operational projections. Each request is permission-checked again in the
    // controller so a route alias cannot widen access to another panel.
    Route::get('/legacy/draws', [LottoFinExecutiveDashboardController::class, 'index'])->name('draws.index');
    Route::get('/risk', [LottoFinExecutiveDashboardController::class, 'index'])->name('risk.index');
    Route::get('/legacy/bets', [LottoFinExecutiveDashboardController::class, 'index'])->name('bets.index');
    Route::get('/legacy/wallets', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallets.index');
    Route::get('/ledger', [LottoFinExecutiveDashboardController::class, 'index'])->name('ledger.index');
    Route::get('/reconciliation', [LottoFinExecutiveDashboardController::class, 'index'])->name('reconciliation.index');
    Route::get('/audits', [LottoFinExecutiveDashboardController::class, 'index'])->name('audits.index');

    // Payment and withdrawal mutations are not implemented by this browser
    // console. They terminate in an explicit NOT_CONFIGURED response rather
    // than silently rendering a GET projection or changing financial state.
    Route::get('/payments', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments.index');
    Route::get('/legacy/withdrawals', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.index');
    Route::post('/withdrawals/{id}/disburse', [LottoFinExecutiveDashboardController::class, 'unsupportedMutation'])->name('withdrawals.disburse');
    Route::post('/withdrawals/{id}/reject', [LottoFinExecutiveDashboardController::class, 'unsupportedMutation'])->name('withdrawals.reject');

    // KYC documents remain on private storage and are streamed only after the
    // controller performs object-level reviewer authorization and audit logging.
    Route::get('/kyc', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.index');
    Route::get('/kyc/{documentToken}/download', [LottoFinExecutiveDashboardController::class, 'downloadKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.download');
    Route::post('/kyc/{documentToken}/approve', [LottoFinExecutiveDashboardController::class, 'reviewKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.approve');
    Route::post('/kyc/{documentToken}/reject', [LottoFinExecutiveDashboardController::class, 'reviewKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.reject');
    Route::get('/compliance', [LottoFinExecutiveDashboardController::class, 'index'])->name('compliance.index');

    // Pages 100–150 operational aliases. These remain read-only projections
    // unless an existing canonical service route is already used elsewhere.
    Route::get('/glo/prize-claims', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.prize-claims.index');
    Route::get('/glo/prize-claims/{claim}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('claim', '[A-Za-z0-9_-]{1,120}')
        ->name('glo.prize-claims.show');
    Route::get('/glo/ticket-freezes', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.ticket-freezes.index');
    Route::get('/glo/ticket-freezes/{token}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('token', '[A-Za-z0-9_-]{1,120}')
        ->name('glo.ticket-freezes.show');
    Route::get('/glo/settlements', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.settlements.index');
    Route::get('/wallet-operations', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallet-operations.index');
    Route::get('/payments/{payment}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('payment', '[0-9]+')
        ->name('payments.show');
    Route::get('/payment-methods', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-methods.index');
    Route::get('/withdrawal-methods', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawal-methods.index');
    Route::get('/payment-events', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-events.index');
    Route::get('/payments/events', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments-events.index');
    Route::get('/payment-exceptions', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-exceptions.index');
    Route::get('/payments/exceptions', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments-exceptions.index');
    Route::get('/draw-lifecycle', [LottoFinExecutiveDashboardController::class, 'index'])->name('draw-lifecycle.index');
    Route::get('/result-publication', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-publication.index');
    Route::get('/result-imports', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-imports.index');
    Route::get('/result-sources', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-sources.index');
    Route::get('/lotteries', [LottoFinExecutiveDashboardController::class, 'index'])->name('lotteries.index');
    Route::get('/lottery-rules', [LottoFinExecutiveDashboardController::class, 'index'])->name('lottery-rules.index');
    Route::get('/fees', [LottoFinExecutiveDashboardController::class, 'index'])->name('fees.index');
    Route::get('/account-grades', [LottoFinExecutiveDashboardController::class, 'index'])->name('account-grades.index');
    Route::get('/account-verification', [LottoFinExecutiveDashboardController::class, 'index'])->name('account-verification.index');
    Route::get('/responsible-gaming', [LottoFinExecutiveDashboardController::class, 'index'])->name('responsible-gaming.index');
    Route::get('/self-exclusion', [LottoFinExecutiveDashboardController::class, 'index'])->name('self-exclusion.index');
    Route::get('/legacy/users', [LottoFinExecutiveDashboardController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('user', '[0-9]+')
        ->name('users.show');
    Route::get('/users/{user}/finance', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('user', '[0-9]+')
        ->name('users.finance');
    Route::get('/bets/{bet}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('bet', '[0-9]+')
        ->name('bets.show');
    Route::get('/tickets/{ticket}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('ticket', '[0-9]+')
        ->name('tickets.show');
    Route::get('/ticket-verification', [LottoFinExecutiveDashboardController::class, 'index'])->name('ticket-verification.index');
    Route::get('/prize-claim-review', [LottoFinExecutiveDashboardController::class, 'index'])->name('prize-claim-review.index');
    Route::get('/commissions', [LottoFinExecutiveDashboardController::class, 'index'])->name('commissions.index');
    Route::get('/queues', [LottoFinExecutiveDashboardController::class, 'index'])->name('queues.index');
    Route::get('/scheduler', [LottoFinExecutiveDashboardController::class, 'index'])->name('scheduler.index');
    Route::get('/runtime', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'runtime')
        ->name('runtime.index');
    Route::get('/api-status', [LottoFinExecutiveDashboardController::class, 'index'])->name('api-status.index');
    Route::get('/webhooks', [LottoFinExecutiveDashboardController::class, 'index'])->name('webhooks.index');
    Route::get('/security', [LottoFinExecutiveDashboardController::class, 'index'])->name('security.index');
    Route::get('/release', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'manifest')
        ->name('release.index');
    Route::get('/cutover', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'cutover')
        ->name('cutover.index');

    Route::get('/release-manifest', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'manifest')
        ->name('release-manifest.index');
    Route::get('/configuration', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'configuration')
        ->name('configuration.index');
    Route::get('/secrets', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'secrets')
        ->name('secrets.index');
    Route::get('/migrations', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'migrations')
        ->name('migrations.index');
    Route::get('/backups', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'backups')
        ->name('backups.index');
    Route::get('/restore-verification', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'restore')
        ->name('restore-verification.index');
    Route::get('/disaster-recovery', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'disaster-recovery')
        ->name('disaster-recovery.index');
    Route::get('/high-availability', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'high-availability')
        ->name('high-availability.index');
    Route::get('/incidents', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'incidents')
        ->name('incidents.index');
    Route::get('/incidents/{reference}', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'incidents')
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('incidents.show');
    Route::get('/deployment-approval', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'deployment-approval')
        ->name('deployment-approval.index');
    Route::get('/deployments', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'deployments')
        ->name('deployments.index');
    Route::get('/rollback', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'rollback')
        ->name('rollback.index');
    Route::get('/feature-flags', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'feature-flags')
        ->name('feature-flags.index');
    Route::get('/configuration-audit', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'configuration-audit')
        ->name('configuration-audit.index');
    Route::get('/sessions', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'sessions')
        ->name('sessions.index');
    Route::get('/access-review', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'access-review')
        ->name('access-review.index');
    Route::get('/privileged-access', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'privileged-access')
        ->name('privileged-access.index');
    Route::get('/permission-matrix', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'permission-matrix')
        ->name('permission-matrix.index');
    Route::get('/service-accounts', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'service-accounts')
        ->name('service-accounts.index');
    Route::get('/network-access', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'network-access')
        ->name('network-access.index');
    Route::get('/device-risk', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'device-risk')
        ->name('device-risk.index');
    Route::get('/mfa', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'mfa')
        ->name('mfa.index');
    Route::get('/authentication-security', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'authentication-security')
        ->name('authentication-security.index');
    Route::get('/rate-limits', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'rate-limits')
        ->name('rate-limits.index');
    Route::get('/captcha', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'captcha')
        ->name('captcha.index');
    Route::get('/risk-rules', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'risk-rules')
        ->name('risk-rules.index');
    Route::get('/suspicious-activity', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'suspicious-activity')
        ->name('suspicious-activity.index');
    Route::get('/compliance-cases', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-cases')
        ->name('compliance-cases.index');
    Route::get('/compliance-cases/{reference}', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-cases')
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('compliance-cases.show');
    Route::get('/sanctions', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'sanctions')
        ->name('sanctions.index');
    Route::get('/kyc-provider', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'kyc-provider')
        ->name('kyc-provider.index');
    Route::get('/kyc-review', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'kyc-review')
        ->name('kyc-review.index');
    Route::get('/age-verification', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'age-verification')
        ->name('age-verification.index');
    Route::get('/duplicate-accounts', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'duplicate-accounts')
        ->name('duplicate-accounts.index');
    Route::get('/account-restrictions', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'account-restrictions')
        ->name('account-restrictions.index');
    Route::get('/retention', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'retention')
        ->name('retention.index');
    Route::get('/privacy', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'privacy')
        ->name('privacy.index');
    Route::get('/data-rights', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'data-rights')
        ->name('data-rights.index');
    Route::get('/legal-registries', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'legal-registries')
        ->name('legal-registries.index');
    Route::get('/compliance-reporting', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-reporting')
        ->name('compliance-reporting.index');
    Route::get('/aml-monitoring', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'aml-monitoring')
        ->name('aml-monitoring.index');
    Route::get('/regulatory-exports', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'regulatory-exports')
        ->name('regulatory-exports.index');
    Route::get('/compliance-audit', [ReleaseOperationsController::class, 'show'])
        ->defaults('surface', 'compliance-audit')
        ->name('compliance-audit.index');
});

/*
|--------------------------------------------------------------------------
| Authenticated support and notification projections
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/support', [SupportPortalController::class, 'index'])->name('support.index');
    Route::post('/support', [SupportPortalController::class, 'store'])
        ->middleware('throttle:contact-submit')
        ->name('support.store');
    Route::get('/support/{reference}', [SupportPortalController::class, 'show'])
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('support.show');
    Route::post('/support/{reference}/reply', [SupportPortalController::class, 'reply'])
        ->middleware('throttle:contact-submit')
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('support.reply');
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
});

/*
|--------------------------------------------------------------------------
| Agent Portal Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agent')->name('agent.')->middleware('auth')->group(function (): void {
    Route::get('/', [AgentPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard', [AgentPortalController::class, 'dashboard'])->name('dashboard.index');
    Route::get('/commissions', [AgentPortalController::class, 'commissions'])->name('commissions');
    Route::get('/settlements', [AgentPortalController::class, 'settlements'])->name('settlements');
    Route::get('/statement', [AgentPortalController::class, 'statement'])->name('statement');
    Route::get('/referrals', [AgentPortalController::class, 'referrals'])->name('referrals');
    Route::get('/referrals/{reference}', [AgentPortalController::class, 'referralDetail'])
        ->where('reference', '[a-f0-9]{64}')
        ->name('referrals.show');
});

/*
|--------------------------------------------------------------------------
| Legacy .php URL compatibility layer (301)
|--------------------------------------------------------------------------
|
| Single home for every public .php URL the replaced site published:
| static pages, member auth surfaces, account explainer pages, the broken
| double-path member URLs, and the per-year archive pages — including the
| "lottoery" typo form search engines indexed. See LegacyRedirectController
| for the map and the rules.
|
| THIS MUST STAY THE LAST ROUTE IN THIS FILE. It only ever sees paths no
| real route claimed, because Laravel matches in registration order, and
| it answers 404 for .php paths it does not know rather than aliasing them.
|
*/

Route::match(['get', 'post'], '/{legacyPath}', [LegacyRedirectController::class, 'resolve'])
    ->where('legacyPath', '.*\.php$')
    ->name('legacy.redirect');
