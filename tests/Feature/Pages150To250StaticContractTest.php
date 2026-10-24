<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static contracts for the first Pages 150–250 implementation batch.
 *
 * These checks do not claim Laravel runtime, database, browser, or deployment
 * verification. They verify that the new routes, translation maps, and
 * fail-closed operational controller remain structurally bounded.
 */
final class Pages150To250StaticContractTest extends TestCase
{
    public function test_release_operations_routes_are_named_and_admin_protected(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        self::assertIsString($routes);
        foreach ([
            "Route::get('/release-manifest'",
            "Route::get('/configuration'",
            "Route::get('/secrets'",
            "Route::get('/migrations'",
            "Route::get('/backups'",
            "Route::get('/restore-verification'",
            "Route::get('/disaster-recovery'",
            "Route::get('/high-availability'",
            "Route::get('/incidents'",
            "Route::get('/incidents/{reference}'",
            "Route::get('/deployment-approval'",
            "Route::get('/deployments'",
            "Route::get('/rollback'",
            "Route::get('/feature-flags'",
            "Route::get('/configuration-audit'",
            "Route::get('/sessions'",
            "Route::get('/access-review'",
            "Route::get('/privileged-access'",
            "Route::get('/permission-matrix'",
            "Route::get('/service-accounts'",
            "Route::get('/network-access'",
            "Route::get('/device-risk'",
            "Route::get('/mfa'",
            "Route::get('/authentication-security'",
            "Route::get('/rate-limits'",
            "Route::get('/captcha'",
            "Route::get('/risk-rules'",
            "Route::get('/suspicious-activity'",
            "Route::get('/compliance-cases'",
            "Route::get('/sanctions'",
            "Route::get('/kyc'",
            "Route::get('/kyc-provider'",
            "Route::get('/kyc-review'",
            "Route::get('/age-verification'",
            "Route::get('/duplicate-accounts'",
            "Route::get('/account-restrictions'",
            "Route::get('/retention'",
            "Route::get('/privacy'",
            "Route::get('/data-rights'",
            "Route::get('/legal-registries'",
            "Route::get('/compliance-reporting'",
            "Route::get('/aml-monitoring'",
            "Route::get('/regulatory-exports'",
            "Route::get('/compliance-audit'",
            'ReleaseOperationsController::class',
            "->middleware(['admin.auth', 'can:access-admin'])",
        ] as $contract) {
            self::assertStringContainsString($contract, $routes);
        }
    }

    public function test_release_controller_is_read_only_and_does_not_execute_shell_or_financial_mutations(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/Admin/ReleaseOperationsController.php'));

        self::assertIsString($controller);
        self::assertStringContainsString('AdminAccess::canAccessPanel', $controller);
        self::assertStringContainsString('AdminAccess::allows', $controller);
        self::assertStringContainsString('hash_file', $controller);
        self::assertStringContainsString("'NOT_VERIFIED'", $controller);
        self::assertStringContainsString("'NOT_CONFIGURED'", $controller);
        self::assertStringNotContainsString('Process::run', $controller);
        self::assertStringNotContainsString('shell_exec(', $controller);
        self::assertStringNotContainsString('exec(', $controller);
        self::assertStringNotContainsString('Artisan::call', $controller);
        self::assertStringNotContainsString('->save()', $controller);
        self::assertStringNotContainsString('->update(', $controller);
        self::assertStringNotContainsString('->delete(', $controller);
        self::assertStringNotContainsString('float', strtolower($controller));
        self::assertStringNotContainsString('double', strtolower($controller));
    }

    public function test_release_translation_maps_have_exact_key_and_placeholder_parity(): void
    {
        $english = require base_path('lang/en/admin_release.php');
        $thai = require base_path('lang/th/admin_release.php');

        self::assertSame(self::keys($english), self::keys($thai));
        self::assertSame(self::placeholders($english), self::placeholders($thai));
    }

    public function test_canonical_finance_lottery_and_rust_boundaries_are_present(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php'));
        $rust = file_get_contents(base_path('security/weekly-result-integrity/src/main.rs'));
        $rustTests = file_get_contents(base_path('security/weekly-result-integrity/tests/integrity.rs'));

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($rust);
        self::assertIsString($rustTests);
        self::assertStringContainsString("Route::get('/ledger'", $routes);
        self::assertStringContainsString("Route::get('/draw-lifecycle'", $routes);
        self::assertStringContainsString('FinancialReconciliationService', $controller);
        self::assertStringContainsString('GloResultImportService', $controller);
        self::assertStringContainsString('single JSON document from stdin', $rust);
        self::assertStringContainsString('synthetic', $rustTests);
    }

    public function test_release_audit_contains_pages_150_through_250(): void
    {
        $audit = file_get_contents(base_path('audit.md'));

        self::assertIsString($audit);
        for ($page = 150; $page <= 250; $page++) {
            self::assertMatchesRegularExpression('/\| '.$page.' \|/', $audit);
        }
        self::assertStringContainsString('NOT VERIFIED — RUNTIME UNAVAILABLE', $audit);
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function keys(array $value, string $prefix = ''): array
    {
        $keys = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys[] = $path;
            if (is_array($child)) {
                $keys = array_merge($keys, self::keys($child, $path));
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function placeholders(array $value, string $prefix = ''): array
    {
        $placeholders = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($child)) {
                $placeholders = array_merge($placeholders, self::placeholders($child, $path));
                continue;
            }

            if (is_string($child)) {
                preg_match_all('/\{[^}]+\}|:[A-Za-z_][A-Za-z0-9_]*/', $child, $matches);
                $placeholders[$path] = $matches[0];
            }
        }

        ksort($placeholders);

        return $placeholders;
    }
}
