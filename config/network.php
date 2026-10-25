<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxy CIDRs
    |--------------------------------------------------------------------------
    | Explicit list of reverse proxies / load balancers (AWS ALB, Cloudflare, Nginx).
    | Empty/null prevents trusting spoofed X-Forwarded-For headers.
    */
    'trusted_proxies' => array_filter(explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,169.254.0.0/16'))),
    'trusted_proxies_setting' => env('TRUSTED_PROXIES'),
    'allow_wildcard_trusted_proxies' => (bool) env('ALLOW_WILDCARD_TRUSTED_PROXIES', false),

    /*
    |--------------------------------------------------------------------------
    | IP Blocklist Enactment
    |--------------------------------------------------------------------------
    */
    'ip_blocklist_enabled' => (bool) env('SECURITY_IP_BLOCKLIST_ENABLED', true),

    'ip_blocklist_cache_ttl' => (int) env('SECURITY_IP_BLOCKLIST_CACHE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Admin Emergency Break-Glass Allowlist
    |--------------------------------------------------------------------------
    | Emergency CIDRs exempted from IP blocklist lockout.
    */
    'admin_allowlist' => array_filter(explode(',', (string) env('SECURITY_ADMIN_ALLOWLIST', '127.0.0.1'))),

];
