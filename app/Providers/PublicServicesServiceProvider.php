<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\Privacy\PrivacySlaCheckCommand;
use App\Console\Commands\Privacy\PurgeExpiredPrivacyDataCommand;
use App\Models\ContactMessage;
use App\Models\DataSubjectRequest;
use App\Policies\ContactMessagePolicy;
use App\Policies\DataSubjectRequestPolicy;
use App\Services\Privacy\PersonalDataExporter;
use App\Services\Privacy\PrivacyRequestService;
use App\Services\Privacy\PrivacyRetentionGuard;
use App\Services\Support\SupportTicketService;
use App\Services\Support\TicketStatusLookupService;
use App\Services\PublicPages\LegalPdfExportService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the privacy-request and support-ticket lanes.
 *
 * =========================================================================
 * WHY THIS IS ITS OWN PROVIDER RATHER THAN MORE LINES IN AppServiceProvider
 * =========================================================================
 * Four features were built in parallel against this application. Everything
 * this lane needs at boot - its named limiters, its two policies and its own
 * route file - lives here, so the single line that turns the lane on is a line
 * somebody can find, read and remove. AppServiceProvider gains exactly one
 * statement: $this->app->register(PublicServicesServiceProvider::class).
 *
 * =========================================================================
 * WHAT IT DOES NOT DO
 * =========================================================================
 * It never writes config, never migrates, never mints a permission and never
 * toggles a feature flag. The lanes stay OFF unless the deployment sets
 * PRIVACY_REQUESTS_ENABLED / SUPPORT_TICKET_STATUS_ENABLED, and both flag checks
 * live at the endpoints, not here: a provider that skipped registering a route
 * because a config was false would turn "off" into a 404 instead of the 501 the
 * existing contract promises.
 *
 * THE ROUTES ARE SEPARATE BECAUSE routes/web.php IS NOT THIS LANE'S TO EDIT.
 * They are loaded with the `web` group (session, CSRF, locale, security
 * headers), and the account pages add their own `auth` in the file itself.
 */
final class PublicServicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The four config files live in config/ and are loaded by the framework,
        // so these merges are normally a no-op. They are here because a
        // deployment that ships an environment-specific overlay (or a cached
        // config built from one) must still see every key this lane reads -
        // a missing 'enabled' key must mean OFF, not a TypeError at the first
        // request.
        foreach (['legal_pdf', 'payout_illustration', 'privacy_requests', 'support_tickets'] as $config) {
            $path = config_path($config.'.php');

            if (is_file($path)) {
                $this->mergeConfigFrom($path, $config);
            }
        }

        // The exporter and the two request services hold no per-request state,
        // so one instance per application is both cheaper and safer: the
        // exporter's chunk size and retention windows are then read once.
        $this->app->singleton(PrivacyRequestService::class);
        $this->app->singleton(PrivacyRetentionGuard::class);
        $this->app->singleton(PersonalDataExporter::class);
        $this->app->singleton(SupportTicketService::class);
        $this->app->singleton(TicketStatusLookupService::class);

        // The legal-document renderer. One instance per application keeps the
        // mPDF font/temp paths read once, and - more importantly - means the
        // three public download endpoints and the tests that exercise them all
        // go through the same object rather than three ad-hoc constructions.
        $this->app->singleton(LegalPdfExportService::class);
    }

    public function boot(): void
    {
        $this->registerPolicies();
        $this->registerRateLimiters();
        $this->registerConsoleCommands();
        $this->registerRoutes();
    }

    /**
     * The lane's two scheduled commands, declared here rather than left to a
     * directory scan.
     *
     * bootstrap/app.php already points withCommands() at app/Console/Commands,
     * so discovery would find them - but that file belongs to another lane in
     * this build. Registering them from the provider that owns them means the
     * commands exist because this feature exists, and a deployment that copies
     * only this lane's files gets a working schedule instead of a silent
     * "command not found" in the scheduler log at 03:10.
     */
    private function registerConsoleCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            PurgeExpiredPrivacyDataCommand::class,
            PrivacySlaCheckCommand::class,
        ]);
    }

    /**
     * Two policies, registered by class rather than by discovery.
     *
     * Explicit registration means the abilities exist even before the panel's
     * resource classes are loaded, so a Blade @can or a Filament canViewAny()
     * asks the same question wherever it is called from.
     */
    private function registerPolicies(): void
    {
        Gate::policy(DataSubjectRequest::class, DataSubjectRequestPolicy::class);
        Gate::policy(ContactMessage::class, ContactMessagePolicy::class);
    }

    /**
     * Named limiters, all read from configuration.
     *
     * KEYED ON DIGESTS, NOT ON ADDRESSES. A limiter store is a cache: it gets
     * dumped, exported and read by people debugging something else. So the keys
     * are sha256 digests of the IP (and of the reference, where one is
     * involved), which are useless outside the limiter.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('privacy-submit', function (Request $request): array {
            $perHour = max(1, (int) config('privacy_requests.rate_limits.submit_per_hour', 3));

            return [
                Limit::perHour($perHour)->by('privacy:submit:hour:'.self::digest((string) $request->ip())),
            ];
        });

        RateLimiter::for('privacy-verify', function (Request $request): array {
            $perMinute = max(1, (int) config('privacy_requests.rate_limits.verify_per_minute', 6));

            return [
                Limit::perMinute($perMinute)->by('privacy:verify:minute:'.self::digest((string) $request->ip())),
            ];
        });

        RateLimiter::for('support-status-lookup', function (Request $request): array {
            $perMinute = max(1, (int) config('support_tickets.rate_limits.lookup_per_minute', 5));
            $perHour = max(1, (int) config('support_tickets.rate_limits.lookup_per_hour', 20));

            return [
                Limit::perMinute($perMinute)->by('support:lookup:minute:'.self::digest((string) $request->ip())),
                Limit::perHour($perHour)->by('support:lookup:hour:'.self::digest((string) $request->ip())),
            ];
        });

        RateLimiter::for('privacy-account', function (Request $request): array {
            // The player's own area: identified by user id, so one account
            // pressing refresh repeatedly cannot spend another person's quota.
            $perMinute = max(1, (int) config('privacy_requests.rate_limits.verify_per_minute', 6));
            $key = self::digest('user:'.(string) ($request->user()?->getKey() ?? 'guest'));

            return [Limit::perMinute($perMinute)->by('privacy:account:'.$key)];
        });
    }

    private function registerRoutes(): void
    {
        $path = base_path('routes/public_services.php');

        if (! is_file($path)) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make('router');

        Route::middleware('web')->group($path);
    }

    private static function digest(string $value): string
    {
        return substr(hash('sha256', $value), 0, 32);
    }
}
