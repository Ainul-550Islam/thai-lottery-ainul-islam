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

    private const ALLOWED_HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT;

    public function __construct(
        private readonly ?Repository $config = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        Request::setTrustedProxies([], self::ALLOWED_HEADERS);

        $config = $this->config ?? config();
        $network = $config->get('network');

        if (! is_array($network) || ! array_key_exists('trusted_proxies', $network)) {
            return $next($request);
        }

        $proxies = $network['trusted_proxies'];

        if (! $this->isValidProxyList($proxies)) {
            Request::setTrustedProxies([], self::ALLOWED_HEADERS);

            throw new HttpResponseException(new JsonResponse([
                'error' => [
                    'code' => 'service_unavailable',
                    'message' => 'Trusted proxy configuration is invalid.',
                ],
            ], 503));
        }

        if ($proxies !== []) {
            Request::setTrustedProxies($proxies, self::ALLOWED_HEADERS);
        }

        return $next($request);
    }

    /**
     * @phpstan-assert-if-true list<string> $proxies
     */
    private function isValidProxyList(mixed $proxies): bool
    {
        if (! is_array($proxies) || ! array_is_list($proxies) || count($proxies) > self::MAX_ENTRIES) {
            return false;
        }

        foreach ($proxies as $entry) {
            if (! is_string($entry) || ! $this->isValidProxyEntry($entry)) {
                return false;
            }
        }

        return true;
    }

    private function isValidProxyEntry(string $entry): bool
    {
        if ($entry === '' || trim($entry) !== $entry || str_contains($entry, '%')) {
            return false;
        }

        $parts = explode('/', $entry);
        if (count($parts) > 2) {
            return false;
        }

        $ip = $parts[0];
        $isIpv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isIpv6 = ! $isIpv4 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $isIpv4 && ! $isIpv6) {
            return false;
        }

        if (count($parts) === 1) {
            return true;
        }

        $prefix = $parts[1];
        if ($prefix === '' || ! ctype_digit($prefix)) {
            return false;
        }

        if (strlen($prefix) > 1 && str_starts_with($prefix, '0')) {
            return false;
        }

        $bits = (int) $prefix;
        $maxBits = $isIpv4 ? 32 : 128;

        return $bits >= 1 && $bits <= $maxBits;
    }
}
