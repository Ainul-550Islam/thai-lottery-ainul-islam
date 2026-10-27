<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Antimalware\AntimalwareManager;
use App\Services\Antimalware\ScannerInterface;
use App\Services\Compliance\AmlScreeningService;
use App\Services\Kyc\KycProviderInterface;
use App\Services\Kyc\KycProviderManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Batch 02 product surfaces: identity-provider selection, AML mode
 * authority, upload scanning, and the two response shapes every portal script
 * already knows how to read.
 *
 * WHY A DEDICATED PROVIDER AND NOT AppServiceProvider
 * `AppServiceProvider` is the application's general boot file and is edited by
 * every batch; adding four unrelated singletons there makes a merge conflict of
 * every concurrent change. This provider owns one concern — the product-surface
 * services — and is registered with a single appended line in
 * bootstrap/providers.php, which is the only shared file it touches.
 *
 * THE JSON MACROS ARE THE POINT OF THE LAST THIRD OF THIS FILE.
 * `resources/js/frontend-api.js` reads exactly two envelopes:
 *
 *   success  { status: 'success', ... }                (any 2xx)
 *   failure  { error: { code, message }, fields?: {} }  (any 4xx/5xx)
 *
 * and every portal script branches on `error.code`. Before these macros each
 * controller hand-built its own array, so a new endpoint could invent a third
 * shape and the script would silently show nothing. The macros make the two
 * shapes one call each.
 */
final class FrontendOpsServiceProvider extends ServiceProvider
{
    /**
     * Every error code the frontend-api wrapper special-cases. A controller that
     * returns something outside this list still gets a valid envelope; the list
     * exists so `FrontendOpsServiceProvider` and the JS cannot drift about which
     * codes trigger a redirect, a retry or a NOT_CONFIGURED panel.
     *
     * @var list<string>
     */
    public const CLIENT_ERROR_CODES = [
        'AUTHENTICATION_REQUIRED',
        'SESSION_EXPIRED',
        'CSRF_TOKEN_MISMATCH',
        'VALIDATION_FAILED',
        'RATE_LIMITED',
        'NOT_CONFIGURED',
        'FORBIDDEN',
        'NOT_FOUND',
        'CONFLICT',
        'REAUTH_REQUIRED',
        'REAUTH_INVALID',
    ];

    public function register(): void
    {
        /*
        | Identity provider selection.
        |
        | The manager is a singleton because it caches nothing across requests
        | and holds no state worth rebuilding; the INTERFACE binding is scoped
        | resolution, so a controller that type-hints the interface never has to
        | know the manager exists. Resolution is lazy: an unconfigured provider
        | throws KycProviderException at the moment of use (which the controller
        | renders as NOT_CONFIGURED), not at container build time, so an
        | unrelated page still renders.
        */
        $this->app->singleton(KycProviderManager::class);

        $this->app->bind(
            KycProviderInterface::class,
            fn (Application $app): KycProviderInterface => $app->make(KycProviderManager::class)->provider(),
        );

        /*
        | AML mode authority. Singleton so the "unrecognised mode" warning is
        | emitted once per request rather than once per flow.
        */
        $this->app->singleton(AmlScreeningService::class);

        /*
        | Upload scanning. The manager is a singleton (it caches resolved
        | drivers); the interface is bound to the configured driver so an upload
        | service can depend on the narrow ScannerInterface without knowing about
        | driver selection. A production deployment on the null driver throws
        | here — deliberately, and the manager refuses before any file is read.
        */
        $this->app->singleton(AntimalwareManager::class);

        $this->app->bind(
            ScannerInterface::class,
            fn (Application $app): ScannerInterface => $app->make(AntimalwareManager::class)->driver(),
        );
    }

    public function boot(): void
    {
        $this->registerErrorMacros();
        $this->registerSuccessMacros();
    }

    /**
     * The failure envelope. One shape, one place.
     */
    private function registerErrorMacros(): void
    {
        /**
         * @param  string  $code  one of {@see self::CLIENT_ERROR_CODES}, or any stable machine code
         * @param  array<string, list<string>>  $fields  per-field validation messages, keyed by field name
         * @param  array<string, scalar|null>  $meta  extra scalars the client may need (retry_after, state)
         */
        Response::macro('opsError', function (
            string $code,
            string $message,
            int $status = 400,
            array $fields = [],
            array $meta = [],
        ): JsonResponse {
            $status = $status >= 400 && $status <= 599 ? $status : 400;

            $payload = [
                'status' => 'error',
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ];

            if ($fields !== []) {
                $payload['errors'] = $fields;
                $payload['error']['fields'] = $fields;
            }

            if ($meta !== []) {
                $payload = array_merge($payload, $meta);
            }

            return new JsonResponse($payload, $status);
        });

        /**
         * The "this lane is not configured" answer, which is NOT an error the
         * user caused. It always carries `state: NOT_CONFIGURED` so
         * `isNotConfigured()` in frontend-api.js recognises it regardless of the
         * status code chosen by the caller.
         */
        Response::macro('opsNotConfigured', function (string $message = '', array $meta = []): JsonResponse {
            return new JsonResponse(array_merge([
                'status' => 'NOT_CONFIGURED',
                'state' => 'NOT_CONFIGURED',
                'message' => $message,
                'error' => [
                    'code' => 'NOT_CONFIGURED',
                    'message' => $message,
                ],
            ], $meta), 501);
        });

        /**
         * A rate-limited answer carrying the exact retry window, so the wrapper
         * can honour it instead of guessing an interval.
         */
        Response::macro('opsRateLimited', function (int $retryAfterSeconds, string $message = ''): JsonResponse {
            $retryAfter = max(1, $retryAfterSeconds);

            return new JsonResponse([
                'status' => 'error',
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => $message,
                ],
                'retry_after' => $retryAfter,
            ], 429)->withHeaders(['Retry-After' => (string) $retryAfter]);
        });

        /**
         * A validation failure in the project's shape: `errors[field]` plus the
         * same map under `error.fields`, so both the Laravel convention and the
         * frontend-api contract are satisfied by one call.
         */
        Response::macro('opsValidationFailed', function (array $fields, string $message = ''): JsonResponse {
            return new JsonResponse([
                'status' => 'error',
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => $message,
                    'fields' => $fields,
                ],
                'errors' => $fields,
            ], 422);
        });
    }

    /**
     * The success envelope.
     */
    private function registerSuccessMacros(): void
    {
        /**
         * @param  array<string, mixed>  $data
         * @param  string|null  $message  a rendered, already-translated sentence; null when the payload speaks for itself
         */
        Response::macro('opsOk', function (array $data = [], ?string $message = null, int $status = 200): JsonResponse {
            $payload = array_merge(['status' => 'success'], $data);

            if ($message !== null && $message !== '') {
                $payload['message'] = $message;
            }

            return new JsonResponse($payload, $status);
        });
    }
}
