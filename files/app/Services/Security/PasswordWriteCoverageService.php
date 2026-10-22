<?php

declare(strict_types=1);

namespace App\Services\Security;

use Symfony\Component\Finder\Finder;

/**
 * Where in this application is a password hash actually written?
 *
 * WHY THIS EXISTS
 * Password writes are the one mutation where a single bypassed call site undoes
 * every other control: the credential epoch, the session-generation revocation
 * and the "own your commit" boundary all hang off the handful of services that
 * are supposed to perform the write. A new controller that does
 * `$user->password = Hash::make($input)` compiles, passes review by eye, and
 * silently skips all of it.
 *
 * A concrete instance was found while writing this: `app/Http/Controllers/Web/
 * AuthController.php` holds a whole registration implementation that no route
 * maps to, which wrote the hash itself and validated the password with
 * `Password::min(8)->letters()->numbers()` instead of the centralised
 * `StrongPasswordRule` that every routed registration form uses. Unrouted today,
 * but it was one `Route::post` away from being a weak door that also ignored
 * `config('auth_security.passwords.*')`.
 *
 * WHAT IT REPORTS
 *  - every `Hash::make(` call site under app/, classified against an allowlist
 *    of the services that are meant to own password writes;
 *  - every `'password' => [...]` validation array that uses a local framework
 *    rule (`Password::…`) instead of `StrongPasswordRule` — i.e. a surface whose
 *    policy will not move when the central policy moves.
 *
 * WHAT IT DOES NOT DO
 * It is a static scan of source text. It cannot see a hash written through a
 * variable, a mutator, or a package, and it does not judge whether an allowlisted
 * service is correct — only that the write happens there.
 */
final class PasswordWriteCoverageService
{
    /**
     * The services that are allowed to write a password hash.
     *
     * @var list<string>
     */
    private const ALLOWED_WRITERS = [
        'app/Services/Auth/PasswordChangeService.php',
        'app/Services/Auth/AdminPasswordChangeService.php',
        'app/Services/Auth/PasswordResetService.php',
        'app/Services/Auth/RegistrationService.php',
    ];

    /**
     * Write sites that are known, named, and deliberately left in place.
     *
     * This is not a way to make the report look clean: each entry is a decision
     * with a reason, and the test suite asserts the list is exactly this — so a
     * new exception has to be added on purpose, in public, next to its reason.
     *
     * @var array<string, string>
     */
    private const KNOWN_EXCEPTIONS = [
        // Part 68: a complete registration implementation that nothing routes to
        // (the live door is MemberAuthController::register). Deleting it is a
        // consolidation decision that needs sign-off, so it stays — with the
        // centralised password rule, and with this entry recording why it is
        // allowed to write a hash at all.
        'app/Http/Controllers/Web/AuthController.php' => 'legacy unrouted registration body; kept pending a deletion decision, password rule centralised in Part 68',
    ];

    /**
     * The needle is assembled so this file does not match itself.
     */
    private const HASH_MAKE = 'Hash::'.'make(';

    public function __construct(
        private readonly ?string $basePath = null,
    ) {}

    /**
     * @return array{scanned_files: int, write_sites: list<array{file: string, line: int}>, covered: list<string>, uncovered: list<string>, known_exceptions: list<string>, weak_rules: list<string>}
     */
    public function report(): array
    {
        $base = $this->basePath ?? base_path();

        $scanned = 0;
        $sites = [];
        $weak = [];

        foreach ($this->phpFiles($base.'/app') as $file) {
            $scanned++;
            $relative = $this->relative($base, $file->getRealPath() ?: $file->getPathname());
            $lines = file($file->getRealPath() ?: $file->getPathname()) ?: [];

            foreach ($lines as $index => $line) {
                $number = $index + 1;
                $text = (string) $line;

                if (str_contains($this->withoutComments($text), self::HASH_MAKE)) {
                    $sites[] = ['file' => $relative, 'line' => $number];
                }

                // A validation array for `password` that leans on the framework's
                // own rule object rather than the centralised rule.
                if (preg_match("/['\"]password['\"]\s*=>\s*\[/", $text) === 1
                    && str_contains($text, 'Password::')
                    && ! str_contains($text, 'StrongPasswordRule')) {
                    $weak[] = $relative.':'.$number;
                }
            }
        }

        $covered = [];
        $uncovered = [];
        $exceptions = [];

        foreach ($sites as $site) {
            if (in_array($site['file'], self::ALLOWED_WRITERS, true)) {
                $covered[] = $site['file'].':'.$site['line'];
            } elseif (array_key_exists($site['file'], self::KNOWN_EXCEPTIONS)) {
                $exceptions[] = $site['file'].':'.$site['line'];
            } else {
                $uncovered[] = $site['file'].':'.$site['line'];
            }
        }

        return [
            'scanned_files' => $scanned,
            'write_sites' => $sites,
            'covered' => array_values(array_unique($covered)),
            'uncovered' => array_values(array_unique($uncovered)),
            'known_exceptions' => array_values(array_unique($exceptions)),
            'weak_rules' => array_values(array_unique($weak)),
        ];
    }

    /**
     * The named exceptions with their reasons.
     *
     * @return array<string, string>
     */
    public function knownExceptions(): array
    {
        return self::KNOWN_EXCEPTIONS;
    }

    /**
     * The allowlist, exposed so a test can state what "covered" means.
     *
     * @return list<string>
     */
    public function allowedWriters(): array
    {
        return self::ALLOWED_WRITERS;
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private function phpFiles(string $directory): iterable
    {
        if (! is_dir($directory)) {
            return [];
        }

        $finder = new Finder;

        return $finder->files()->in($directory)->name('*.php')->sortByName();
    }

    private function relative(string $base, string $path): string
    {
        $prefix = rtrim($base, '/').'/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /**
     * Drop `//` and `#` trailing comments so a docblock or an explanatory comment
     * that merely mentions Hash::make is not counted as a write site.
     */
    private function withoutComments(string $line): string
    {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
            return '';
        }

        $position = strpos($line, '//');

        return $position === false ? $line : substr($line, 0, $position);
    }
}
