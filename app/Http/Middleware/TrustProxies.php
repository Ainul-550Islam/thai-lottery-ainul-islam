<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Explicit peer trust only; no platform/Host-derived or static wildcard fallback. */
class TrustProxies extends Middleware
{
    private const TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT;

    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Symfony holds proxy trust statically: replace it on EVERY request,
        // including invalid configuration, to avoid long-lived worker leakage.
        $request::setTrustedProxies([], self::TRUSTED_HEADERS);
        $proxies = $this->config->get('network.trusted_proxies', []);
        if (! is_array($proxies) || ! array_is_list($proxies) || count($proxies) > 64) {
            $this->invalidConfiguration();
        }
        foreach ($proxies as $proxy) {
            if (! $this->validProxy($proxy)) {
                // Reject the entire configuration, never partially trust a bad list.
                $this->invalidConfiguration();
            }
        }
        $request::setTrustedProxies(array_values(array_unique($proxies)), self::TRUSTED_HEADERS);

        // Deliberately do not call parent::handle(): it can trust the calling IP
        // based on hosting/Host hints or other global configuration. Symfony still
        // resolves trusted multi-hop chains and verifies the immediate peer.
        // X-Forwarded-Host, X-Forwarded-Prefix and RFC Forwarded are NOT trusted.
        return $next($request);
    }

    private function invalidConfiguration(): never
    {
        // Preserve 503 through the existing exception mapper without changing it.
        // Do not echo the proxy list or exception internals to public clients.
        throw new HttpResponseException(ApiResponse::error(
            'service_unavailable', 'Service unavailable.', 503,
        ));
    }

    private function validProxy(mixed $proxy): bool
    {
        if (! is_string($proxy) || $proxy === '' || strlen($proxy) > 64 || trim($proxy) !== $proxy) {
            return false;
        }
        $parts = explode('/', $proxy);
        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }
        $maximum = str_contains($parts[0], ':') ? 128 : 32;

        return preg_match('/^[1-9][0-9]{0,2}$/D', $parts[1]) === 1
            && (int) $parts[1] <= $maximum;
    }
}
