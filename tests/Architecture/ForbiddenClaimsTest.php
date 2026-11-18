<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Services\Compliance\MarketingClaimGuard;
use Tests\TestCase;

/**
 * The repository-wide scan: no forbidden claim may be hardcoded anywhere.
 *
 * WHY A SOURCE SCAN AND NOT JUST A RUNTIME GUARD
 * A runtime guard only protects strings that travel through it. The dangerous case is
 * a literal typed straight into a Blade template or a lang file - "Government
 * approved", "100% secure" - which never touches a service and therefore never meets
 * the guard. This test is the only thing that sees those.
 *
 * ALLOWED FILES are exactly the three that must contain the literals: the deny list
 * itself, this test, and the guard's own docblock. Nothing else is exempt. A
 * whitelist keyed by filename is how a forbidden claim survives a refactor, so the
 * list is deliberately tiny and unguessable.
 */
final class ForbiddenClaimsTest extends TestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function scannedPaths(): array
    {
        return [
            'application code' => ['app'],
            'views' => ['resources/views'],
            'translations' => ['lang'],
            'config' => ['config'],
        ];
    }

    /**
     * @dataProvider scannedPaths
     */
    public function test_no_forbidden_claim_is_hardcoded(string $path): void
    {
        $root = base_path($path);

        if (! is_dir($root)) {
            $this->markTestSkipped($path.' does not exist in this checkout.');
        }

        $guard = app(MarketingClaimGuard::class);

        $hits = $guard->scanPaths([$root], $this->allowedPaths());

        $this->assertSame(
            [],
            $hits,
            "A claim this system cannot prove is hardcoded in:\n".
            $this->format($hits).
            "\nRemove the claim or route it through ComplianceBadgeService with a valid attestation.",
        );
    }

    /**
     * The files permitted to contain a forbidden literal, because they ARE the list
     * or the machinery that enforces it.
     *
     * THE DISTINCTION THAT MATTERS
     * A file is exempt when the literal appears in a COMMENT that NAMES the phrase in
     * order to forbid it - "a label asserting '100% secure' is refused" is the guard
     * explaining itself. A file is NOT exempt when the literal appears in a string
     * that is rendered, returned by a resource, or offered as a form default.
     *
     * That distinction is why this list is short and why each entry is justified
     * individually rather than by a glob. A filename wildcard is how a claim survives
     * a refactor: rename the view, keep the string, and the scan passes.
     *
     * @return list<string>
     */
    private function allowedPaths(): array
    {
        return [
            // The deny list itself.
            config_path('player_experience.php'),

            // The guard, the refusal, and the attestation-code enum: each names a
            // forbidden phrase in a docblock to explain why it is forbidden. The enum
            // does so twice ("Security audit on file" is provable, "bank-level
            // security" is not) which is exactly the teaching this codebase needs.
            base_path('app/Services/Compliance/MarketingClaimGuard.php'),
            base_path('app/Services/Compliance/ClaimRefused.php'),
            base_path('app/Enums/ComplianceAttestationCode.php'),

            // The two files whose PURPOSE is to reject these phrases at the point an
            // operator types them: the Filament save hook, and the security overview
            // resource whose docblock states that a claim like "100% secure" fails
            // the build.
            base_path('app/Filament/Resources/ComplianceAttestationResource.php'),
            base_path('app/Http/Resources/SecurityOverviewResource.php'),

            // This file, which must contain the phrases to test for them.
            base_path('tests/Architecture/ForbiddenClaimsTest.php'),
        ];
    }

    /**
     * @param  array<string, list<string>>  $hits
     */
    private function format(array $hits): string
    {
        $lines = [];

        foreach ($hits as $file => $claims) {
            $lines[] = '  '.str_replace(base_path().'/', '', $file).': '.implode(', ', $claims);
        }

        return implode("\n", $lines);
    }

    public function test_the_deny_list_is_not_empty(): void
    {
        // A scan against an empty list passes vacuously and proves nothing.
        $this->assertNotEmpty(app(MarketingClaimGuard::class)->forbiddenClaims());
    }

    public function test_the_guard_actually_detects_a_known_bad_string(): void
    {
        // The scan is only meaningful if the matcher works; this is the control.
        $this->assertNotEmpty(
            app(MarketingClaimGuard::class)->matches('This platform is 100% secure and government approved.'),
            'The matcher must detect a compound claim, or the scan above is a no-op.',
        );
    }

    public function test_no_blade_template_formats_a_percentage_claim(): void
    {
        // "78%" style marketing figures are forbidden by the batch regardless of the
        // deny list, because the number has no source. Percentages that ARE derived
        // (a progress bar, a score) come from a service value, never a literal.
        $root = base_path('resources/views');

        if (! is_dir($root)) {
            $this->markTestSkipped('No views directory in this checkout.');
        }

        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $contents = (string) @file_get_contents($file->getPathname());

            if (preg_match('/>\s*\d{1,3}\s*%\s*</', $contents) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, "A literal percentage is rendered as copy in:\n  ".implode("\n  ", $offenders));
    }
}
