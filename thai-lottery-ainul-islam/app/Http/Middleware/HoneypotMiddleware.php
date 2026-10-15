<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\Security\HoneypotService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Auth form observation by default; enforcement is an explicit operator choice. */
final class HoneypotMiddleware
{
    public function __construct(private readonly HoneypotService $honeypot, private readonly LoggerInterface $logger) {}

    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        $mode = 'log';
        try {
            $mode = $this->honeypot->mode();
            $code = $this->honeypot->inspect($request, $purpose);
        } catch (LogicException) {
            throw new HttpResponseException(ApiResponse::error('service_unavailable', 'Service unavailable.', 503));
        } catch (Throwable) {
            $code = 'UNAVAILABLE';
        } finally {
            // Do not let trap values/proofs reach validation old-input flashing,
            // downstream business payloads or logs. This namespace is reserved.
            foreach ([$request->request, $request->query, $request->json(), $request->files] as $bag) {
                foreach (array_keys($bag->all()) as $key) {
                    if ($key === HoneypotService::TOKEN_FIELD || preg_match('/^hp_[a-f0-9]{24}$/D', (string) $key)) {
                        $bag->remove($key);
                    }
                }
            }
        }
        $request->attributes->set('honeypot.status', $code);
        $request->attributes->set('honeypot.mode', $mode);
        $suspicious = ! in_array($code, ['CLEAR', 'DISABLED'], true);
        if ($suspicious) {
            try {
                $this->logger->notice('security.honeypot_observation', ['code' => $code, 'mode' => $mode, 'purpose' => $purpose]);
            } catch (Throwable) {
                // Logging failure cannot grant an enforced request or block log mode.
            }
        }
        if ($suspicious && $mode === 'enforce') {
            throw ValidationException::withMessages([
                'security_check' => __('The security check failed. Reload the form and try again.'),
            ]);
        }

        return $next($request);
    }
}
