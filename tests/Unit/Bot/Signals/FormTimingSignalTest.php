<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Signals;

use App\Services\Bot\BotScoreCalculator;
use App\Services\Bot\Enums\BotDecision;
use App\Services\Bot\Signals\FormTimingSignal;
use App\Services\Bot\Signals\HeaderConsistencySignal;
use App\Services\Bot\Signals\UserAgentSignal;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormTimingSignalTest extends TestCase
{
    private Repository $config;

    private FormTimingSignal $signal;

    private Store $session;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $this->config = new Repository(['bot' => [
            'form_timing' => ['field' => '_bot_timing', 'min_submit_seconds' => 2, 'ttl_seconds' => 600, 'max_tokens_per_session' => 10],
        ]]);
        $this->signal = new FormTimingSignal($this->config);
        $this->session = new Store('bot-test', new ArraySessionHandler(120));
        $this->session->start();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(mixed $token = null, ?Store $session = null, string $purpose = 'register'): Request
    {
        $request = Request::create('/register', 'POST', ['_bot_timing' => $token]);
        $request->setLaravelSession($session ?? $this->session);
        $request->attributes->set('bot.form_timing_required', true);
        $request->attributes->set('bot.form_purpose', $purpose);

        return $request;
    }

    private function issue(): string
    {
        return $this->signal->issue($this->request(), 'register');
    }

    private function advance(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    public static function ages(): array
    {
        return [[0, 'FORM_SUBMITTED_TOO_FAST'], [1, 'FORM_SUBMITTED_TOO_FAST'], [2, null], [600, null], [601, 'FORM_TOKEN_EXPIRED'], [-1, 'FORM_TOKEN_FUTURE']];
    }

    #[DataProvider('ages')]
    public function test_signed_age_boundaries_and_sequential_replay(int $age, ?string $reason): void
    {
        $token = $this->issue();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.[0-9]+\.[a-f0-9]{64}$/D', $token);
        $this->advance($age);
        $result = $this->signal->score($this->request($token));
        self::assertSame($reason === null ? 0 : 100, $result->strength);
        self::assertSame($reason === null ? [] : [$reason], $result->reasons);
        self::assertSame(['FORM_TOKEN_REPLAYED_OR_EVICTED'], $this->signal->score($this->request($token))->reasons);
    }

    public function test_timestamp_or_signature_tampering_does_not_consume_valid_token(): void
    {
        $token = $this->issue();
        $parts = explode('.', $token);
        $parts[1] = (string) ((int) $parts[1] - 60);
        self::assertSame(['FORM_TOKEN_INVALID'], $this->signal->score($this->request(implode('.', $parts)))->reasons);
        $parts = explode('.', $token);
        $parts[2] = str_repeat('0', 64);
        self::assertSame(['FORM_TOKEN_INVALID'], $this->signal->score($this->request(implode('.', $parts)))->reasons);
        $this->advance(2);
        self::assertSame(0, $this->signal->score($this->request($token))->strength);
    }

    public function test_cross_session_and_cross_purpose_refused(): void
    {
        $token = $this->issue();
        $other = new Store('other', new ArraySessionHandler(120));
        $other->start();
        self::assertSame(['FORM_TOKEN_INVALID'], $this->signal->score($this->request($token, $other))->reasons);
        self::assertSame(['FORM_TOKEN_INVALID'], $this->signal->score($this->request($token, purpose: 'withdraw'))->reasons);
        $this->advance(2);
        self::assertSame(0, $this->signal->score($this->request($token))->strength);
    }

    public function test_session_id_regeneration_invalidates_old_token(): void
    {
        $token = $this->issue();
        $this->session->migrate();
        $this->advance(2);
        self::assertSame(['FORM_TOKEN_INVALID'], $this->signal->score($this->request($token))->reasons);
    }

    public static function malformedTokens(): array
    {
        return [[null, 'FORM_TOKEN_MISSING'], ['', 'FORM_TOKEN_MISSING'], [[], 'FORM_TOKEN_INVALID'], ['broken', 'FORM_TOKEN_INVALID'], [str_repeat('x', 10000), 'FORM_TOKEN_INVALID']];
    }

    #[DataProvider('malformedTokens')]
    public function test_missing_or_malformed_token(mixed $token, string $reason): void
    {
        self::assertSame([$reason], $this->signal->score($this->request($token))->reasons);
    }

    public function test_api_without_trusted_context_is_not_penalized_or_mutated(): void
    {
        $request = Request::create('/api/check', 'POST', ['bot.form_timing_required' => true]);
        $before = $this->session->all();
        self::assertSame(0, $this->signal->score($request)->strength);
        self::assertSame($before, $this->session->all());
    }

    public function test_required_form_without_session_is_risk_evidence(): void
    {
        $request = Request::create('/register', 'POST');
        $request->attributes->set('bot.form_timing_required', true);
        $request->attributes->set('bot.form_purpose', 'register');
        self::assertSame(['FORM_SESSION_MISSING'], $this->signal->score($request)->reasons);
    }

    public function test_issue_requires_session(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->signal->issue(Request::create('/register'), 'register');
    }

    public function test_multiple_tabs_have_independent_tokens(): void
    {
        $a = $this->issue();
        $b = $this->issue();
        self::assertNotSame($a, $b);
        $this->advance(2);
        self::assertSame(0, $this->signal->score($this->request($a))->strength);
        self::assertSame(0, $this->signal->score($this->request($b))->strength);
    }

    public function test_session_storage_bound_and_expired_pruning(): void
    {
        $this->config->set('bot.form_timing.max_tokens_per_session', 2);
        $old = $this->issue();
        $this->issue();
        $new = $this->issue();
        self::assertCount(2, $this->session->get('bot.form_timing.tokens'));
        self::assertSame(['FORM_TOKEN_REPLAYED_OR_EVICTED'], $this->signal->score($this->request($old))->reasons);
        $this->advance(2);
        self::assertSame(0, $this->signal->score($this->request($new))->strength);
        $this->advance(601);
        $this->issue();
        self::assertCount(1, $this->session->get('bot.form_timing.tokens'));
    }

    public static function invalidSettings(): array
    {
        return [['min_submit_seconds', -1], ['min_submit_seconds', '2'], ['ttl_seconds', 1], ['ttl_seconds', 86401], ['max_tokens_per_session', 0], ['max_tokens_per_session', 101], ['field', 'bad.field']];
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_configuration_rejected(string $key, mixed $value): void
    {
        $this->config->set('bot.form_timing.'.$key, $value);
        $this->expectException(InvalidArgumentException::class);
        $this->issue();
    }

    public function test_missing_trusted_purpose_is_configuration_error(): void
    {
        $request = $this->request();
        $request->attributes->remove('bot.form_purpose');
        $this->expectException(InvalidArgumentException::class);
        $this->signal->score($request);
    }

    public function test_invalid_purpose_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->signal->issue($this->request(), 'email@example.com');
    }

    public function test_real_signal_composition_stays_observation_only(): void
    {
        $settings = require dirname(__DIR__, 4).'/config/bot.php';
        // Isolate this test from operator environment overrides.
        $settings['mode'] = 'log';
        $settings['enabled'] = true;
        $request = $this->request();
        $request->headers->replace(['User-Agent' => 'curl/8.0']);
        $result = (new BotScoreCalculator(new Repository(['bot' => $settings])))->calculate([
            (new UserAgentSignal)->score($request),
            (new HeaderConsistencySignal)->score($request),
            $this->signal->score($request),
        ]);
        self::assertSame(50, $result->score);
        self::assertSame(BotDecision::Challenge, $result->assessedDecision);
        self::assertSame(BotDecision::Allow, $result->decision);
        self::assertSame(0, $result->enforcementScore);
    }
}
