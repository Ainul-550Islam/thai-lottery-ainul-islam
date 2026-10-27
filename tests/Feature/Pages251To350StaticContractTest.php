<?php

// TYPE: PHP static contract test
// PURPOSE: Verify Pages 251–350 runtime scripts, owner-scoped support activation, canonical references, and complete audit coverage without claiming runtime execution.

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class Pages251To350StaticContractTest extends TestCase
{
    public function test_runtime_preflight_and_acceptance_gate_are_secret_free_and_machine_readable(): void
    {
        $preflight = file_get_contents(base_path('scripts/runtime_preflight.py'));
        $gate = file_get_contents(base_path('scripts/pages_251_350_runtime_gate.py'));

        self::assertIsString($preflight);
        self::assertIsString($gate);
        self::assertStringContainsString('# TYPE:', $preflight);
        self::assertStringContainsString('# PURPOSE:', $preflight);
        self::assertStringContainsString('No secret values are read or emitted.', $preflight);
        self::assertStringContainsString('BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE', $gate);
        self::assertStringContainsString('page-350-acceptance.json', $gate);
        self::assertStringNotContainsString('DB_PASSWORD', $gate);
        self::assertStringNotContainsString('APP_KEY', $gate);
    }

    public function test_support_case_activation_is_owner_scoped_and_does_not_reuse_anonymous_contact_messages(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/Support/SupportPortalController.php'));
        $service = file_get_contents(base_path('app/Services/Support/SupportCaseService.php'));
        $migration = file_get_contents(base_path('database/migrations/2026_09_30_000900_create_support_case_tables.php'));

        self::assertIsString($routes);
        self::assertIsString($controller);
        self::assertIsString($service);
        self::assertIsString($migration);
        self::assertStringContainsString("Route::post('/support'", $routes);
        self::assertStringContainsString("Route::post('/support/{reference}/reply'", $routes);
        self::assertStringContainsString("where('reference', '[A-Za-z0-9_-]{1,120}')", $routes);
        self::assertStringContainsString('where(\'owner_user_id\'', $service);
        self::assertStringContainsString('findForOwner', $service);
        self::assertStringContainsString('SupportCase', $service);
        self::assertStringContainsString('owner_user_id', $migration);
        self::assertStringNotContainsString('ContactMessage', $controller);
        self::assertStringNotContainsString('request->input(\'user_id\'', $controller);
        self::assertStringNotContainsString('request->input(\'owner_user_id\'', $controller);
    }

    public function test_pages_251_through_350_audit_rows_and_required_reports_exist(): void
    {
        $audit = file_get_contents(base_path('audit.md'));
        $runtimeReport = file_get_contents(base_path('RUNTIME-VERIFICATION-REPORT.md'));
        $financialReport = file_get_contents(base_path('FINANCIAL-INTEGRITY-REPORT.md'));
        $rustReport = file_get_contents(base_path('RUST-RUNTIME-REPORT.md'));

        self::assertIsString($audit);
        self::assertIsString($runtimeReport);
        self::assertIsString($financialReport);
        self::assertIsString($rustReport);
        for ($page = 251; $page <= 350; $page++) {
            self::assertMatchesRegularExpression('/\| '.$page.' \|/', $audit);
        }
        self::assertStringContainsString('BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE', $audit);
        self::assertStringContainsString('NOT_CONFIGURED', $audit);
        self::assertStringContainsString('RUNTIME', $runtimeReport);
        self::assertStringContainsString('Financial integrity', $financialReport);
        self::assertStringContainsString('Cargo', $rustReport);
    }
}
