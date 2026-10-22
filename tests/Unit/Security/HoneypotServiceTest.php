<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Security\HoneypotService;
use App\Services\Support\ContactPrivacyService;
use App\Services\Support\ContactSpamProtectionService;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HoneypotServiceTest extends TestCase
{
    private Repository $config;

    private HoneypotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $this->config = new Repository;
        $this->service = new HoneypotService($this->config);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(): Request
    {
        $request = Request::create('/login', 'POST');
        $session = new Store('honeypot-test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    public function test_random_fields_and_empty_round_trip_are_single_use(): void
    {
        self::assertSame('log', $this->service->mode());
        $request = $this->request();
        $first = $this->service->issue($request, 'login');
        $second = $this->service->issue($request, 'login');
        self::assertNotSame($first['field'], $second['field']);
        self::assertNotSame($first['token'], $second['token']);
        $request->merge(['_hp_token' => $first['token'], $first['field'] => '']);
        self::assertSame('CLEAR', $this->service->inspect($request, 'login'));
        self::assertSame('INVALID_FORM', $this->service->inspect($request, 'login'));
    }

    public static function values(): array
    {
        return [[null, false], ['', false], ['  ', false], ['0', true], ['bot', true], [false, true], [0, true], [[], true]];
    }

    #[DataProvider('values')]
    public function test_value_semantics(mixed $value, bool $tripped): void
    {
        self::assertSame($tripped, HoneypotService::hasValue($value));
        if (is_array($value)) {
            // Symfony's InputBag rejects nested non-scalar input on construction;
            // the classifier contract is covered directly above for this value.
            return;
        }
        $request = $this->request();
        $challenge = $this->service->issue($request, 'register');
        $request->merge(['_hp_token' => $challenge['token'], $challenge['field'] => $value]);
        self::assertSame($tripped ? 'FILLED' : 'CLEAR', $this->service->inspect($request, 'register'));
        if (is_string($value) || $value === null) {
            $legacy = new ContactSpamProtectionService($this->config, new ContactPrivacyService);
            self::assertSame($tripped, $legacy->isHoneypotTripped($value));
        }
    }

    public function test_missing_field_is_distinct_from_null_after_laravel_normalization(): void
    {
        $request = $this->request();
        $challenge = $this->service->issue($request, 'login');
        $request->merge(['_hp_token' => $challenge['token']]);
        self::assertSame('MISSING_FIELD', $this->service->inspect($request, 'login'));
    }

    public function test_wrong_session_or_purpose_cannot_clear(): void
    {
        $request = $this->request();
        $challenge = $this->service->issue($request, 'login');
        $other = $this->request();
        $input = ['_hp_token' => $challenge['token'], $challenge['field'] => ''];
        $other->merge($input);
        self::assertSame('INVALID_FORM', $this->service->inspect($other, 'login'));
        $request->merge($input);
        self::assertSame('PURPOSE_MISMATCH', $this->service->inspect($request, 'register'));
        self::assertSame('INVALID_FORM', $this->service->inspect($request, 'login'));
    }

    public function test_expiry_boundary_and_bounded_store(): void
    {
        $this->config->set('honeypot.max_forms', 2);
        $request = $this->request();
        $first = $this->service->issue($request, 'login');
        $this->service->issue($request, 'login');
        $last = $this->service->issue($request, 'login');
        $entries = $request->session()->get('security.honeypot.forms');
        self::assertCount(2, $entries);
        self::assertArrayNotHasKey($first['token'], $entries);
        Carbon::setTestNow(Carbon::now()->addSeconds(600));
        $request->merge(['_hp_token' => $last['token'], $last['field'] => '']);
        self::assertSame('EXPIRED_FORM', $this->service->inspect($request, 'login'));
        $this->service->issue($request, 'login');
        self::assertCount(1, $request->session()->get('security.honeypot.forms'));
    }

    public function test_query_string_proof_is_not_accepted(): void
    {
        $request = $this->request();
        $challenge = $this->service->issue($request, 'login');
        $request->query->replace(['_hp_token' => $challenge['token'], $challenge['field'] => '']);
        self::assertSame('INVALID_FORM', $this->service->inspect($request, 'login'));
    }

    public function test_disable_and_missing_session_are_explicit(): void
    {
        self::assertSame('UNAVAILABLE', $this->service->inspect(Request::create('/'), 'login'));
        $this->config->set('honeypot.enabled', false);
        self::assertNull($this->service->issue($this->request(), 'login'));
        self::assertSame('DISABLED', $this->service->inspect($this->request(), 'login'));
    }

    public static function invalidPolicy(): array
    {
        return [['mode', 'typo'], ['enabled', 'true'], ['ttl_seconds', 0], ['ttl_seconds', 3601], ['max_forms', 0], ['max_forms', 51]];
    }

    #[DataProvider('invalidPolicy')]
    public function test_bad_configuration_does_not_silently_clear(string $key, mixed $value): void
    {
        $this->config->set('honeypot.'.$key, $value);
        $this->expectException(LogicException::class);
        $this->service->inspect($this->request(), 'login');
    }
}
