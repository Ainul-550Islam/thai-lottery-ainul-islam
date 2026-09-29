<?php

declare(strict_types=1);

use App\Http\Controllers\AccountGradeController;
use App\Http\Controllers\AccountVerificationController;
use App\Http\Controllers\BingoLotteryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LottoDiscountController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NationalLotteryController;
use App\Http\Controllers\PcsoLotteryController;
use App\Http\Controllers\PrizeVerificationController;
use App\Http\Controllers\PublicAccountInfoController;
use App\Http\Controllers\PublicPagesController;
use App\Http\Controllers\PublicServicePagesController;
use App\Http\Controllers\Auth\MemberAuthController;
use App\Http\Controllers\Verification\AccountVerificationController as MemberAccountVerificationController;
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
|   player.profile.limits
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
    Route::get('/bets', [PlayerWebController::class, 'bets'])->name('player.bets');

    Route::get('/wallet', [PlayerWebController::class, 'wallet'])->name('player.wallet');

    Route::get('/deposit', [PlayerWebController::class, 'deposit'])->name('player.deposit');
    Route::post('/deposit', [PlayerWebController::class, 'storeDeposit'])->name('player.deposit.store');

    Route::get('/withdraw', [PlayerWebController::class, 'withdraw'])->name('player.withdraw');
    Route::post('/withdraw', [PlayerWebController::class, 'storeWithdraw'])->name('player.withdraw.store');

    Route::get('/profile', [PlayerWebController::class, 'profile'])->name('player.profile');
    Route::put('/profile', [PlayerWebController::class, 'updateProfile'])->name('player.profile.update');
    Route::put('/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.profile.password');
    Route::put('/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.profile.limits');
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
    Route::get('/account/verification/document/{document}', [MemberAccountVerificationController::class, 'download'])
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
Route::view('/results', 'results.index')->name('results.index');

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
Route::get('/fees', [PublicServicePagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('fees');

// Public Prize Verification (PROMPT 4) — anonymous ticket / result checker.
// The POST carries throttle:ticket-verification because a public six-digit
// checker is an enumeration oracle without a ceiling. The verification answer
// is a fixed public vocabulary; nothing user-identifying is ever rendered.
Route::get('/prize-verification', [PrizeVerificationController::class, 'show'])
    ->middleware('public.legal')
    ->name('prize-verification');
Route::post('/prize-verification', [PrizeVerificationController::class, 'verify'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.submit');

// Public Lotto Discount catalogue (PROMPT 4) — published rules only, server
// computed. GLO L6/N3 appear as immutable-price products and can never carry
// a discount row.
Route::get('/discounts', [LottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('discounts');

/*
|--------------------------------------------------------------------------
| Public National Lottery results (PROMPT 5) — anonymous by design
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These four routes serve national_lottery_* data
| only. They do not touch GLO L6/N3 or the operator markets, and they do not
| collide with the existing public surfaces: /results (GLO result view),
| /prize-verification and /discounts keep their own paths and their own
| controllers, unchanged.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE the
| /{draw} wildcard, so the literal paths can never be captured and read as a
| draw reference.
|
| BOUNDS ARE ON THE ROUTE, NOT ONLY IN THE CONTROLLER. {year} is at most four
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:national-result-search (registered in
| AppServiceProvider from config('national_lottery.rate_limit')): IP per
| minute, IP per hour, and a hashed query fingerprint per minute. A public
| lookup over a 1,000,000-value space is an enumeration oracle without it.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/national-lottery', [NationalLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('national-lottery.index');

Route::get('/national-lottery/search', [NationalLotteryController::class, 'search'])
    ->middleware('throttle:national-result-search')
    ->name('national-lottery.search');

Route::get('/national-lottery/year/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year');

Route::get('/national-lottery/{draw}', [NationalLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.show');

/*
|--------------------------------------------------------------------------
| Public Weekly Lottery results (PROMPT 6) — anonymous by design
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These four routes serve weekly_lottery_* data only.
| They do not touch GLO L6/N3, the National lane or the operator markets, and
| they do not collide with the existing public surfaces: /results,
| /national-lottery, /prize-verification and /discounts keep their own paths
| and their own controllers, unchanged.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE the
| /{draw} wildcard, so the literal paths can never be captured and read as a
| draw reference.
|
| BOUNDS ARE ON THE ROUTE, NOT ONLY IN THE CONTROLLER. {year} is at most four
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

Route::get('/weekly-lottery/search', [WeeklyLotteryController::class, 'search'])
    ->middleware('throttle:weekly-result-search')
    ->name('weekly-lottery.search');

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
| A SEPARATE PRODUCT LANE. These four routes serve bingo_lottery_* data and
| nothing else: not GLO L6/N3, not the National lane, not the Weekly lane, not
| an operator market. They read; nothing here writes.
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

Route::get('/pcso-lottery/year/{year}', [PcsoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.year');

Route::get('/pcso-lottery/{draw}', [PcsoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.show');

// Static pages used by footer/support CTAs when configured.
Route::view('/privacy', 'static.privacy')->name('privacy');
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

Route::get('/account-grades', [PublicAccountInfoController::class, 'grades'])
    ->name('account-grades');

Route::get('/account-verification-guide', [PublicAccountInfoController::class, 'verification'])
    ->name('account-verification-guide');

Route::get('/contact', [ContactController::class, 'show'])->name('contact');

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

Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('throttle:contact-submit')
    ->name('contact.submit');

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
