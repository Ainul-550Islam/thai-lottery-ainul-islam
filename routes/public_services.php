<?php

declare(strict_types=1);

use App\Http\Controllers\Privacy\PrivacyRequestStatusController;
use App\Http\Controllers\Privacy\PrivacyRequestVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Privacy-request lane (PDPA data-subject requests)
|--------------------------------------------------------------------------
|
| WHY THIS FILE EXISTS RATHER THAN MORE LINES IN routes/web.php
| routes/web.php belongs to another lane in this build. Everything this feature
| needs is here, loaded by PublicServicesServiceProvider with the `web` group,
| so the whole surface can be read in one screen and removed in one file.
|
| WHAT IS DELIBERATELY NOT ROUTED
| Opening a public request, verifying it and looking up a ticket all already
| have canonical URLs in routes/api.php and routes/web.php (owned elsewhere).
| Those endpoints keep their exact 501 bodies while the lane is off; the
| controllers behind them are what this lane changed. Re-declaring the same
| paths here would create a second route to the same handler and make the
| "identical while off" promise impossible to verify.
|
| THE VERIFICATION LINK IS SIGNED. The token alone is a random string; the
| signature binds it to this deployment's key with a short expiry, so a token
| copied out of a database row cannot be opened by hand.
|
*/

Route::get('/privacy/request/verify/{token}', PrivacyRequestVerificationController::class)
    ->middleware(['signed', 'throttle:privacy-verify'])
    ->where('token', '[A-Za-z0-9]{32,128}')
    ->name('privacy.request.verify');

/*
|--------------------------------------------------------------------------
| The requester's own area
|--------------------------------------------------------------------------
|
| Every action is scoped by the session user and by the request reference; no
| route accepts a user id, an address or a path to an archive. The export link
| is signed and short-lived on top of that, because it is the one route that
| returns a file.
|
*/
Route::middleware(['auth', 'active', 'throttle:privacy-account'])->group(function (): void {
    Route::get('/account/privacy-requests', [PrivacyRequestStatusController::class, 'index'])
        ->name('account.privacy-requests.index');

    Route::post('/account/privacy-requests', [PrivacyRequestStatusController::class, 'store'])
        ->middleware('throttle:privacy-submit')
        ->name('account.privacy-requests.store');

    Route::post('/account/privacy-requests/{reference}/cancel', [PrivacyRequestStatusController::class, 'cancel'])
        ->where('reference', '[A-Za-z0-9-]{4,32}')
        ->name('account.privacy-requests.cancel');

    Route::get('/account/privacy-requests/{reference}/export/{export}', [PrivacyRequestStatusController::class, 'export'])
        ->middleware('signed')
        ->where(['reference' => '[A-Za-z0-9-]{4,32}', 'export' => '[0-9]{1,20}'])
        ->name('account.privacy-requests.export');
});
