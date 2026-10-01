<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Trust Proxies Middleware.
 *
 * Configures trusted reverse proxy headers to accurately resolve client IP,
 * HTTPS protocol, and hostnames while preventing client-spoofed headers.
 */
class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * In production, configure TRUSTED_PROXIES env or leave configured CIDR blocks.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_PREFIX |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
