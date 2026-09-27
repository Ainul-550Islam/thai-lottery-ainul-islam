<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\OperationsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BetAmendmentController;
use App\Http\Controllers\Api\V1\BetCancellationController;
use App\Http\Controllers\Api\V1\BetController;
use App\Http\Controllers\Api\V1\BetPurchaseController;
use App\Http\Controllers\Api\V1\BingoLotteryController;
use App\Http\Controllers\Api\V1\BulkBetController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\DrawController;
use App\Http\Controllers\Api\V1\DrawResultController;
use App\Http\Controllers\Api\V1\GloController;
use App\Http\Controllers\Api\V1\KycController;
use App\Http\Controllers\Api\V1\NationalLotteryController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\PayoutController;
use App\Http\Controllers\Api\V1\PcsoLotteryController;
use App\Http\Controllers\Api\V1\PrizeClaimController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ResponsibleGamingController;
use App\Http\Controllers\Api\V1\SecurityController;
use App\Http\Controllers\Api\V1\TicketController;
use App\Http\Controllers\Api\V1\TicketOwnershipController;
use App\Http\Controllers\Api\V1\TicketProductController;
use App\Http\Controllers\Api\V1\TicketVerificationController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WeeklyLotteryController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use App\Http\Controllers\LottoDiscountController;
use App\Http\Controllers\PrizeVerificationController;
use App\Http\Controllers\PublicServicePagesController;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Phase 4.4 establishes the project's first HTTP surface. Phase 1 left this
| file as a placeholder, so there was no existing route convention to
| preserve and this file defines it: everything lives under an explicit
| /api/v1 prefix with named routes, so a future v2 can be added beside it
| without renaming or breaking v1.
|
| WHY EVERY ROUTE IS AUTHENTICATED
| There is no public endpoint here. Not a price list, not a draw list, not a
| market list. Every route in this file sits behind auth:sanctum, so an
| unauthenticated request cannot reach a controller at all - it is stopped by
| middleware before any application code runs.
|
| THE MIDDLEWARE STACK, AND WHY EACH LAYER IS PRESENT
| - auth:sanctum: establishes WHO the caller is. Sanctum is already installed,
|   the personal_access_tokens migration already exists, and App\Models\User
|   already uses HasApiTokens - so this is the project's existing mechanism,
|   not a new one.
| - active: the existing EnsureUserIsActive middleware. Authentication proves
|   identity; it does not prove the account is permitted to trade. A suspended
|   player holding a still-valid token is stopped here.
| - throttle:api: a per-caller ceiling on the whole surface.
| - throttle:bet: an additional, much tighter ceiling on the purchase route
|   only. Both limiters are configured from config/security.php, which already
|   declared these values and noted that "Named limiters are registered from
|   these values in a later phase" - Phase 4.4 is that phase, and they are
|   registered in AppServiceProvider.
|
| WHAT IS DELIBERATELY NOT HERE
| No payment gateway route, no webhook route, no draw-result or settlement
| route, no payout route, no agent route, no admin route. Those belong to
| later phases and adding an empty stub for them now would imply a contract
| this phase cannot honour.
|
| The `wallet.active` middleware is also deliberately not applied to the
| purchase route. Wallet state is checked inside the Phase 4.3 purchase, under
| the wallet's own row lock, and that is the check that actually decides the
| outcome. A second check out here would be a weaker duplicate of a financial
| rule this phase must not duplicate.
|
*/

/*
|--------------------------------------------------------------------------
| Authentication (added after Phase 5.1)
|--------------------------------------------------------------------------
|
| Phase 4.4 stated that every route on this surface is authenticated, and it was
| right to - but it left no route that could ISSUE a credential, so no client
| could reach any endpoint at all. The token surface below is the minimum that
| makes the existing surface usable, built on the Sanctum installation the
| project already had.
|
| /auth/login is the ONE public route in this file. It carries `throttle:login`,
| a limiter registered in AppServiceProvider from the login ceilings that
| config/security.php already declared and keyed, per that config, on the
| submitted identifier AND the client IP - so neither an account nor an address
| can be attacked freely. It does NOT carry `active`: that middleware requires an
| authenticated user, and account status is checked inside the controller.
|
*/
Route::prefix('v1/auth')
    ->name('api.v1.auth.')
    ->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('login');

        Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
            // `active` is applied to /me and /logout but NOT relaxed anywhere: a suspended
            // account may still revoke its own token, which is why logout is grouped here
            // with the same middleware the rest of the surface uses.
            Route::get('/me', [AuthController::class, 'me'])->middleware('active')->name('me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        });
    });

Route::middleware(['throttle:glo.public'])->group(function (): void {
    Route::get('/v1/glo/public/tickets/{reference}/status', [GloController::class, 'publicTicketStatus'])
        ->where('reference', '[A-Za-z0-9_\-]{3,80}')
        ->name('api.v1.glo.public.status');

    // GLO-16/18 public surfaces: sales points + result experience
    // (same limiter — enumeration protection on public lookups).
    Route::get('/v1/glo/sales-points', [GloController::class, 'salesPoints'])
        ->name('api.v1.glo.sales-points.index');
    Route::get('/v1/glo/results', [GloController::class, 'publicResults'])
        ->name('api.v1.glo.results.current');
    Route::get('/v1/glo/results/history', [GloController::class, 'publicResultHistory'])
        ->name('api.v1.glo.results.history');
    Route::get('/v1/glo/results/check/{number}', [GloController::class, 'publicCheckSixDigit'])
        ->where('number', '\d{6}')
        ->name('api.v1.glo.results.check');
    Route::post('/v1/glo/results/check', [GloController::class, 'publicCheckSixDigit'])
        ->name('api.v1.glo.results.check.post');
    Route::get('/v1/glo/results/sheet', [GloController::class, 'publicResultSheet'])
        ->name('api.v1.glo.results.sheet');
    Route::post('/v1/glo/results/datamatrix', [GloController::class, 'verifyDataMatrix'])
        ->name('api.v1.glo.results.datamatrix');
    Route::get('/v1/glo/results/live', [GloController::class, 'liveDrawStatus'])
        ->name('api.v1.glo.results.live');
    Route::get('/v1/glo/results/replay', [GloController::class, 'replayStatus'])
        ->name('api.v1.glo.results.replay');
    // Path-style single-draw lookup (?draw= also works on /results).
    Route::get('/v1/glo/results/{draw}', [GloController::class, 'publicResultByDraw'])
        ->where('draw', '[A-Za-z0-9_\-]{1,40}')
        ->name('api.v1.glo.results.show');
});

/*
|--------------------------------------------------------------------------
| PROMPT 5: public National Lottery JSON surface
|--------------------------------------------------------------------------
|
| A SEPARATE LANE from /v1/glo/results above. Different tables, different
| controller, different limiter, different projection. Nothing here reads a
| glo_* table and nothing there reads a national_lottery_* one.
|
| ORDER IS LOAD-BEARING: /results/year/{year} is declared before
| /results/{draw}, so the literal segment cannot be captured by the wildcard.
|
| The three read endpoints use the generic 'api' limiter; /search uses the
| dedicated 'national-result-search' limiter, because the six-digit space is
| enumerable and the generic ceiling is sized for ordinary traffic.
*/
Route::prefix('v1/national-lottery')
    ->name('api.v1.national-lottery.')
    ->group(function (): void {
        Route::get('/results', [NationalLotteryController::class, 'index'])
            ->middleware('throttle:api')
            ->name('results.index');

        Route::get('/results/year/{year}', [NationalLotteryController::class, 'year'])
            ->where('year', '[0-9]{1,4}')
            ->middleware('throttle:api')
            ->name('results.year');

        Route::get('/search', [NationalLotteryController::class, 'search'])
            ->middleware('throttle:national-result-search')
            ->name('search');

        Route::get('/results/{draw}', [NationalLotteryController::class, 'show'])
            ->where('draw', '[A-Za-z0-9\-]{1,40}')
            ->middleware('throttle:api')
            ->name('results.show');
    });

/*
|--------------------------------------------------------------------------
| PROMPT 6: public Weekly Lottery JSON surface
|--------------------------------------------------------------------------
|
| A SEPARATE LANE from /v1/glo/results and /v1/national-lottery. Different
| tables, different controller, different limiter, different projection.
|
| ORDER IS LOAD-BEARING: /results/year/{year} is declared before
| /results/{draw}, so the literal segment cannot be captured by the wildcard.
|
| The three read endpoints use the generic 'api' limiter; /search uses the
| dedicated 'weekly-result-search' limiter, because the six-digit space is
| enumerable and the generic ceiling is sized for ordinary traffic.
*/
Route::prefix('v1/weekly-lottery')
    ->name('api.v1.weekly-lottery.')
    ->group(function (): void {
        Route::get('/results', [WeeklyLotteryController::class, 'index'])
            ->middleware('throttle:api')
            ->name('results.index');

        Route::get('/results/year/{year}', [WeeklyLotteryController::class, 'year'])
            ->where('year', '[0-9]{1,4}')
            ->middleware('throttle:api')
            ->name('results.year');

        Route::get('/search', [WeeklyLotteryController::class, 'search'])
            ->middleware('throttle:weekly-result-search')
            ->name('search');

        Route::get('/results/{draw}', [WeeklyLotteryController::class, 'show'])
            ->where('draw', '[A-Za-z0-9\-]{1,40}')
            ->middleware('throttle:api')
            ->name('results.show');
    });

/*
|--------------------------------------------------------------------------
| PROMPT 8: public Bingo / Mega Lottery JSON surface
|--------------------------------------------------------------------------
|
| A SEPARATE LANE from /v1/glo/results, /v1/national-lottery and
| /v1/weekly-lottery. Same shape, different data, no shared table.
|
| Every route is a public GET carrying throttle:api, except /search, which
| carries the dedicated 'bingo-result-search' limiter because a six-digit
| space is enumerable. The projection exposes published result fields and
| provenance-safe metadata only: no token, no endpoint, no internal id, no
| importer detail.
|
*/
Route::prefix('v1/bingo-lottery')
    ->name('api.v1.bingo-lottery.')
    ->group(function (): void {
        Route::get('/results', [BingoLotteryController::class, 'index'])
            ->middleware('throttle:api')
            ->name('results.index');

        Route::get('/results/year/{year}', [BingoLotteryController::class, 'year'])
            ->where('year', '[0-9]{1,4}')
            ->middleware('throttle:api')
            ->name('results.year');

        Route::get('/search', [BingoLotteryController::class, 'search'])
            ->middleware('throttle:bingo-result-search')
            ->name('search');

        Route::get('/results/{draw}', [BingoLotteryController::class, 'show'])
            ->where('draw', '[A-Za-z0-9\-]{1,40}')
            ->middleware('throttle:api')
            ->name('results.show');
    });

/*
|--------------------------------------------------------------------------
| PROMPT 9: public PCSO Lottery JSON surface
|--------------------------------------------------------------------------
|
| Same four-route shape as the sibling lanes, different data, no shared
| table. The projection carries the draw TIME alongside the date, because in
| this lane a date alone does not identify a draw.
|
| No token, no endpoint, no internal id, no importer detail.
|
*/

Route::prefix('v1/pcso-lottery')
    ->name('api.v1.pcso-lottery.')
    ->group(function (): void {
        Route::get('/results', [PcsoLotteryController::class, 'index'])
            ->middleware('throttle:api')
            ->name('results.index');

        Route::get('/results/year/{year}', [PcsoLotteryController::class, 'year'])
            ->where('year', '[0-9]{1,4}')
            ->middleware('throttle:api')
            ->name('results.year');

        Route::get('/search', [PcsoLotteryController::class, 'search'])
            ->middleware('throttle:pcso-result-search')
            ->name('search');

        Route::get('/results/{draw}', [PcsoLotteryController::class, 'show'])
            ->where('draw', '[A-Za-z0-9\-]{1,40}')
            ->middleware('throttle:api')
            ->name('results.show');
    });

Route::prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        // PROMPT 3: public fee schedule (config-driven; no user-specific fees).
        Route::get('/fees', [PublicServicePagesController::class, 'feesApi'])
            ->middleware('throttle:api')
            ->name('fees.index');

        // PROMPT 4: public discount catalogue (published rules only, server
        // computed). Same projection the /discounts page renders, so the JSON
        // surface cannot drift from the HTML one.
        Route::get('/discounts', [LottoDiscountController::class, 'indexApi'])
            ->middleware('throttle:api')
            ->name('discounts.index');

        // PROMPT 4: public prize verification. Carries the dedicated
        // enumeration limiter, not the generic api one.
        Route::post('/prize-verification', [PrizeVerificationController::class, 'verify'])
            ->middleware('throttle:ticket-verification')
            ->name('prize-verification.verify');
    });

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {

        // The purchase route. The only mutating endpoint in this phase, and the
        // only one carrying the tighter `throttle:bet` limiter.
        Route::post('/bets/purchase', [BetPurchaseController::class, 'store'])
            ->middleware('throttle:bet')
            ->name('bets.purchase');

        // Multi-selection slip and permutation (กลับเลข) purchasing. Both run
        // selections through the single-bet pipeline sequentially, so they
        // inherit its money rules — and its tighter limiter.
        Route::post('/bets/quote', [BulkBetController::class, 'quote'])
            ->name('bets.quote');
        Route::post('/bets/purchase-bulk', [BulkBetController::class, 'purchase'])
            ->middleware('throttle:bet')
            ->name('bets.purchase-bulk');
        Route::post('/bets/permutations/preview', [BulkBetController::class, 'previewPermutation'])
            ->name('bets.permutations.preview');
        Route::post('/bets/permutations/purchase', [BulkBetController::class, 'purchasePermutation'])
            ->middleware('throttle:bet')
            ->name('bets.permutations.purchase');

        // Cancellation and amendment of the caller's own bet. The {bet}
        // identifier carries the same conservative constraint as the read
        // endpoints; ownership is enforced by the services' user-scoped
        // resolution (a foreign bet is a 404, indistinguishable from unknown).
        Route::post('/bets/{bet}/cancel', [BetCancellationController::class, 'store'])
            ->where('bet', '[A-Za-z0-9-]{1,64}')
            ->name('bets.cancel');
        Route::post('/bets/{bet}/amend', [BetAmendmentController::class, 'store'])
            ->where('bet', '[A-Za-z0-9-]{1,64}')
            ->middleware('throttle:bet')
            ->name('bets.amend');

        // Ticket verification (owner-scoped detail) and share links.
        Route::post('/tickets/verify', [TicketVerificationController::class, 'verify'])
            ->name('tickets.verify');
        Route::post('/tickets/{ticket}/share', [TicketVerificationController::class, 'share'])
            ->where('ticket', '[A-Za-z0-9-]{1,64}')
            ->name('tickets.share');
        Route::delete('/tickets/shares/{share}', [TicketVerificationController::class, 'revokeShare'])
            ->whereNumber('share')
            ->name('tickets.shares.revoke');

        // Read endpoints. Both accept either the numeric id or the uuid, and both
        // resolve it inside a query already scoped to the authenticated user.
        //
        // The {bet} and {ticket} parameters are constrained to a conservative
        // character class at the route level. This is not the authorization check -
        // that is the user-scoped query plus the existing policy inside the
        // controller - it simply means a hostile identifier never reaches the query
        // builder in the first place.
        Route::get('/bets/{bet}', [BetController::class, 'show'])
            ->where('bet', '[A-Za-z0-9-]{1,64}')
            ->name('bets.show');

        Route::get('/bets/{bet}/status', [BetController::class, 'status'])
            ->where('bet', '[A-Za-z0-9-]{1,64}')
            ->name('bets.status');

        // Player reads: paginated own bets and own tickets. Both scoped to the
        // authenticated user inside the controller.
        Route::get('/bets', [BetController::class, 'index'])->name('bets.index');
        Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');

        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
            ->where('ticket', '[A-Za-z0-9-]{1,64}')
            ->name('tickets.show');

        // Lottery draws and published official results.
        Route::get('/draws', [DrawController::class, 'index'])->name('draws.index');
        Route::get('/draws/current', [DrawController::class, 'current'])->name('draws.current');
        Route::get('/draws/{draw}', [DrawController::class, 'show'])
            ->where('draw', '[A-Za-z0-9-]{1,64}')
            ->name('draws.show');
        Route::get('/draws/{draw}/results', [DrawController::class, 'results'])
            ->where('draw', '[A-Za-z0-9-]{1,64}')
            ->name('draws.results');

        // Wallet balance and transaction history.
        Route::get('/wallet', [WalletController::class, 'show'])->name('wallet.show');
        Route::get('/wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');

        // Profile and password.
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        // KYC status and document upload.
        Route::get('/kyc/status', [KycController::class, 'status'])->name('kyc.status');
        Route::post('/kyc/upload', [KycController::class, 'upload'])->name('kyc.upload');

        // Responsible gaming limits and self-exclusion.
        Route::get('/responsible-gaming', [ResponsibleGamingController::class, 'show'])->name('responsible-gaming.show');
        Route::put('/responsible-gaming/limits', [ResponsibleGamingController::class, 'updateLimits'])->name('responsible-gaming.limits');
        Route::post('/responsible-gaming/self-exclude', [ResponsibleGamingController::class, 'selfExclude'])->name('responsible-gaming.self-exclude');
        // Batch-14 server-authoritative lanes (additive; legacy lanes above stay).
        Route::post('/responsible-gaming/limits/pronounce', [ResponsibleGamingController::class, 'pronounceLimit'])->name('responsible-gaming.limits.pronounce');
        Route::post('/responsible-gaming/self-exclusion', [ResponsibleGamingController::class, 'requestSelfExclusion'])->name('responsible-gaming.self-exclusion.request');
        Route::get('/responsible-gaming/reality-checks', [ResponsibleGamingController::class, 'realityChecks'])->name('responsible-gaming.reality-checks.index');
        Route::post('/responsible-gaming/reality-checks/acknowledge', [ResponsibleGamingController::class, 'acknowledgeRealityCheck'])->name('responsible-gaming.reality-checks.acknowledge');
        Route::get('/responsible-gaming/protection-state', [ResponsibleGamingController::class, 'protectionState'])->name('responsible-gaming.protection-state');
        // Account security lane (batch-15): MFA, sessions, devices, ledger.
        Route::post('/security/mfa/challenge', [SecurityController::class, 'challengeMfa'])->name('security.mfa.challenge');
        Route::post('/security/mfa/verify', [SecurityController::class, 'verifyMfa'])->name('security.mfa.verify');
        Route::get('/security/sessions', [SecurityController::class, 'sessions'])->name('security.sessions.index');
        Route::delete('/security/sessions/{reference}', [SecurityController::class, 'revokeSession'])->name('security.sessions.revoke');
        Route::post('/security/devices/trust', [SecurityController::class, 'trustDevice'])->name('security.devices.trust');
        Route::delete('/security/devices/{fingerprint}', [SecurityController::class, 'revokeDevice'])->name('security.devices.revoke');
        Route::get('/security/events', [SecurityController::class, 'eventsSummary'])->name('security.events.summary');
        // Transactional notifications (batch-16).
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::get('/notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences.index');
        Route::put('/notifications/preferences', [NotificationController::class, 'writePreference'])->name('notifications.preferences.write');
        Route::get('/notifications/status/{fingerprint}', [NotificationController::class, 'status'])->name('notifications.status');

        // GLO official reference surface. Read-only: the prize ladder, one draw's
        // recorded official numbers, and the 6-digit ticket checker. A winning
        // check is reported, never credited — the physical GLO ticket is paid in
        // person, not through the wallet.
        Route::get('/glo/prizes', [GloController::class, 'prizes'])->name('glo.prizes');
        Route::get('/glo/draws/{draw}', [GloController::class, 'draw'])
            ->where('draw', '[A-Za-z0-9-]{1,64}')
            ->name('glo.draw');
        Route::post('/glo/check-ticket', [GloController::class, 'checkTicket'])->name('glo.check-ticket');

        // GLO-11 freeze mutations (permission-gated, default deny via glo.permission).
        Route::post('/glo/tickets/{ticket}/freeze', [GloController::class, 'requestFreeze'])
            ->middleware('glo.permission:'.AdminAccess::REQUEST_GLO_FREEZES)
            ->name('glo.tickets.freeze');
        Route::post('/glo/freezes/{freeze}/review', [GloController::class, 'reviewFreeze'])
            ->middleware('glo.permission:'.AdminAccess::REVIEW_GLO_FREEZES)
            ->name('glo.freezes.review');
        Route::post('/glo/freezes/{freeze}/approve', [GloController::class, 'approveFreeze'])
            ->middleware('glo.permission:'.AdminAccess::REVIEW_GLO_FREEZES)
            ->name('glo.freezes.approve');
        Route::post('/glo/freezes/{freeze}/reject', [GloController::class, 'rejectFreeze'])
            ->middleware('glo.permission:'.AdminAccess::REVIEW_GLO_FREEZES)
            ->name('glo.freezes.reject');
        Route::post('/glo/freezes/{freeze}/release', [GloController::class, 'releaseFreeze'])
            ->middleware('glo.permission:'.AdminAccess::REVIEW_GLO_FREEZES)
            ->name('glo.freezes.release');

        // GLO-12/14 claim mutations (401/403 default deny; claimant may submit own claim).
        Route::post('/glo/claims', [GloController::class, 'submitClaim'])->name('glo.claims.store');
        Route::get('/glo/claims/{claim}', [GloController::class, 'claim'])->name('glo.claims.show');
        Route::post('/glo/claims/{claim}/review', [GloController::class, 'reviewClaim'])
            ->middleware('glo.permission:'.AdminAccess::MANAGE_GLO_PRIZE_CLAIMS)
            ->name('glo.claims.review');
        Route::post('/glo/claims/{claim}/approve', [GloController::class, 'approveClaim'])
            ->middleware('glo.permission:'.AdminAccess::MANAGE_GLO_PRIZE_CLAIMS)
            ->name('glo.claims.approve');
        Route::post('/glo/claims/{claim}/reject', [GloController::class, 'rejectClaim'])
            ->middleware('glo.permission:'.AdminAccess::MANAGE_GLO_PRIZE_CLAIMS)
            ->name('glo.claims.reject');
        Route::post('/glo/claims/{claim}/pay', [GloController::class, 'payClaim'])
            ->middleware('glo.permission:'.AdminAccess::EXECUTE_GLO_PRIZE_PAYMENTS)
            ->name('glo.claims.pay');
        Route::post('/glo/claims/{claim}/cancel', [GloController::class, 'cancelClaim'])->name('glo.claims.cancel');

        // GLO-17 authenticated user: saved tickets + own notification deliveries.
        Route::get('/glo/me/tickets', [GloController::class, 'listMySavedTickets'])->name('glo.me.tickets');
        Route::post('/glo/me/tickets/{ticket}/save', [GloController::class, 'saveMyTicket'])
            ->where('ticket', '\d+')
            ->name('glo.me.tickets.save');
        Route::delete('/glo/me/tickets/{ticket}/save', [GloController::class, 'removeMySavedTicket'])
            ->where('ticket', '\d+')
            ->name('glo.me.tickets.unsave');
        Route::get('/glo/me/notifications', [GloController::class, 'myGloNotifications'])->name('glo.me.notifications');

        // GLO-15/16 dealer e-Service (dealer is a user with a GloDealer profile).
        Route::get('/glo/dealer/profile', [GloController::class, 'dealerProfile'])->name('glo.dealer.profile');
        Route::get('/glo/dealer/history', [GloController::class, 'dealerHistory'])->name('glo.dealer.history');
        Route::post('/glo/dealer/change-requests', [GloController::class, 'submitDealerChangeRequest'])->name('glo.dealer.change-requests.store');
        Route::get('/glo/dealer/change-requests', [GloController::class, 'dealerChangeRequests'])->name('glo.dealer.change-requests.index');
        Route::post('/glo/dealer/sales-location', [GloController::class, 'updateDailySalesLocation'])->name('glo.dealer.sales-location');

        // Operator review (permission middleware — default deny).
        Route::get('/glo/operator/dealer-requests', [GloController::class, 'operatorDealerRequests'])
            ->middleware('glo.permission:'.AdminAccess::REVIEW_GLO_DEALER_REQUESTS)
            ->name('glo.operator.dealer-requests.index');
        Route::post('/glo/operator/dealer-requests/{reference}/approve', [GloController::class, 'operatorApproveDealerRequest'])
            ->where('reference', '[A-Za-z0-9\-]{6,40}')
            ->middleware('glo.permission:'.AdminAccess::REVIEW_GLO_DEALER_REQUESTS)
            ->name('glo.operator.dealer-requests.approve');
        Route::post('/glo/operator/dealer-requests/{reference}/reject', [GloController::class, 'operatorRejectDealerRequest'])
            ->where('reference', '[A-Za-z0-9\-]{6,40}')
            ->middleware('glo.permission:'.AdminAccess::REVIEW_GLO_DEALER_REQUESTS)
            ->name('glo.operator.dealer-requests.reject');
    });

/*
|--------------------------------------------------------------------------
| Deposits (added for the payment-gateway surface)
|--------------------------------------------------------------------------
|
| The player-facing deposit endpoints behind the same authenticated stack as
| the rest of the surface. The contract: POST /deposits initiates a deposit
| and returns a pending record plus the gateway checkout payload; GET
| /deposits/{id} returns one of the caller's own deposits (by id, uuid or
| reference number) scoped to the authenticated user; GET /deposits lists the
| caller's deposit history and GET /deposits/methods enumerates the available
| methods.
|
*/
Route::prefix('v1/deposits')
    ->name('api.v1.deposits.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/methods', [DepositController::class, 'methods'])->name('methods');
        Route::get('/', [DepositController::class, 'index'])->name('index');
        Route::post('/', [DepositController::class, 'store'])->middleware('throttle:deposit')->name('store');
        Route::get('/{deposit}', [DepositController::class, 'show'])->name('show');
    });

/*
|--------------------------------------------------------------------------
| Payment webhooks (added for the payment-gateway surface)
|--------------------------------------------------------------------------
|
| The one public inbound surface: a gateway POSTs a signed notification to
| /api/v1/payments/webhook/{gateway}. Signature verification is the
| controller's first act, so an unsigned request is refused before any
| payload is parsed or any database state is read. The gateway name in the
| URL chooses the driver, which is what keeps every provider's verification
| scheme in one place.
|
*/
/*
|--------------------------------------------------------------------------
| Payment intent surface (batch-12)
|--------------------------------------------------------------------------
|
| Authenticated intent endpoints: the user is the authenticated principal
| and the wallet is DERIVED from that principal (never accepted from the
| body as authority). Every money claim is re-proven by the service under
| a row lock.
|
*/
Route::prefix('v1/payments')
    ->name('api.v1.payments.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/providers', [PaymentController::class, 'providers'])->name('providers');
        Route::get('/methods', [PaymentController::class, 'methods'])->name('methods');
        Route::post('/intents', [PaymentController::class, 'createIntent'])->name('intents.create');
        Route::get('/intents/{intent}', [PaymentController::class, 'showIntent'])->name('intents.show');
        Route::post('/intents/{intent}/cancel', [PaymentController::class, 'cancelIntent'])->name('intents.cancel');
    });

Route::prefix('v1/payments/webhook')
    ->name('api.v1.payments.webhook.')
    ->middleware(['throttle:webhook'])
    ->group(function (): void {
        Route::post('/{gateway}', [PaymentWebhookController::class, 'handle'])->name('handle');
        // Hardened envelope lane (batch-12): signature verification
        // BEFORE persistence, exactly-once by fingerprint, async apply.
        Route::post('/v2/{gateway}', [PaymentWebhookController::class, 'receive'])->name('receive');
    });

/*
|--------------------------------------------------------------------------
| Shared ticket bearer view (public)
|--------------------------------------------------------------------------
|
| The one deliberately public read besides login: anyone holding a 256-bit
| share token may view the ticket's coarse summary. Possession of the token
| IS the authorization, so there is no auth middleware; the token constraint
| (43 base64url characters) refuses malformed input before the query builder,
| and dead/revoked/unknown tokens all return the same 404.
|
*/
Route::prefix('v1/tickets/shared')
    ->name('api.v1.tickets.shared.')
    ->middleware(['throttle:api'])
    ->group(function (): void {
        Route::get('/{token}', [TicketVerificationController::class, 'shared'])
            ->where('token', '[A-Za-z0-9_-]{43}')
            ->name('show');
    });

/*
|--------------------------------------------------------------------------
| Withdrawals (added for the withdrawal-disbursement surface)
|--------------------------------------------------------------------------
|
| The player-facing withdrawal endpoints behind the same authenticated stack:
| POST /withdrawals requests a withdrawal (immediately reserving the funds in
| the wallet's locked balance), GET /withdrawals lists the caller's history and
| GET /withdrawals/{id} returns one of the caller's own withdrawals by id,
| uuid or reference number, scoped to the authenticated user.
|
*/
Route::prefix('v1/withdrawals')
    ->name('api.v1.withdrawals.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [WithdrawalController::class, 'index'])->name('index');
        Route::post('/', [WithdrawalController::class, 'store'])->middleware('throttle:withdrawal')->name('store');
        Route::get('/{withdrawal}', [WithdrawalController::class, 'show'])->name('show');
        Route::post('/{withdrawal}/cancel', [WithdrawalController::class, 'cancel'])->name('cancel');
    });

/*
|--------------------------------------------------------------------------
| Payouts (Batch-7 API surface)
|--------------------------------------------------------------------------
|
| Player + operator surface over payout obligations. The PayoutPolicy
| owns admittance per payout; the PayoutResource owns sanitization. The
| cancellation route is a PETITION, never a status mutation — obligations
| are financial-court territory, the most a player may do through HTTP is
| state that they no longer want the obligation discharged.
|
*/
Route::prefix('v1/payouts')
    ->name('api.v1.payouts.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [PayoutController::class, 'index'])->name('index');
        Route::get('/{payout}', [PayoutController::class, 'show'])->name('show');
        Route::post('/{payout}/cancel', [PayoutController::class, 'cancel'])->name('cancel');
    });

/*
|--------------------------------------------------------------------------
| Prize claims (Batch-7)
|--------------------------------------------------------------------------
|
| Player claim surface: submit a claim against a won bet (owner-scoped,
| idempotent against the (bet, claimant, method) identity the service
| derives), inspect one claim, or page through the caller's claim history.
| All eligibility and duplicate rules live inside PrizeClaimService; the
| endpoints never duplicate them.
|
*/
Route::prefix('v1/prize-claims')
    ->name('api.v1.prize-claims.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [PrizeClaimController::class, 'index'])->name('index');
        Route::post('/', [PrizeClaimController::class, 'store'])->middleware('throttle:withdrawal')->name('store');
        Route::get('/{bet}', [PrizeClaimController::class, 'show'])->name('show');
    });

/*
|--------------------------------------------------------------------------
| Ticket product catalogue (Batch-7)
|--------------------------------------------------------------------------
|
| Public consumers see ACTIVE products only; operators may widen to the
| full lifecycle with the lane parameter (demoted for everyone else).
|
*/
Route::prefix('v1/ticket-products')
    ->name('api.v1.ticket-products.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [TicketProductController::class, 'index'])->name('index');
        Route::get('/{product}', [TicketProductController::class, 'show'])->name('show');
    });

/*
|--------------------------------------------------------------------------
| Identity-bound ticket ownership (Batch-7)
|--------------------------------------------------------------------------
|
| Owner-scoped read side only: the caller's OWN bound tickets and one
| ticket's binding stamp, resolved through the ownership lane itself.
| Bindings/locks/claims mutate elsewhere (purchase lanes, claim lanes,
| operator console) — this surface never moves ownership.
|
*/
Route::prefix('v1/ticket-ownership')
    ->name('api.v1.ticket-ownership.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [TicketOwnershipController::class, 'index'])->name('index');
        Route::get('/{ticket}', [TicketOwnershipController::class, 'show'])->name('show');
    });

/*
|--------------------------------------------------------------------------
| Draw results (Batch-7)
|--------------------------------------------------------------------------
|
| The controlled result pipeline surface: public read of a published
| result (operators may read the authorized shape), operator staging
| (MAKER, never publishes), second-pair confirmation (CHECKER), and the
| final publication gesture, which re-derives its numbers from the
| CONFIRMED ingestion card — the HTTP layer can never offer publication
| numbers the checkers never saw.
|
*/
Route::prefix('v1/draws/{draw}/result')
    ->name('api.v1.draws.result.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::get('/', [DrawResultController::class, 'show'])->name('show');
        Route::post('/ingest', [DrawResultController::class, 'storeIngest'])->name('ingest');
        Route::post('/confirm', [DrawResultController::class, 'storeConfirmation'])->name('confirm');
        Route::post('/publish', [DrawResultController::class, 'storePublication'])->name('publish');
    });

/*
|--------------------------------------------------------------------------
| Admin operations (Batch-17)
|--------------------------------------------------------------------------
|
| The admin command surface: evidence-backed operations lifecycle,
| provider operational seats, read-only cross-lane reports with
| checksum-sealed exports, and the audit query view. Admin auth floor
| here matches the desk gate: sanctum + active; capability floors are
| asserted inside the controller (four-eyes lanes are workflow, not
| middleware).
|
*/
Route::prefix('v1/admin')
    ->name('api.v1.admin.')
    ->middleware(['auth:sanctum', 'active', 'throttle:api'])
    ->group(function (): void {
        Route::post('/operations', [OperationsController::class, 'proposeOperation'])->name('operations.propose');
        Route::get('/operations', [OperationsController::class, 'listOperations'])->name('operations.index');
        Route::get('/operations/{fingerprint}', [OperationsController::class, 'showOperation'])->name('operations.show');
        Route::post('/operations/{fingerprint}/approve', [OperationsController::class, 'approveOperation'])->name('operations.approve');
        Route::put('/providers/{provider}/state', [OperationsController::class, 'seatProviderState'])->name('providers.state');
        Route::get('/providers/health', [OperationsController::class, 'providerHealth'])->name('providers.health');
        Route::post('/reports', [OperationsController::class, 'askReport'])->name('reports.ask');
        Route::get('/reports/{queryFingerprint}/export', [OperationsController::class, 'exportReport'])->name('reports.export');
        Route::get('/audit-logs', [OperationsController::class, 'auditQuery'])->name('audit.query');
    });
