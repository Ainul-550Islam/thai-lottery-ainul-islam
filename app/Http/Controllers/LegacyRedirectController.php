<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * LEGACY .php URL COMPATIBILITY LAYER (audit findings 1, 2, 3, 4, 12-15).
 *
 * The public site this project replaces published .php URLs. Those URLs are
 * in search engine indexes, bookmarks and third-party links, so they must
 * keep resolving after the cutover. This controller is the single place
 * that translates a legacy path to its modern equivalent and answers 301
 * Moved Permanently, so crawlers transfer ranking and browsers update
 * bookmarks, instead of every old link turning into a 404.
 *
 * Rules:
 *  - Only the legacy paths listed here are translated. Anything else keeps
 *    the framework 404: this is a bridge for documented legacy URLs, not a
 *    catch-all alias that masks broken links.
 *  - Archive year pages pass the BUDDHIST-ERA year through untouched. The
 *    modern year routes normalise era input themselves (see
 *    AbstractLotteryCalendarService::normaliseYearInput), so a legacy
 *    "2568" and a modern "2025" land on the same page.
 *  - GET and legacy form POSTs both get a permanent redirect; the browser
 *    re-issues as a GET to the modern page where the current form lives.
 *  - No legacy URL is a second entry point into application logic: every
 *    target is a named route, so authorisation, throttling and everything
 *    else on that route apply exactly as on a direct visit.
 */
class LegacyRedirectController extends Controller
{
    /**
     * Static page map: legacy .php path => modern route name.
     *
     * account-verify.php / account-grade.php point at the PUBLIC explainer
     * pages, not the authenticated /account/verification and /account/grade
     * surfaces: the legacy URLs were signed-out marketing pages, and a
     * redirected visitor who is not logged in must not bounce to login.
     */
    private const STATIC_ROUTES = [
        // The bare index URL old bookmarks and search results carry.
        'index.php' => 'home',
        'about.php' => 'about',
        'vision.php' => 'vision',
        'terms.php' => 'terms',
        'fees.php' => 'fees',
        'contact.php' => 'contact',
        'national-lottery.php' => 'national-lottery.index',
        'weekly-lottery.php' => 'weekly-lottery.index',
        'bingo-lottery.php' => 'bingo-lottery.index',
        'pcso-lottery.php' => 'pcso-lottery.index',
        'account-verify.php' => 'account-verification-guide',
        'account-grade.php' => 'account-grades',
        // Both observed legacy spellings of the two account-programme
        // pages: prize-verify.php / prize-verification.php and
        // discount.php / lotto-discount.php.
        'prize-verify.php' => 'prize-verification',
        'prize-verification.php' => 'prize-verification',
        'discount.php' => 'discounts',
        'lotto-discount.php' => 'discounts',
        'login.php' => 'login',
        'register.php' => 'register',
        'forgot_password.php' => 'password.request',
    ];

    /**
     * The replaced member area shipped double-path URLs that 404'd on its
     * own server (audit findings 12-15). They are mapped to the modern
     * authenticated pages; the auth middleware then routes a signed-out
     * visitor through login with an intended URL, exactly as a direct
     * visit to the modern page would.
     */
    private const MEMBER_ROUTES = [
        // profile-password.php lands on the same profile page, which owns
        // the password form. logout.php deliberately maps to home, not to
        // the logout action: modern logout is a CSRF-protected POST, and a
        // redirected legacy GET must never be able to log anybody out.
        'members/members/profile.php' => 'player.profile',
        'members/members/profile-password.php' => 'player.profile',
        'members/members/logout.php' => 'home',
        'members/members/lottery.php' => 'player.draws',
        'members/members/lottery_history.php' => 'player.bets',
        'members/members/cash_to_win.php' => 'player.deposit',
        'members/members/win_to_cash.php' => 'player.withdraw',
    ];

    /**
     * Legacy numbered archive pages: suffix => Buddhist-era year.
     * national-lottery1.php carried 2563, ...2 carried 2562, ...3 carried 2561.
     */
    private const NUMBERED_ARCHIVE_YEARS = [
        1 => 2563,
        2 => 2562,
        3 => 2561,
    ];

    public function resolve(Request $request, string $legacyPath): RedirectResponse
    {
        $path = ltrim($legacyPath, '/');

        if (isset(self::STATIC_ROUTES[$path])) {
            return redirect()->to(route(self::STATIC_ROUTES[$path]), 301);
        }

        if (isset(self::MEMBER_ROUTES[$path])) {
            return redirect()->to(route(self::MEMBER_ROUTES[$path]), 301);
        }

        // Year archives. The replaced site misspelled "lottery" as
        // "lottoery" on its National and Weekly archive links for years
        // 2564-2568, and those misspelled URLs are the ones search engines
        // indexed, so the typo form is mapped deliberately. Bingo and PCSO
        // used the correctly spelled form for the same purpose.
        if (preg_match('#^(national|weekly)-lottoery-([0-9]{4})\.php$#', $path, $m) === 1) {
            return redirect()->to(route($m[1].'-lottery.year', ['year' => $m[2]]), 301);
        }

        if (preg_match('#^(bingo|pcso)-lottery-([0-9]{4})\.php$#', $path, $m) === 1) {
            return redirect()->to(route($m[1].'-lottery.year', ['year' => $m[2]]), 301);
        }

        if (preg_match('#^(national|weekly|bingo|pcso)-lottery([1-3])\.php$#', $path, $m) === 1) {
            return redirect()->to(
                route($m[1].'-lottery.year', ['year' => self::NUMBERED_ARCHIVE_YEARS[(int) $m[2]]]),
                301
            );
        }

        // A .php path this map does not know is still a 404.
        throw new NotFoundHttpException('Unknown legacy path ['.$path.'].');
    }
}
