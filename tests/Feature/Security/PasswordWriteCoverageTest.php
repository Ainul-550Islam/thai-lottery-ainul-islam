<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Services\Security\PasswordWriteCoverageService;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Part 68 — password-hash writes stay inside the services that own them.
 *
 * WHAT WAS FOUND
 * `app/Http/Controllers/Web/AuthController.php` contains a full registration
 * implementation that no route maps to. It wrote the hash itself and validated
 * with `Password::min(8)->letters()->numbers()`, while every routed registration
 * form uses the centralised `StrongPasswordRule` (which reads
 * `config('auth_security.passwords.*')` and applies a common-password denylist).
 * So an operator tightening the central policy would have tightened every door
 * except this one — and this one was a single `Route::post` away from being
 * reachable with no throttle, honeypot, bot or CAPTCHA middleware.
 *
 * WHAT IS PROVEN
 *  1. Every `Hash::make(` call site under app/ is one of the four services that
 *     are meant to own password writes — nothing else writes a hash.
 *  2. No `password` validation array in app/ leans on a local framework rule
 *     instead of `StrongPasswordRule`.
 *  3. The detector really detects: a synthetic file with both defects is
 *     reported. Without this, assertions 1 and 2 would also hold for a service
 *     that scans nothing.
 *  4. The legacy controller is still unrouted, and the live `/register` route is
 *     MemberAuthController behind throttle/ip-access/honeypot/bot.
 */
final class PasswordWriteCoverageTest extends TestCase
{
    #[Test]
    public function every_password_hash_write_is_inside_an_allowed_service(): void
    {
        $report = app(PasswordWriteCoverageService::class)->report();

        $this->assertGreaterThan(0, $report['scanned_files'], 'nothing was scanned');
        $this->assertNotEmpty($report['write_sites'], 'no Hash::make call sites were found at all');
        $this->assertSame(
            [],
            $report['uncovered'],
            'password hash written outside the owning services: '.implode(', ', $report['uncovered']),
        );
        $this->assertNotEmpty($report['covered']);

        // No legacy exception may quietly reintroduce an unowned hash writer.
        $this->assertSame([], app(PasswordWriteCoverageService::class)->knownExceptions());
        $this->assertSame([], $report['known_exceptions']);
    }

    #[Test]
    public function no_registration_surface_uses_a_weaker_local_password_rule(): void
    {
        $report = app(PasswordWriteCoverageService::class)->report();

        $this->assertSame(
            [],
            $report['weak_rules'],
            'password validation using a local framework rule: '.implode(', ', $report['weak_rules']),
        );

        // The legacy controller is the case that was fixed; pin it directly so a
        // revert is visible without reading the scan output.
        $source = (string) file_get_contents(
            base_path('app/Http/Controllers/Web/AuthController.php'),
        );

        $this->assertStringContainsString('abort(404)', $source);
        $this->assertStringNotContainsString('Hash::make(', $source);

        // The closed legacy method must not retain a weaker validation array.
        $this->assertSame(
            0,
            preg_match("/['\"]password['\"]\\s*=>\\s*\\[[^\\]]*Password::/", $source),
            'the registration validation array still uses a local framework password rule',
        );
    }

    #[Test]
    public function the_detector_reports_a_write_outside_the_allowlist_and_a_weak_rule(): void
    {
        $tmp = sys_get_temp_dir().'/password-coverage-'.bin2hex(random_bytes(4));
        mkdir($tmp.'/app/Http/Controllers', 0o777, true);

        file_put_contents(
            $tmp.'/app/Http/Controllers/RogueController.php',
            <<<'PHP'
            <?php

            namespace App\Http\Controllers;

            class RogueController
            {
                public function store($request)
                {
                    $validated = $request->validate([
                        'password' => ['required', \Illuminate\Validation\Rules\Password::min(8)],
                    ]);

                    $user = new \App\Models\User;
                    $user->password = \Illuminate\Support\Facades\Hash::make($validated['password']);
                    $user->save();
                }
            }
            PHP,
        );

        $report = (new PasswordWriteCoverageService($tmp))->report();

        $this->assertSame(1, $report['scanned_files']);
        $this->assertCount(1, $report['write_sites']);
        $this->assertSame(
            ['app/Http/Controllers/RogueController.php:14'],
            $report['uncovered'],
        );
        $this->assertSame(
            ['app/Http/Controllers/RogueController.php:10'],
            $report['weak_rules'],
        );

        @unlink($tmp.'/app/Http/Controllers/RogueController.php');
        @rmdir($tmp.'/app/Http/Controllers');
        @rmdir($tmp.'/app/Http');
        @rmdir($tmp.'/app');
        @rmdir($tmp);
    }

    #[Test]
    public function the_legacy_controller_is_still_unrouted_and_the_live_door_is_guarded(): void
    {
        $legacy = [];
        $register = null;

        foreach (Route::getRoutes() as $route) {
            $action = (string) ($route->getAction('controller') ?? '');

            if (str_contains($action, 'Web\\AuthController')) {
                $legacy[] = $route->uri().' => '.$action;
            }

            if ($route->uri() === 'register' && in_array('POST', $route->methods(), true)) {
                $register = $route;
            }
        }

        $this->assertSame(
            [],
            $legacy,
            'Web\AuthController became routable: '.implode(', ', $legacy)
                .' — migrate it to RegistrationService and add throttle/honeypot/bot first',
        );

        $this->assertNotNull($register, 'no POST /register route was found');
        $this->assertStringContainsString('MemberAuthController@register', (string) $register->getAction('controller'));

        $middleware = $register->gatherMiddleware();
        foreach (['throttle:register', 'ip-access', 'honeypot:register', 'bot'] as $expected) {
            $this->assertContains($expected, $middleware, 'POST /register lost '.$expected);
        }
    }
}
