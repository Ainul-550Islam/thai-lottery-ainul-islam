<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use App\Http\Support\BetPurchaseErrorMapper;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Universal Exception Handler.
 *
 * Protects production error envelopes against information leakage (no stack traces,
 * file paths, database credentials, or secret keys). Maps API errors into standard
 * ApiResponse envelopes and preserves correlation IDs.
 */
class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
        'secret',
        'api_key',
        'token',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return $this->renderApiResponse($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * Build standard, safe JSON API error envelope.
     */
    protected function renderApiResponse(Request $request, Throwable $e): JsonResponse
    {
        if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
            return $e->getResponse();
        }

        /** @var BetPurchaseErrorMapper $mapper */
        $mapper = app(BetPurchaseErrorMapper::class);
        $mapped = $mapper->map($e);

        if ($mapped['status'] >= 500) {
            Log::error('api.unhandled_exception', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'path' => $request->path(),
                'method' => $request->method(),
                'correlation_id' => $request->header('X-Correlation-Id'),
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);
        }

        $headers = [];
        if ($e instanceof HttpExceptionInterface) {
            $headers = $e->getHeaders();
            unset($headers['Content-Type']);
        }

        return ApiResponse::error(
            code: $mapped['code'],
            message: $mapped['message'],
            status: $mapped['status'],
            details: $mapped['details'],
            headers: $headers,
        );
    }
}
