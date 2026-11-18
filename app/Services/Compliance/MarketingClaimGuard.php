<?php

declare(strict_types=1);

namespace App\Services\Compliance;

/**
 * Scans text for claims this system cannot prove.
 *
 * WHERE IT IS USED
 *   1. ForbiddenClaimsTest — a repository-wide scan of app/, resources/views,
 *      lang/ and config/ against the same deny list, so a claim cannot be written
 *      into a Blade template and shipped.
 *   2. The Filament save hook on ComplianceAttestationResource — an operator
 *      typing an over-claiming badge label is refused at the point of typing,
 *      with the reason, instead of being published and found later.
 *
 * WHAT A MATCH MEANS
 * A match is not "this sentence is false". It is "this sentence asserts a
 * credential, an absolute or a guarantee, and the only path allowed to assert
 * those is ComplianceBadgeService with a valid attestation behind it". So the
 * guard is deliberately about SHAPE, not truth: it cannot know whether a licence
 * exists, but it can know that a string claiming one has no business being
 * hardcoded in a view.
 *
 * THE ALLOWANCE
 * Text rendered FROM a ComplianceBadgeService payload is exempt, and that
 * exemption is expressed by scanning filenames and by the service being the only
 * producer of those strings. Nothing here whitelists a literal: a whitelist of
 * literals is how a forbidden claim survives a refactor.
 */
final class MarketingClaimGuard
{
    /**
     * The configured deny list.
     *
     * @return list<string>
     */
    public function forbiddenClaims(): array
    {
        /** @var list<string> $claims */
        $claims = array_values(array_map(
            static fn ($c): string => (string) $c,
            (array) config('player_experience.compliance.forbidden_claims', []),
        ));

        return array_values(array_filter($claims, static fn (string $c): bool => trim($c) !== ''));
    }

    /**
     * Every forbidden claim present in the text.
     *
     * Case-insensitive, and matched on the plain string (not a word boundary) so
     * "100% secure!" and "100% secure." both match — punctuation is exactly how
     * such a claim gets past a naive scanner.
     *
     * @return list<string>
     */
    public function matches(string $text): array
    {
        $haystack = mb_strtolower($text);
        $found = [];

        foreach ($this->forbiddenClaims() as $claim) {
            $needle = mb_strtolower($claim);
            $offset = 0;

            while (($position = mb_strpos($haystack, $needle, $offset)) !== false) {
                /*
                 * A DISCLAIMER IS THE OPPOSITE OF A CLAIM.
                 *
                 * "No guaranteed winnings", "you cannot lose nothing", "never risk
                 * free" - the responsible-gaming copy this platform is REQUIRED to
                 * show contains the exact phrases a marketing claim would use. A
                 * scanner that cannot tell them apart punishes the operator for
                 * writing the disclaimer and is, in practice, turned off within a
                 * week.
                 *
                 * So a match preceded (within a short window) by a negation is not a
                 * match. The window is measured in CHARACTERS, not words, because
                 * "no " before "guaranteed win" is the whole signal and a longer
                 * reach would start swallowing genuine claims that merely follow a
                 * negative sentence.
                 */
                if (! $this->isNegated($haystack, $position)) {
                    $found[] = $claim;

                    break;
                }

                $offset = $position + mb_strlen($needle);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Whether the match at $position is introduced by a negation.
     *
     * Examined: the characters immediately before the match, back to the nearest
     * sentence break. Anything further away belongs to a different clause.
     */
    private function isNegated(string $haystack, int $position): bool
    {
        $windowStart = max(0, $position - 24);
        $before = mb_substr($haystack, $windowStart, $position - $windowStart);

        // Only the current clause counts.
        $clauseBreak = max(
            (int) mb_strrpos($before, '.'),
            (int) mb_strrpos($before, ';'),
            (int) mb_strrpos($before, ':'),
            (int) mb_strrpos($before, '\n'),
        );

        if ($clauseBreak > 0) {
            $before = mb_substr($before, $clauseBreak + 1);
        }

        return preg_match('/\b(no|not|never|cannot|without|zero|nor|neither)\b/i', $before) === 1;
    }

    public function isClean(string $text): bool
    {
        return $this->matches($text) === [];
    }

    /**
     * Assert, for a writer, that a string may be stored/published.
     *
     * Used by the Filament hook. The thrown refusal carries every offending
     * phrase so the operator can fix it in one edit.
     *
     * @throws ClaimRefused
     */
    public function assertPublishable(string $text, string $field = 'text'): void
    {
        $matches = $this->matches($text);

        if ($matches !== []) {
            throw new ClaimRefused($field, $matches);
        }
    }

    /**
     * Scan a directory tree for PHP/Blade/lang/config files containing a
     * forbidden claim.
     *
     * Read-only, used by the architecture test. Returns path => list of matches.
     * `$allowPaths` are files permitted to contain the literal (the deny list in
     * config, the test itself, and this class) — no other file is exempt.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $allowPaths
     * @return array<string, list<string>>
     */
    public function scanPaths(array $paths, array $allowPaths = []): array
    {
        $hits = [];

        foreach ($paths as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();

                if ($this->isAllowed($path, $allowPaths)) {
                    continue;
                }

                if (! preg_match('/\.(php|blade\.php|json|ya?ml|js|mjs|md)$/i', $path) && ! str_ends_with($path, '.blade.php')) {
                    continue;
                }

                $contents = @file_get_contents($path);

                if (! is_string($contents) || $contents === '') {
                    continue;
                }

                $matches = $this->matches($contents);

                if ($matches !== []) {
                    $hits[$path] = $matches;
                }
            }
        }

        ksort($hits);

        return $hits;
    }

    /**
     * @param  list<string>  $allowPaths
     */
    private function isAllowed(string $path, array $allowPaths): bool
    {
        $normalised = str_replace('\\', '/', $path);

        foreach ($allowPaths as $allowed) {
            $needle = str_replace('\\', '/', $allowed);

            if ($needle !== '' && str_contains($normalised, $needle)) {
                return true;
            }
        }

        return false;
    }
}
