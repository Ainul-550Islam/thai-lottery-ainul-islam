<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrustProxies
{
    private const MAX_ENTRIES = 64;

    private const ALLOWED_HEADER_SET = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT;

    private readonly Repository $config;

    public function __construct(?Repository $config = null)
    {
        $this->config = $config ?? app('config');
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->config->has('network.trusted_proxies')) {
            Request::setTrustedProxies([], self::ALLOWED_HEADER_SET);

            return $next($request);
        }

        $raw = $this->config->get('network.trusted_proxies');
        $validated = $this->validateProxyAllowlist($raw);

        if ($validated === null) {
            Request::setTrustedProxies([], self::ALLOWED_HEADER_SET);

            throw new HttpResponseException(new JsonResponse([
                'error' => [
                    'code' => 'service_unavailable',
                    'message' => 'Trusted proxy configuration is invalid.',
                ],
            ], Response::HTTP_SERVICE_UNAVAILABLE));
        }

        Request::setTrustedProxies($validated, self::ALLOWED_HEADER_SET);

        return $next($request);
    }

    /**
     * @return list<string>|null
     */
    private function validateProxyAllowlist(mixed $raw): ?array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > self::MAX_ENTRIES) {
            return null;
        }

        $clean = [];
        foreach ($raw as $entry) {
            if (! is_string($entry) || $entry === '' || $entry !== trim($entry)) {
                return null;
            }

            if (str_contains($entry, '%')) {
                return null;
            }

            if (str_contains($entry, '/')) {
                $parts = explode('/', $entry);
                if (count($parts) !== 2) {
                    return null;
                }
                [$ip, $prefix] = $parts;
                if ($ip === '' || $prefix === '' || ! ctype_digit($prefix)) {
                    return null;
                }
                if (strlen($prefix) > 1 && str_starts_with($prefix, '0')) {
                    return null;
                }

                $prefixInt = (int) $prefix;
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    if ($prefixInt < 1 || $prefixInt > 32) {
                        return null;
                    }
                } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                    if ($prefixInt < 1 || $prefixInt > 128) {
                        return null;
                    }
                } else {
                    return null;
                }

                $clean[] = $entry;

                continue;
            }

            if (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6) === false) {
                return null;
            }

            $clean[] = $entry;
        }

        return $clean;
    }
}
