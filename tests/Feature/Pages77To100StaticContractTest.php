<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static contract checks for the Pages 77–100 hardening pass.
 *
 * These assertions deliberately do not require a database. Runtime, route
 * dispatch, Blade compilation, browser, and provider gates remain separate
 * and are reported as unavailable when the Laravel runtime is unavailable.
 */
final class Pages77To100StaticContractTest extends TestCase
{
    public function test_admin_route_group_has_authentication_and_admin_gate(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        self::assertIsString($routes);
        self::assertStringContainsString("Route::prefix('admin')->name('admin.')->middleware(['admin.auth', 'can:access-admin'])->group", $routes);
        self::assertStringContainsString("Route::post('/api/reconciliation'", $routes);
        self::assertStringContainsString("LottoFinExecutiveDashboardController::class, 'unsupportedMutation'", $routes);
        self::assertStringContainsString("/kyc/{documentToken}/download", $routes);
        self::assertStringNotContainsString("/kyc/{id}/download", $routes);
    }

    public function test_requested_public_payment_and_admin_route_contracts_are_present(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        self::assertIsString($routes);
        foreach ([
            "Route::get('/results'",
            "Route::get('/check'",
            "Route::get('/sales-points'",
            "Route::get('/privacy'",
            "Route::get('/contact'",
            "Route::get('/download'",
            "Route::get('/download-app'",
            "Route::get('/app'",
            "Route::get('/account-grades'",
            "Route::get('/account-grade'",
            "Route::get('/account-verification'",
            "Route::get('/account-verification-guide'",
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }

        self::assertStringContainsString("Route::middleware('auth')->group(function (): void {", $routes);
        self::assertStringContainsString("\$callbackPath('success_url', '/payment/success')", $routes);
        self::assertStringContainsString("\$callbackPath('failure_url', '/payment/failure')", $routes);
        self::assertStringContainsString("\$callbackPath('cancel_url', '/payment/cancel')", $routes);
        self::assertStringContainsString("\$callbackPath('pending_url', '/payment/pending')", $routes);
    }

    public function test_pages_100_through_150_route_contracts_are_present_and_private_surfaces_are_bounded(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        self::assertIsString($routes);
        foreach ([
            "Route::get('/glo/prize-claims'",
            "Route::get('/glo/prize-claims/{claim}'",
            "Route::get('/glo/ticket-freezes'",
            "Route::get('/glo/ticket-freezes/{token}'",
            "Route::get('/glo/settlements'",
            "Route::get('/wallet-operations'",
            "Route::get('/payment-methods'",
            "Route::get('/withdrawal-methods'",
            "Route::get('/payment-events'",
            "Route::get('/payments/events'",
            "Route::get('/payment-exceptions'",
            "Route::get('/payments/exceptions'",
            "Route::get('/payments/{payment}'",
            "Route::get('/draw-lifecycle'",
            "Route::get('/result-publication'",
            "Route::get('/result-imports'",
            "Route::get('/result-sources'",
            "Route::get('/lotteries'",
            "Route::get('/lottery-rules'",
            "Route::get('/fees'",
            "Route::get('/account-grades'",
            "Route::get('/account-verification'",
            "Route::get('/responsible-gaming'",
            "Route::get('/self-exclusion'",
            "Route::get('/users'",
            "Route::get('/users/{user}'",
            "Route::get('/users/{user}/finance'",
            "Route::get('/bets/{bet}'",
            "Route::get('/tickets/{ticket}'",
            "Route::get('/ticket-verification'",
            "Route::get('/prize-claim-review'",
            "Route::get('/commissions'",
            "Route::get('/queues'",
            "Route::get('/scheduler'",
            "Route::get('/runtime'",
            "Route::get('/api-status'",
            "Route::get('/webhooks'",
            "Route::get('/security'",
            "Route::get('/release'",
            "Route::get('/cutover'",
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }

        self::assertStringContainsString("Route::get('/support'", $routes);
        self::assertStringContainsString("Route::get('/support/{reference}'", $routes);
        self::assertStringContainsString("Route::get('/notifications'", $routes);
        self::assertStringContainsString("Route::prefix('agent')->name('agent.')->middleware('auth')->group", $routes);
        self::assertStringContainsString('SupportPortalController::class', $routes);
        self::assertStringContainsString('AgentPortalController::class', $routes);
        self::assertStringContainsString('NotificationCenterController::class', $routes);
    }

    public function test_results_controller_and_admin_dashboard_contain_no_known_fixture_values(): void
    {
        $results = file_get_contents(base_path('app/Http/Controllers/GloResultsPageController.php'));
        $dashboard = file_get_contents(base_path('app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php'));
        $view = file_get_contents(base_path('resources/views/admin/dashboard.blade.php'));

        self::assertIsString($results);
        self::assertIsString($dashboard);
        self::assertIsString($view);

        foreach (['724605', '482963', '7419', '9361', '5824', '52938', '1420500', '348200', '4821'] as $fixture) {
            self::assertStringNotContainsString($fixture, $results.$dashboard.$view);
        }
        self::assertStringNotContainsString('number_format((float)', $dashboard);
    }

    public function test_payment_browser_return_lane_is_read_only_and_reference_bounded(): void
    {
        $service = file_get_contents(base_path('app/Services/Payment/PaymentCallbackService.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/Web/PaymentCallbackController.php'));

        self::assertIsString($service);
        self::assertIsString($controller);
        self::assertStringContainsString('browserReturnProjection', $service);
        self::assertStringContainsString('safeReference', $service);
        self::assertStringContainsString('viewerId: $request->user()?->id', $controller);
        self::assertStringNotContainsString('->save()', substr($controller, strpos($controller, 'private function render')));
    }

    public function test_results_and_admin_translation_maps_have_exact_recursive_key_parity(): void
    {
        foreach (['results', 'admin', 'public_pages', 'account_info', 'agent', 'support', 'notifications'] as $file) {
            $english = require base_path('lang/en/'.$file.'.php');
            $thai = require base_path('lang/th/'.$file.'.php');

            self::assertSame(self::keys($english), self::keys($thai), $file.' translation key parity failed.');
        }
    }

    public function test_audit_matrix_has_one_row_for_each_page_77_through_100(): void
    {
        $audit = file_get_contents(base_path('audit.md'));

        self::assertIsString($audit);
        for ($page = 77; $page <= 100; $page++) {
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
}
