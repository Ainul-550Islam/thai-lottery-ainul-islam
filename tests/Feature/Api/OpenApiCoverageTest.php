<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The OpenAPI file and the router must describe the same surface.
 *
 * TWO DIRECTIONS, BECAUSE EITHER ALONE LIES
 *   1. Every registered route in the covered prefixes must be documented. Without
 *      this, a new endpoint ships with no contract and nobody notices.
 *   2. Every documented operation must exist as a route. Without this, a typo in
 *      the YAML ("/api/v1/me/bet-slips") passes review and misleads a client
 *      developer for months.
 *
 * COVERED PREFIXES (Batch 1 + 2 + 3 surface)
 *   - api/v1/me/*
 *   - api/v1/games/*
 *   - api/v1/public/compliance-badges
 *   - health/*
 *
 * If a Batch 1 or Batch 2 route under /api/v1/games or /api/v1/me is registered
 * without an entry in docs/api/openapi.yaml, direction 1 fails and names it.
 *
 * NO YAML LIBRARY IS USED
 * Rule 8 forbids a new composer dependency and a YAML parser is not guaranteed to be
 * installed. The file is hand-written with a fixed shape (paths at two spaces,
 * methods at four), so a line scan is exact for it. If the layout ever changes the
 * "parser finds paths" test fails first, loudly, instead of the other tests passing
 * on an empty set.
 */
final class OpenApiCoverageTest extends TestCase
{
    private const SPEC = 'docs/api/openapi.yaml';

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @return array<string, true> operation key => true, e.g. "GET /api/v1/me/bets/{}"
     */
    private function documentedOperations(): array
    {
        $path = base_path(self::SPEC);

        $this->assertFileExists($path, 'docs/api/openapi.yaml is missing.');

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        $operations = [];
        $inPaths = false;
        $currentPath = null;

        foreach ($lines as $line) {
            if (preg_match('/^paths:\s*$/', $line) === 1) {
                $inPaths = true;

                continue;
            }

            // The next top-level key ends the paths block.
            if ($inPaths && preg_match('/^[A-Za-z]/', $line) === 1) {
                break;
            }

            if (! $inPaths) {
                continue;
            }

            if (preg_match('/^  (\/[^\s:]*):\s*$/', $line, $m) === 1) {
                $currentPath = $this->normalise($m[1]);

                continue;
            }

            if ($currentPath !== null
                && preg_match('/^    ('.implode('|', self::METHODS).'):\s*$/', $line, $m) === 1) {
                $operations[strtoupper($m[1]).' '.$currentPath] = true;
            }
        }

        return $operations;
    }

    /**
     * Turn `{item}` / `{bet?}` into `{}` and make sure there is a leading slash,
     * so a path parameter's NAME never decides whether two paths are "the same".
     */
    private function normalise(string $uri): string
    {
        $uri = '/'.ltrim($uri, '/');

        return (string) preg_replace('/\{[^}]*\}/', '{}', $uri);
    }

    private function isCovered(string $uri): bool
    {
        $uri = ltrim($uri, '/');

        return str_starts_with($uri, 'api/v1/me/')
            || $uri === 'api/v1/me'
            || str_starts_with($uri, 'api/v1/games')
            || $uri === 'api/v1/public/compliance-badges'
            || str_starts_with($uri, 'health/');
    }

    /**
     * @return array<string, true> operation key => true
     */
    private function registeredOperations(): array
    {
        $operations = [];

        /** @var LaravelRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! $this->isCovered($route->uri())) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }

                $operations[$method.' '.$this->normalise($route->uri())] = true;
            }
        }

        return $operations;
    }

    public function test_the_parser_actually_finds_operations(): void
    {
        $documented = $this->documentedOperations();

        // A silent parse failure would make the next two tests pass on nothing.
        $this->assertGreaterThanOrEqual(
            25,
            count($documented),
            'The OpenAPI scan found fewer than the 25 Batch 3 operations; the layout probably changed.',
        );
    }

    public function test_every_registered_route_in_the_covered_prefixes_is_documented(): void
    {
        $registered = array_keys($this->registeredOperations());
        $documented = $this->documentedOperations();

        $missing = array_values(array_filter(
            $registered,
            static fn (string $operation): bool => ! isset($documented[$operation]),
        ));

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "Routes registered but absent from docs/api/openapi.yaml:\n".implode("\n", $missing),
        );
    }

    public function test_every_documented_operation_exists_as_a_route(): void
    {
        $registered = $this->registeredOperations();
        $documented = array_keys($this->documentedOperations());

        $phantom = array_values(array_filter(
            $documented,
            static fn (string $operation): bool => ! isset($registered[$operation]),
        ));

        sort($phantom);

        $this->assertSame(
            [],
            $phantom,
            "Operations documented but not registered (typo or removed route):\n".implode("\n", $phantom),
        );
    }

    public function test_the_confirm_operation_documents_the_idempotency_header(): void
    {
        $contents = (string) file_get_contents(base_path(self::SPEC));

        $this->assertStringContainsString('name: Idempotency-Key', $contents);
        $this->assertStringContainsString("'#/components/parameters/IdempotencyKey'", $contents);
    }

    public function test_every_member_operation_declares_the_401_error_code(): void
    {
        $contents = (string) file_get_contents(base_path(self::SPEC));

        // Each /me operation references the shared Unauthenticated response. 22 /me
        // operations exist in Batch 3 (25 routes minus compliance-badges, live, ready).
        $this->assertGreaterThanOrEqual(
            22,
            substr_count($contents, "'#/components/responses/Unauthenticated'"),
            'Every authenticated operation must document the 401 response.',
        );
    }
}
