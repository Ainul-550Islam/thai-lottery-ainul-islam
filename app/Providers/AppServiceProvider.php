<?php

namespace App\Providers;

use App\Http\Responses\ApiResponse;
use App\Http\Support\BetPurchaseErrorMapper;
use App\Services\Lottery\GloDataMatrixParser;
use App\Services\Lottery\GloDataMatrixParserInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // NOTE (Payment phase): the PaymentGatewayInterface binding lives here
        // once App\Services\Payment\Gateway\* classes are implemented. It is
        // intentionally not registered yet so the container stays resolvable.

        // PROMPT 4: the Data Matrix reader is consumed through its interface by
        // App\Services\Lottery\TicketBarcodeService, so the public verification
        // page can be pointed at a real reader later without touching the
        // service. GloDataMatrixParser is the only implementation today and
        // already reports 'not_configured' when no format is authorised.
        $this->app->bind(GloDataMatrixParserInterface::class, GloDataMatrixParser::class);
    }

    public function boot(): void
    {
        // Money is handled with bcmath strings; force a consistent scale.
        if (function_exists('bcscale')) {
            bcscale(2);
        }

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerRateLimiters();

        if ($this->app->environment('local')) {
            DB::whenQueryingForLongerThan(1000, function ($connection, $event): void {
                logger()->warning('Slow query detected.', [
                    'sql' => $event->sql,
                    'time' => $event->time,
                ]);
            });
        }
    }

    /**
     * Register the named rate limiters used by routes/api.php.
     *
     * config/security.php already declared these ceilings in Phase 1 and explicitly noted
     * that "Named limiters are registered from these values in a later phase". Phase 4.4 is
     * that phase. The numbers are read from config, never hard-coded here, so an operator
     * changes a limit through the existing RATE_LIMIT_* environment variables rather than by
     * editing application code.
     *
     * WHY THE `bet` LIMITER IS KEYED ON THE USER, NOT THE IP
     * Betting is an authenticated action. Keying on the IP would punish every player behind
     * one mobile carrier NAT for the behaviour of one of them, and would let a single
     * account bypass its own ceiling by rotating IPs. The user id is the only key that
     * matches what the limit is actually protecting. This is also exactly what
     * config('security.rate_limits.bet.by') already specified: 'user'.
     */
    private function registerRateLimiters(): void
    {
        $this->registerLoginLimiter();

        $apiPerMinute = (int) config('security.rate_limits.api.max_per_minute', 60);
        $betPerMinute = (int) config('security.rate_limits.bet.max_per_minute', 10);
        $webhookPerMinute = (int) config('security.rate_limits.webhook.max_per_minute', 120);
        $depositPerHour = (int) config('security.rate_limits.deposit.max_per_hour', 5);
        $withdrawalPerDay = (int) config('security.rate_limits.withdrawal.max_per_day', 3);

        // Money-entry ceilings, keyed on the authenticated user exactly as
        // config('security.rate_limits.deposit.by') / withdrawal.by declare. Deposits and
        // withdrawals are the two write surfaces where a per-hour / per-day ceiling matters
        // beyond the per-minute api limiter, so both are registered here even though only
        // the deposit route carries the deposit limiter today.
        // The key passed to ->by() is suffixed with the limiter name by the throttle
        // middleware, so ->by('user:1') yields the cache key 'deposit:user:1' — the exact
        // key that hardening consumers clear with RateLimiter::clear('deposit:user:N').
        RateLimiter::for('deposit', function (Request $request) use ($depositPerHour): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perHour($depositPerHour)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('withdrawal', function (Request $request) use ($withdrawalPerDay): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perDay($withdrawalPerDay)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // Two further named limiters that hardening consumers reference by name:
        // 'player-bet-placement' is the tighter per-minute ceiling for the wager write
        // surface, and 'financial-critical' guards every endpoint that can move money.
        // Both key on the authenticated user, falling back to the IP for unauthenticated
        // traffic so a hostile host cannot exhaust a real user's allowance.
        RateLimiter::for('player-bet-placement', function (Request $request) use ($betPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($betPerMinute)
                ->by($identifier === null ? 'bet:ip:'.$request->ip() : 'bet:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('financial-critical', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(30)
                ->by($identifier === null ? 'financial-critical:ip:'.$request->ip() : 'financial-critical:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // 'player-api' mirrors the broad per-minute API ceiling; registered under its own
        // name so the hardening surface can reference it independently of 'api'.
        RateLimiter::for('player-api', function (Request $request) use ($apiPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($apiPerMinute)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('api', function (Request $request) use ($apiPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            // 'user_or_ip' per config: an authenticated caller is limited as themselves, and
            // an unauthenticated one - which on this surface means a request that will be
            // rejected by auth middleware anyway - is limited by IP so that unauthenticated
            // traffic cannot be used to exhaust a real user's allowance.
            return Limit::perMinute($apiPerMinute)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('bet', function (Request $request) use ($betPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            if ($identifier === null) {
                // Should not occur behind auth:sanctum. Falling back to the IP is the safe
                // direction: an unkeyed limiter would be no limiter at all.
                return Limit::perMinute($betPerMinute)
                    ->by('bet:ip:'.$request->ip())
                    ->response($this->throttleResponse());
            }

            return Limit::perMinute($betPerMinute)
                ->by('bet:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // The webhook limiter is keyed on the IP, exactly as
        // config('security.rate_limits.webhook.by') declares. Incoming gateway
        // notifications are unauthenticated by nature of being server-to-server
        // callbacks; the IP is the only key that can pin a hostile host without
        // punishing legitimately high-volume providers.
        RateLimiter::for('glo.public', function (Request $request): Limit {
            $perMinute = (int) config('glo.public_status.rate_limit_per_minute', 30);

            return Limit::perMinute($perMinute)
                ->by('glo-public:'.$request->ip())
                ->response($this->throttleResponse());
        });

        RateLimiter::for('webhook', function (Request $request) use ($webhookPerMinute): Limit {
            return Limit::perMinute($webhookPerMinute)
                ->by('ip:'.$request->ip())
                ->response($this->throttleResponse());
        });

        // Public Home ticket-check UI — anonymous IP-keyed ceiling.
        RateLimiter::for('home-check', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by('home-check:'.$request->ip())
                ->response($this->throttleResponse());
        });

        // Public prize/ticket verification (PROMPT 4) — anonymous and
        // enumerable by nature: six digits is a 1,000,000-value space, so an
        // unthrottled checker is a free oracle for "does this ticket exist".
        //
        // TWO CEILINGS, ONE LIMITER. Laravel evaluates every Limit returned
        // here, so the IP ceiling bounds sweeping a range while the second
        // ceiling - keyed on a HASH of the submitted value - bounds hammering
        // one value. The hash means the limiter never stores the number that
        // was checked, matching the evidence policy in
        // config('ticket_verification.evidence').
        RateLimiter::for('ticket-verification', function (Request $request): array {
            $perMinute = max(1, (int) config('ticket_verification.rate_limit.per_minute', 12));
            $perHour = max($perMinute, (int) config('ticket_verification.rate_limit.per_hour', 120));
            $fingerprintPerMinute = max(1, (int) config('ticket_verification.rate_limit.fingerprint_per_minute', 4));

            $value = (string) $request->input('value', '');
            $fingerprint = $value === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $value, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('ticket-verification:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('ticket-verification:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('ticket-verification:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 5: public National Lottery search.
        //
        // Same three-ceiling shape as 'ticket-verification', for the same
        // reason: the six-digit space is enumerable, so an IP ceiling alone
        // lets a distributed client walk it while each address stays polite.
        // The third ceiling is keyed on a HASHED query, so repeating one term
        // is cheap for a human refreshing a page and expensive for a script
        // grinding the space.
        //
        // The fingerprint is an HMAC of the term with the app key, never the
        // term itself: a rate-limiter cache entry should not become a
        // plaintext record of what the public searched for.
        RateLimiter::for('national-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('national_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('national_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('national_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('number', ''));

            if ($term === '') {
                $term = trim((string) $request->query('date', ''));
            }

            $fingerprint = $term === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('national-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('national-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('national-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 6: public Weekly Lottery search.
        //
        // Same three-ceiling shape as 'ticket-verification' and
        // 'national-result-search', for the same reason: the six-digit space
        // is enumerable, so an IP ceiling alone lets a distributed client walk
        // it while each address stays polite. The third ceiling is keyed on a
        // HASHED query, so repeating one term is cheap for a human refreshing
        // a page and expensive for a script grinding the space.
        //
        // The fingerprint is an HMAC of type+term with the app key, never the
        // term itself: a rate-limiter cache entry must not become a plaintext
        // record of what the public searched for.
        RateLimiter::for('weekly-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('weekly_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('weekly_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('weekly_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('type', '')).'|'.trim((string) $request->query('term', ''));

            $fingerprint = trim($term, '|') === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('weekly-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('weekly-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('weekly-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 8: the Bingo/Mega search space is the same size as the
        // Weekly one, so it gets the same three ceilings. The query
        // fingerprint is HMAC'd, never stored raw: a limiter key is not a
        // place to keep a record of what visitors searched for.
        RateLimiter::for('bingo-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('bingo_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('bingo_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('bingo_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('type', '')).'|'.trim((string) $request->query('term', ''));

            $fingerprint = trim($term, '|') === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('bingo-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('bingo-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('bingo-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 9: PCSO searches four widths rather than three, so the
        // space a crawler could walk is larger, not smaller. Same three
        // ceilings, same HMAC'd query fingerprint - a limiter key is not a
        // place to keep a record of what visitors searched for.
        // PROMPT 10: public contact submissions.
        //
        // A public POST that sends mail is an open relay without a ceiling.
        // Two dimensions here, both keyed on the REQUEST: the service applies
        // a third, hashed-email dimension it can only compute after
        // validation. Neither an address nor an IP is ever a cache key in
        // clear - a limiter store is a cache, and caches get dumped.
        RateLimiter::for('contact-submit', function (Request $request): array {
            $perMinute = max(1, (int) config('contact.anti_spam.per_minute', 3));
            // Not clamped to the per-minute figure: a lower hourly ceiling is
            // a real instruction, not a mistake to correct.
            $perHour = max(1, (int) config('contact.anti_spam.per_hour', 20));

            $sender = hash_hmac('sha256', 'contact|ip|'.$request->ip(), (string) config('app.key', ''));

            return [
                Limit::perMinute($perMinute)->by('contact-submit:min:'.$sender),
                Limit::perHour($perHour)->by('contact-submit:hour:'.$sender),
            ];
        });

        RateLimiter::for('pcso-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('pcso_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('pcso_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('pcso_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('type', '')).'|'.trim((string) $request->query('term', ''));

            $fingerprint = trim($term, '|') === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('pcso-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('pcso-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('pcso-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // Account verification submit/upload — user-keyed, moderate ceiling
        // so legitimate multi-file uploads work but scraping cannot.
        $verifyPerMinute = (int) config('account.rate_limits.verification_submit_per_minute', 5);
        RateLimiter::for('account-verification', function (Request $request) use ($verifyPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(max(1, $verifyPerMinute))
                ->by($identifier === null ? 'account-verification:ip:'.$request->ip() : 'account-verification:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // Grade page + history + refresh — user-keyed, cheap reads but not free-for-all.
        $gradePerMinute = (int) config('account.rate_limits.grade_history_per_minute', 30);
        RateLimiter::for('account-grade', function (Request $request) use ($gradePerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(max(1, $gradePerMinute))
                ->by($identifier === null ? 'account-grade:ip:'.$request->ip() : 'account-grade:user:'.$identifier)
                ->response($this->throttleResponse());
        });
    }

    /**
     * The `login` limiter used by the public token route.
     *
     * config/security.php already declared `rate_limits.login` with max_attempts,
     * decay_minutes and `by => 'email_and_ip'`, and nothing was reading it because the
     * project had no login route. Both halves of that key are used: the submitted
     * identifier (lower-cased so casing cannot multiply an attacker's allowance) and the
     * client IP. Keying on the identifier alone would let one host attack thousands of
     * accounts; keying on the IP alone would let a botnet attack one account.
     */
    private function registerLoginLimiter(): void
    {
        $maxAttempts = (int) config('security.rate_limits.login.max_attempts', 5);
        $decayMinutes = (int) config('security.rate_limits.login.decay_minutes', 15);

        RateLimiter::for('login', function (Request $request) use ($maxAttempts, $decayMinutes): array {
            $identifier = mb_strtolower(trim((string) $request->input('login', '')));

            return [
                Limit::perMinutes($decayMinutes, $maxAttempts)
                    ->by('login:id:'.sha1($identifier))
                    ->response($this->throttleResponse()),
                Limit::perMinutes($decayMinutes, $maxAttempts * 5)
                    ->by('login:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
            ];
        });
    }

    /**
     * The throttled response, in the project's API envelope.
     *
     * Without this, Laravel returns its own plain `{"message": "Too Many Attempts."}` body,
     * which would be the one response on the whole surface that did not match the documented
     * envelope - so a client's error handling would break precisely when it is being rate
     * limited. The Retry-After header that the throttle middleware adds is preserved.
     */
    private function throttleResponse(): callable
    {
        return function (Request $request, array $headers = []): Response {
            return ApiResponse::error(
                BetPurchaseErrorMapper::CODE_RATE_LIMITED,
                'Too many requests. Please slow down and retry shortly.',
                429,
                [],
                $headers,
            );
        };
    }
}
