<?php

declare(strict_types=1);

use Illuminate\Http\Request;

/*
 * |--------------------------------------------------------------------------
 * | Trusted reverse proxies
 * |--------------------------------------------------------------------------
 * |
 * | WHY THIS FILE EXISTS AT ALL
 * |
 * | Illuminate\Http\Middleware\TrustProxies is already in the framework's DEFAULT
 * | global middleware stack (Foundation/Configuration/Middleware.php puts it right
 * | after InvokeDeferredCallbacks), and its handle() opens with
 * |
 * |     $request::setTrustedProxies([], $this->getTrustedHeaderNames());
 * |     $trustedIps = $this->proxies() ?: config('trustedproxy.proxies');
 * |
 * | so without this file every request resolves its client IP to the machine that
 * | opened the TCP connection - the load balancer - no matter what X-Forwarded-For
 * | says. That is not merely an audit cosmetic here:
 * |
 * |   - AppServiceProvider::registerLoginLimiter() spends one of its two budgets on
 * |     Limit::perMinutes(...)->by('login:ip:'.$request->ip()), so with a collapsed IP
 * |     every player behind the proxy shares one bucket: one attacker can exhaust it
 * |     and lock the whole lobby out of signing in.
 * |   - The authentication-attempt audit lane (AuthenticationSecurityService::record())
 * |     persists 'ip_address', as do KycController, WithdrawalController,
 * |     BalanceTransferController, DepositController and PlayerProfilePortalController.
 * |     Every one of those rows would name the load balancer.
 * |   - HomeController keys a per-IP check cache with sha1($input.'|'.$request->ip()),
 * |     so a collapsed IP merges distinct visitors into one cache entry.
 * |
 * | app/Http/Middleware/TrustProxies.php used to carry this policy as a subclass with
 * | `protected $proxies = '*'` and a docblock promising a TRUSTED_PROXIES variable it
 * | never read. It was registered in bootstrap/app.php, in no middleware group, and in
 * | no route - `grep -rn 'Http\\Middleware\\TrustProxies' app config routes bootstrap
 * | tests` returned nothing but the file itself - so the promise was unenforceable and
 * | the class was dead weight. It is deleted; this file is now the only place the
 * | policy lives, and it is read by the middleware that already runs.
 * |
 * | DEFAULT: INTERNAL ADDRESSES ONLY
 * |
 * | Nothing is trusted blindly. The loopback and RFC 1918 ranges cover the two
 * | topologies this project actually deploys in (nginx or a sidecar on the same host,
 * | and a private-network load balancer or ingress). A request that arrives from a
 * | public address is never believed, so a client cannot mint its own X-Forwarded-For.
 * |
 * | A CDN in front (Cloudflare / CloudFront, whose egress addresses are public) needs
 * | TRUSTED_PROXIES=* - or, better, their published CIDR list. That setting trusts the
 * | immediate peer, which is exactly why the header MASK is deliberately narrower than
 * | the framework default; the mask is pinned in bootstrap/app.php, next to the reason
 * | it cannot be read from config there.
 * |
 * | Values:
 * |   unset or empty  -> the internal ranges below
 * |   none            -> trust nothing at all (every request reports its TCP peer)
 * |   * or **         -> trust the calling IP on every request (CDN deployments)
 * |   anything else   -> comma-separated IPs or CIDR blocks, e.g.
 * |                      TRUSTED_PROXIES="203.0.113.10,198.51.100.0/24"
 * |
 * | The '*' case must stay a bare *string*. The framework compares `$trustedIps === '*'`
 * | before it ever treats the value as a list, and Symfony matches array entries as
 * | exact addresses or CIDR blocks - so a one-element array ['*'] would silently trust
 * | nobody. The shape mapping below is therefore load bearing, and
 * | tests/Feature/Http/TrustedProxyResolutionTest.php pins it.
 */

$configured = trim((string) env('TRUSTED_PROXIES', ''));

if ($configured === '' || strcasecmp($configured, 'internal') === 0) {
    $proxies = [
        '127.0.0.1',
        '::1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
    ];
} elseif (strcasecmp($configured, 'none') === 0) {
    $proxies = [];
} elseif ($configured === '*' || $configured === '**') {
    $proxies = $configured;
} else {
    $proxies = array_values(array_filter(
        array_map(static fn (string $entry): string => trim($entry), explode(',', $configured)),
        static fn (string $entry): bool => $entry !== ''
    ));
}

return [

    /*
     | The addresses whose forwarded headers the application believes. See the header
     | block for the accepted shapes.
     */
    'proxies' => $proxies,
];
