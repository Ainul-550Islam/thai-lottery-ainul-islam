<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Http\Support\BetPurchaseErrorMapper;
use App\Services\Network\ConfiguredIpPolicy;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Only routes explicitly attaching this middleware are subject to this policy. */
final class ConfiguredIpAccessMiddleware
{
    public function __construct(private readonly ConfiguredIpPolicy $policy, private readonly LoggerInterface $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $assessment = $this->policy->assess($request->ip());
        } catch (LogicException) {
            throw new HttpResponseException(ApiResponse::error('service_unavailable', 'Service unavailable.', 503));
        }
        $request->attributes->set('ip_access.code', $assessment['code']);
        $request->attributes->set('ip_access.mode', $assessment['mode']);
        $unavailable = in_array($assessment['code'], ['STORE_UNAVAILABLE', 'CAPACITY_EXCEEDED', 'RULE_INVALID'], true);
        $flagged = $unavailable || in_array($assessment['code'], ['LISTED', 'ADDRESS_UNAVAILABLE'], true);
        $reject = $flagged && $assessment['mode'] === 'enforce';
        if ($flagged) {
            try {
                $this->logger->notice('security.ip_access_observation', [
                    'code' => $assessment['code'], 'mode' => $assessment['mode'],
                    'adapter_action' => $reject ? 'reject' : 'observe',
                ]);
            } catch (Throwable) {
                // Log transport failure cannot turn enforcement into allowance,
                // or turn an observation-only policy into a request failure.
            }
        }
        if ($reject && $unavailable) {
            throw new HttpResponseException(ApiResponse::error('service_unavailable', 'Service unavailable.', 503));
        }
        if ($reject) {
            throw new HttpResponseException(ApiResponse::error(
                BetPurchaseErrorMapper::CODE_AUTHORIZATION_FAILED, 'Request not allowed.', 403,
            ));
        }

        return $next($request);
    }
}
