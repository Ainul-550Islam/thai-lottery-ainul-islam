<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\PublicPagesController;
use App\Http\Controllers\PublicServicePagesController;
use App\Http\Controllers\AccountGradeController;
use App\Http\Controllers\AccountVerificationController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\PlayerWebController;
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
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt')->middleware('throttle:login');
    Route::get('/register', [AuthController::class, 'showRegisterForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.attempt')->middleware('throttle:login');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

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
    Route::get('/account/verification', [AccountVerificationController::class, 'show'])
        ->name('account.verification');
    Route::post('/account/verification', [AccountVerificationController::class, 'submit'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.submit');
    Route::get('/account/verification/document/{document}', [AccountVerificationController::class, 'download'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.document');

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

// Static pages used by footer/support CTAs when configured.
Route::view('/privacy', 'static.privacy')->name('privacy');
Route::get('/contact', [HomeController::class, 'contact'])->name('contact');
