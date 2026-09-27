<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Models\ContactMessage;
use App\Models\ContactMessageDelivery;
use App\Services\Support\ContactDeliveryService;
use App\Services\Support\ContactMessageService;
use App\Services\Support\ContactPrivacyService;
use App\Services\Support\ContactSpamProtectionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Public Contact / Support surface (PROMPT 10, file 30).
 *
 * NO ADDRESS, DOMAIN OR WORDING IN THIS FILE CAME FROM ANY REFERENCE PAGE.
 * Every fixture uses example.test, which is reserved for exactly this.
 *
 * WHAT THIS SUITE IS MOSTLY ABOUT. Not that the form works - that is the easy
 * part - but that the page never claims more than happened. The delivery
 * tests below exist to make "we emailed support" impossible to say when no
 * provider is configured or the provider failed.
 */
final class ContactPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Visitor',
            'email' => 'tester@example.test',
            'subject' => 'Support question',
            'message' => 'This is a synthetic test message about a result page.',
        ], $overrides);
    }

    private function configureSupport(): void
    {
        config()->set('contact.support.name', 'Customer Support');
        config()->set('contact.support.email', 'support@example.test');
        config()->set('contact.support.hours', 'Monday to Friday, 09:00-18:00');
    }

    // =====================================================================
    // 1-4  Page rendering
    // =====================================================================

    public function test_01_contact_page_is_anonymous(): void
    {
        $response = $this->get('/contact');

        $response->assertOk();
        $this->assertSame(200, $response->getStatusCode(), 'A login redirect would not be 200.');
    }

    public function test_02_contact_page_renders_the_form(): void
    {
        $response = $this->get(route('contact'));

        $response->assertOk();
        $response->assertSee(route('contact.submit'), false);
        $response->assertSee('name="name"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="subject"', false);
        $response->assertSee('name="message"', false);
        // CSRF token is present, so the form is not relying on an exemption.
        $response->assertSee('name="_token"', false);
    }

    public function test_03_configured_support_details_are_shown(): void
    {
        $this->configureSupport();

        $response = $this->get(route('contact'));

        $response->assertOk();
        $response->assertSee('support@example.test', false);
        $response->assertSee('Customer Support', false);
    }

    public function test_04_missing_support_configuration_is_stated_honestly(): void
    {
        config()->set('contact.support.email', null);
        config()->set('contact.support.name', null);
        config()->set('home.support.email', null);

        $response = $this->get(route('contact'));

        $response->assertOk();
        $response->assertSee(trans('contact.support_unavailable'), false);
        // No invented address of any shape.
        $response->assertDontSee('@example.com', false);
        $response->assertDontSee('support@', false);
    }

    // =====================================================================
    // 5-10  Server-side validation
    // =====================================================================

    public function test_05_name_is_required(): void
    {
        $this->post(route('contact.submit'), $this->validPayload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, ContactMessage::query()->count());
    }

    public function test_06_email_is_required(): void
    {
        $this->post(route('contact.submit'), $this->validPayload(['email' => '']))
            ->assertSessionHasErrors('email');
    }

    public function test_07_an_invalid_email_is_rejected(): void
    {
        $this->post(route('contact.submit'), $this->validPayload(['email' => 'not-an-address']))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, ContactMessage::query()->count());
    }

    public function test_08_subject_is_required(): void
    {
        $this->post(route('contact.submit'), $this->validPayload(['subject' => '']))
            ->assertSessionHasErrors('subject');
    }

    public function test_09_message_is_required(): void
    {
        $this->post(route('contact.submit'), $this->validPayload(['message' => '']))
            ->assertSessionHasErrors('message');
    }

    public function test_10_maximum_lengths_are_enforced_server_side(): void
    {
        // maxlength in the markup is absent from a request that did not come
        // from the form, so the validator is what actually holds.
        $this->post(route('contact.submit'), $this->validPayload([
            'name' => str_repeat('a', (int) config('contact.limits.name') + 50),
        ]))->assertSessionHasErrors('name');

        $this->post(route('contact.submit'), $this->validPayload([
            'subject' => str_repeat('b', (int) config('contact.limits.subject') + 50),
        ]))->assertSessionHasErrors('subject');

        $this->post(route('contact.submit'), $this->validPayload([
            'message' => str_repeat('c', (int) config('contact.limits.message') + 500),
        ]))->assertSessionHasErrors('message');

        $this->assertSame(0, ContactMessage::query()->count());
    }

    // =====================================================================
    // 11-14  Abuse control
    // =====================================================================

    public function test_11_the_honeypot_blocks_a_bot_without_telling_it(): void
    {
        $field = (string) config('contact.anti_spam.honeypot_field');

        $response = $this->post(route('contact.submit'), $this->validPayload([$field => 'http://spam.example.test']));

        // Indistinguishable from success on the wire: a bot learns nothing.
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // But it is filed as spam and nothing was delivered.
        $message = ContactMessage::query()->firstOrFail();
        $this->assertSame(ContactMessage::STATUS_SPAM, $message->status);
        $this->assertNotSame(ContactMessage::DELIVERY_SENT, $message->delivery_state);
        $this->assertSame(0, ContactMessageDelivery::query()->count());
    }

    public function test_12_the_per_minute_ceiling_is_enforced(): void
    {
        config()->set('contact.anti_spam.per_minute', 2);
        config()->set('contact.anti_spam.per_hour', 100);
        config()->set('contact.anti_spam.duplicate_window_seconds', 0);

        for ($i = 0; $i < 2; $i++) {
            $this->post(route('contact.submit'), $this->validPayload([
                'subject' => 'Question number '.$i,
                'message' => 'A distinct synthetic message number '.$i.' for the limiter.',
            ]));
        }

        $this->assertSame(2, ContactMessage::query()->count());

        $blocked = $this->post(route('contact.submit'), $this->validPayload([
            'subject' => 'Question number 3',
            'message' => 'A third distinct synthetic message that should be refused.',
        ]));

        // The ROUTE limiter refuses first, with 429, before validation or any
        // database work happens. That ordering is the point: the cheapest
        // possible refusal for the request that is costing the most.
        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertSame(2, ContactMessage::query()->count(), 'The third message should not have been stored.');
    }

    public function test_13_the_per_hour_ceiling_is_enforced(): void
    {
        config()->set('contact.anti_spam.per_minute', 100);
        config()->set('contact.anti_spam.per_hour', 2);
        config()->set('contact.anti_spam.duplicate_window_seconds', 0);

        for ($i = 0; $i < 2; $i++) {
            $this->post(route('contact.submit'), $this->validPayload([
                'subject' => 'Hourly question '.$i,
                'message' => 'A distinct synthetic hourly message number '.$i.'.',
            ]));
        }

        $third = $this->post(route('contact.submit'), $this->validPayload([
            'subject' => 'Hourly question 3',
            'message' => 'A third distinct synthetic hourly message.',
        ]));

        // An hourly ceiling BELOW the per-minute one must still bind. It did
        // not until PROMPT 10 removed a max() clamp that quietly raised it to
        // the per-minute figure.
        $this->assertSame(429, $third->getStatusCode());
        $this->assertSame(2, ContactMessage::query()->count());
    }

    public function test_14_a_double_submission_does_not_become_two_messages(): void
    {
        $payload = $this->validPayload();

        $first = $this->post(route('contact.submit'), $payload);
        $first->assertRedirect();

        $reference = session('contact_status')['reference'] ?? null;
        $this->assertIsString($reference);

        // The same message again, immediately: a double-click, a retry, a
        // reconnecting phone.
        $second = $this->post(route('contact.submit'), $payload);
        $second->assertRedirect();

        $this->assertSame(1, ContactMessage::query()->count());
        $this->assertSame($reference, session('contact_status')['reference'] ?? null);

        // A genuinely DIFFERENT message from the same visitor still gets
        // through - silencing that would be the worse bug.
        $this->post(route('contact.submit'), $this->validPayload([
            'subject' => 'A second, different question',
            'message' => 'This is a different synthetic message from the same visitor.',
        ]));

        $this->assertSame(2, ContactMessage::query()->count());
    }

    // =====================================================================
    // 15-19  Persistence and delivery honesty
    // =====================================================================

    public function test_15_a_valid_message_is_persisted(): void
    {
        $this->post(route('contact.submit'), $this->validPayload())->assertRedirect();

        $message = ContactMessage::query()->firstOrFail();

        $this->assertSame('Test Visitor', $message->name);
        $this->assertSame('tester@example.test', $message->email);
        $this->assertSame('Support question', $message->subject);
        $this->assertStringContainsString('synthetic test message', $message->message);
        $this->assertNotSame('', (string) $message->public_reference);
    }

    public function test_16_a_stored_message_starts_as_received(): void
    {
        $this->post(route('contact.submit'), $this->validPayload());

        $this->assertSame(ContactMessage::STATUS_RECEIVED, ContactMessage::query()->firstOrFail()->status);
    }

    public function test_17_an_unconfigured_provider_is_reported_honestly(): void
    {
        config()->set('contact.delivery.enabled', false);

        $this->post(route('contact.submit'), $this->validPayload());

        $message = ContactMessage::query()->firstOrFail();

        $this->assertSame(ContactMessage::DELIVERY_NOT_CONFIGURED, $message->delivery_state);
        // Stored is still stored.
        $this->assertSame(ContactMessage::STATUS_RECEIVED, $message->status);

        $state = session('contact_status')['state'] ?? null;
        $this->assertSame('received_not_configured', $state);

        // The page must NOT claim it was emailed.
        $page = $this->get(route('contact'));
        $page->assertOk();
        $page->assertDontSee(trans('contact.status.sent'), false);
        $page->assertSee(trans('contact.status.received_not_configured'), false);
    }

    public function test_18_sent_is_reported_only_when_the_provider_accepts(): void
    {
        Mail::fake();
        $this->configureSupport();
        config()->set('contact.delivery.enabled', true);
        config()->set('contact.delivery.from_address', 'noreply@example.test');
        config()->set('contact.delivery.from_name', 'Platform');

        $this->post(route('contact.submit'), $this->validPayload());

        $message = ContactMessage::query()->firstOrFail();

        $this->assertSame(ContactMessage::DELIVERY_SENT, $message->delivery_state);
        $this->assertSame('sent', session('contact_status')['state'] ?? null);

        $attempt = ContactMessageDelivery::query()->firstOrFail();
        $this->assertSame(ContactMessage::DELIVERY_SENT, $attempt->state);
        $this->assertNull($attempt->error_class);
    }

    public function test_19_a_failing_provider_is_reported_honestly_and_keeps_the_message(): void
    {
        $this->configureSupport();
        config()->set('contact.delivery.enabled', true);
        config()->set('contact.delivery.from_address', 'noreply@example.test');

        // A transport that always throws.
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('smtp://user:hunter2@mail.internal:25 refused'));

        $this->post(route('contact.submit'), $this->validPayload());

        $message = ContactMessage::query()->firstOrFail();

        // THE MESSAGE SURVIVED. An SMTP outage must not delete a support
        // request.
        $this->assertSame(ContactMessage::STATUS_RECEIVED, $message->status);
        $this->assertSame(ContactMessage::DELIVERY_FAILED, $message->delivery_state);
        $this->assertSame('received_delivery_failed', session('contact_status')['state'] ?? null);

        $attempt = ContactMessageDelivery::query()->firstOrFail();
        $this->assertSame('TRANSPORT_FAILURE', $attempt->error_code);
        $this->assertSame(\RuntimeException::class, $attempt->error_class);
    }

    // =====================================================================
    // 20-22  Nothing sensitive escapes
    // =====================================================================

    public function test_20_provider_credentials_never_reach_the_database_or_response(): void
    {
        $this->configureSupport();
        config()->set('contact.delivery.enabled', true);
        config()->set('contact.delivery.from_address', 'noreply@example.test');

        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('smtp://user:hunter2@mail.internal:25 refused'));

        $this->post(route('contact.submit'), $this->validPayload());

        $stored = ContactMessageDelivery::query()->get()->toJson();

        foreach (['hunter2', 'mail.internal', 'smtp://', 'refused'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }

        $page = $this->get(route('contact'));
        $page->assertOk();

        foreach (['hunter2', 'mail.internal', 'smtp://'] as $secret) {
            $page->assertDontSee($secret, false);
        }
    }

    public function test_21_a_raw_provider_exception_never_reaches_the_page(): void
    {
        $this->configureSupport();
        config()->set('contact.delivery.enabled', true);
        config()->set('contact.delivery.from_address', 'noreply@example.test');

        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('Connection could not be established with host'));

        $this->post(route('contact.submit'), $this->validPayload());

        $page = $this->get(route('contact'));
        $page->assertOk();
        $page->assertDontSee('Connection could not be established', false);
        $page->assertDontSee('RuntimeException', false);
        // Only the safe, translated wording.
        $page->assertSee(trans('contact.status.received_delivery_failed'), false);
    }

    public function test_22_no_ip_or_user_agent_is_stored_or_rendered(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'SyntheticAgent/1.0'])
            ->post(route('contact.submit'), $this->validPayload());

        $message = ContactMessage::query()->firstOrFail();

        // The columns do not exist, so nothing can hold them.
        $attributes = $message->getAttributes();
        $this->assertArrayNotHasKey('ip_address', $attributes);
        $this->assertArrayNotHasKey('user_agent', $attributes);

        $json = $message->toJson();
        $this->assertStringNotContainsString('203.0.113.9', $json);
        $this->assertStringNotContainsString('SyntheticAgent', $json);

        // The fingerprint is a hash, and it is not the address.
        $this->assertNotSame('203.0.113.9', $message->sender_fingerprint);
        $this->assertStringNotContainsString('203.0.113.9', (string) $message->sender_fingerprint);

        $page = $this->get(route('contact'));
        $page->assertOk();
        $page->assertDontSee('203.0.113.9', false);
        $page->assertDontSee((string) $message->sender_fingerprint, false);
    }

    // =====================================================================
    // 23-25  Injection and CSRF
    // =====================================================================

    public function test_23_user_content_is_escaped_when_rendered(): void
    {
        // Stored as typed, rendered as text. The page under test is the one a
        // visitor sees after submitting.
        $this->post(route('contact.submit'), $this->validPayload([
            'name' => 'Mallory <script>alert(1)</script>',
            'subject' => 'Bold <b>subject</b>',
        ]));

        $message = ContactMessage::query()->firstOrFail();

        // The value was not silently rewritten - escaping is a rendering
        // concern, not a storage one.
        $this->assertStringContainsString('<script>', $message->name);

        $page = $this->get(route('contact'));
        $page->assertOk();
        // Nothing renders the message back to the public page at all, which is
        // the strongest form of "not injectable".
        $page->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_24_crlf_header_injection_is_rejected(): void
    {
        foreach (['name', 'email', 'subject'] as $field) {
            ContactMessage::query()->delete();

            $this->post(route('contact.submit'), $this->validPayload([
                $field => "Legit\r\nBcc: attacker@example.test",
            ]))->assertSessionHasErrors($field);

            $this->assertSame(0, ContactMessage::query()->count(), $field.' accepted a CRLF payload');
        }
    }

    public function test_25_csrf_protection_is_active_on_the_post_route(): void
    {
        // The route lives in the web group, so the CSRF middleware applies.
        $route = Route::getRoutes()->getByName('contact.submit');
        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();
        $this->assertContains('web', $middleware);
        $this->assertContains('throttle:contact-submit', $middleware);

        // And the exemption list does not name it.
        $this->assertNotContains('contact', (array) config('session.csrf_exclude', []));
    }

    // =====================================================================
    // 26-32  Seams
    // =====================================================================

    public function test_26_guest_navigation_exposes_contact(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee(route('contact'), false);
        $response->assertSee('Contact Us', false);
    }

    public function test_27_national_lottery_remains_in_the_navigation(): void
    {
        $this->get('/')->assertOk()->assertSee(route('national-lottery.index'), false);
    }

    public function test_28_weekly_lottery_remains_in_the_navigation(): void
    {
        $this->get('/')->assertOk()->assertSee(route('weekly-lottery.index'), false);
    }

    public function test_29_mega_lottery_remains_in_the_navigation(): void
    {
        $this->get('/')->assertOk()->assertSee(route('bingo-lottery.index'), false);
    }

    public function test_30_pcso_lottery_remains_in_the_navigation(): void
    {
        $this->get('/')->assertOk()->assertSee(route('pcso-lottery.index'), false);
    }

    public function test_31_useful_links_all_resolve(): void
    {
        $response = $this->get(route('contact'));
        $response->assertOk();

        foreach ([
            'home', 'about', 'vision', 'fees', 'results.index',
            'national-lottery.index', 'weekly-lottery.index',
            'bingo-lottery.index', 'pcso-lottery.index', 'terms',
        ] as $name) {
            $this->assertTrue(Route::has($name), $name.' is referenced by the useful-links component');
            $response->assertSee(route($name), false);
        }
    }

    public function test_32_contact_stays_crawlable(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertDoesNotMatchRegularExpression('/^Disallow:\s*\/contact/m', $robots);

        $this->get(route('contact'))->assertOk()->assertSee('index,follow', false);
    }

    // =====================================================================
    // 33-38  Honesty, privacy, retention, parity
    // =====================================================================

    public function test_33_no_competitor_string_appears_in_the_contact_surface(): void
    {
        $files = array_merge(
            glob(app_path('Services/Support/Contact*.php')) ?: [],
            glob(resource_path('views/contact/*.blade.php')) ?: [],
            glob(resource_path('views/components/contact/*.blade.php')) ?: [],
            [
                config_path('contact.php'),
                lang_path('en/contact.php'),
                lang_path('th/contact.php'),
                app_path('Http/Controllers/ContactController.php'),
                app_path('Http/Requests/ContactMessageRequest.php'),
                resource_path('css/contact.css'),
                resource_path('js/contact.js'),
            ],
        );

        foreach ($files as $file) {
            $contents = (string) file_get_contents((string) $file);

            foreach (['thailotto', 'thailotto.club', 'support@thailotto.club'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $contents,
                    basename((string) $file).' must not reference a competitor',
                );
            }
        }

        $this->get(route('contact'))->assertOk()->assertDontSee('thailotto', false);
    }

    public function test_34_no_government_endorsement_is_claimed(): void
    {
        $response = $this->get(route('contact'));
        $response->assertOk();

        foreach ([
            'Government Lottery Office',
            'official government support',
            'government-approved',
            'officially licensed',
        ] as $claim) {
            $response->assertDontSee($claim, false);
        }

        foreach ([lang_path('en/contact.php'), lang_path('th/contact.php'), config_path('contact.php')] as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('Government Lottery Office', $contents);
            $this->assertStringNotContainsString('government-approved', $contents);
        }
    }

    public function test_35_no_internal_filesystem_path_is_rendered(): void
    {
        $this->post(route('contact.submit'), $this->validPayload());

        $page = $this->get(route('contact'));
        $page->assertOk();
        $page->assertDontSee('/home/', false);
        $page->assertDontSee('/Users/', false);
        $page->assertDontSee('vendor/laravel', false);
    }

    public function test_36_retention_leaves_active_messages_intact(): void
    {
        $old = ContactMessage::query()->create([
            'name' => 'Old Visitor',
            'email' => 'old@example.test',
            'subject' => 'Old resolved question',
            'message' => 'A synthetic resolved message.',
            'status' => ContactMessage::STATUS_RESOLVED,
            'delivery_state' => ContactMessage::DELIVERY_NOT_CONFIGURED,
        ]);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();

        $activeOld = ContactMessage::query()->create([
            'name' => 'Waiting Visitor',
            'email' => 'waiting@example.test',
            'subject' => 'Still unanswered',
            'message' => 'A synthetic message nobody has answered yet.',
            'status' => ContactMessage::STATUS_RECEIVED,
            'delivery_state' => ContactMessage::DELIVERY_NOT_CONFIGURED,
        ]);
        $activeOld->forceFill(['created_at' => now()->subDays(400)])->save();

        $this->artisan('contact:purge')->assertExitCode(0);

        // The resolved one is gone; the unanswered one is NOT, however old.
        $this->assertNull(ContactMessage::query()->find($old->getKey()));
        $this->assertNotNull(ContactMessage::query()->find($activeOld->getKey()));

        // Idempotent: a second run removes nothing further.
        $before = ContactMessage::query()->count();
        $this->artisan('contact:purge')->assertExitCode(0);
        $this->assertSame($before, ContactMessage::query()->count());
    }

    public function test_37_english_and_thai_keys_are_identical(): void
    {
        $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
            $keys = [];

            foreach ($array as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                $keys = is_array($value)
                    ? array_merge($keys, $flatten($value, $path))
                    : array_merge($keys, [$path]);
            }

            return $keys;
        };

        $en = $flatten(require lang_path('en/contact.php'));
        $th = $flatten(require lang_path('th/contact.php'));

        sort($en);
        sort($th);

        $this->assertSame($en, $th);
    }

    public function test_38_no_private_operator_address_leaks(): void
    {
        $this->configureSupport();
        // A separate internal address that must never surface.
        config()->set('mail.from.address', 'ops-internal@example.test');

        $response = $this->get(route('contact'));

        $response->assertOk();
        $response->assertDontSee('ops-internal@example.test', false);
        // The public identity is fine to show.
        $response->assertSee('support@example.test', false);
    }

    // =====================================================================
    // 39-40  Limiter registration and transactional consistency
    // =====================================================================

    public function test_39_the_submit_route_is_rate_limited(): void
    {
        $route = Route::getRoutes()->getByName('contact.submit');

        $this->assertNotNull($route);
        $this->assertContains('throttle:contact-submit', $route->gatherMiddleware());

        // The limiter really exists rather than being a name nobody registered.
        $this->assertNotNull(RateLimiter::limiter('contact-submit'));
    }

    public function test_40_a_delivery_failure_leaves_exactly_one_consistent_record(): void
    {
        $this->configureSupport();
        config()->set('contact.delivery.enabled', true);
        config()->set('contact.delivery.from_address', 'noreply@example.test');

        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('transport exploded'));

        $this->post(route('contact.submit'), $this->validPayload());

        // One message, one attempt, and the two agree about the state.
        $this->assertSame(1, ContactMessage::query()->count());
        $this->assertSame(1, ContactMessageDelivery::query()->count());

        $message = ContactMessage::query()->firstOrFail();
        $attempt = ContactMessageDelivery::query()->firstOrFail();

        $this->assertSame($message->getKey(), $attempt->contact_message_id);
        $this->assertSame($message->delivery_state, $attempt->state);
        $this->assertSame(ContactMessage::DELIVERY_FAILED, $message->delivery_state);
    }

    // =====================================================================
    // 42-44  Concurrency
    //
    // The pre-insert SELECT is a fast path, not a guarantee: two requests
    // arriving together both find nothing and both try to write. These tests
    // model that interleaving by removing the SELECT, so what is left is the
    // only thing that can actually settle the race - the UNIQUE index.
    // =====================================================================

    /**
     * A spam service whose duplicate lookup always misses.
     *
     * This is exactly what both requests see when they run the SELECT at the
     * same instant, before either has committed.
     */
    private function blindSpamService(): ContactSpamProtectionService
    {
        return new class(app('config'), app(ContactPrivacyService::class)) extends ContactSpamProtectionService
        {
            public function findRecentDuplicate(string $contentFingerprint): ?ContactMessage
            {
                return null;
            }
        };
    }

    public function test_42_two_concurrent_identical_submissions_create_one_record(): void
    {
        $this->app->instance(ContactSpamProtectionService::class, $this->blindSpamService());

        $service = $this->app->make(ContactMessageService::class);
        $request = Request::create('/contact', 'POST');

        $payload = [
            'name' => 'Race Visitor',
            'email' => 'racer@example.test',
            'subject' => 'Concurrent question',
            'message' => 'Two requests carrying exactly this body arrive together.',
        ];

        $first = $service->submit($payload, $request);
        $second = $service->submit($payload, $request);

        // One record, whichever request won.
        $this->assertSame(1, ContactMessage::query()->count());

        $this->assertSame(ContactMessageService::OUTCOME_RECEIVED, $first['outcome']);
        $this->assertSame(ContactMessageService::OUTCOME_DUPLICATE, $second['outcome']);

        // The loser is told the truth and gets the SAME reference, so the
        // visitor behind it sees a successful submission rather than an error
        // for a message that really was received.
        $this->assertSame($first['reference'], $second['reference']);
    }

    public function test_43_the_loser_of_the_race_does_not_trigger_a_second_delivery(): void
    {
        Mail::fake();
        $this->configureSupport();
        config()->set('contact.delivery.enabled', true);
        config()->set('contact.delivery.from_address', 'noreply@example.test');

        $this->app->instance(ContactSpamProtectionService::class, $this->blindSpamService());

        $service = $this->app->make(ContactMessageService::class);
        $request = Request::create('/contact', 'POST');

        $payload = [
            'name' => 'Race Visitor',
            'email' => 'racer@example.test',
            'subject' => 'Concurrent delivery question',
            'message' => 'Two identical requests must not send two emails.',
        ];

        $service->submit($payload, $request);
        $service->submit($payload, $request);

        // One message, one delivery attempt. A second attempt would mean the
        // support inbox received the same enquiry twice.
        $this->assertSame(1, ContactMessage::query()->count());
        $this->assertSame(1, ContactMessageDelivery::query()->count());

        $message = ContactMessage::query()->firstOrFail();
        $attempt = ContactMessageDelivery::query()->firstOrFail();

        $this->assertSame(ContactMessage::DELIVERY_SENT, $message->delivery_state);
        $this->assertSame($message->delivery_state, $attempt->state);
    }

    public function test_44_the_database_constraint_is_what_enforces_it(): void
    {
        // Stated as a schema fact rather than trusted from the service, so
        // removing the index fails this test even if the SELECT still hides
        // the problem in every sequential scenario.
        $fingerprint = app(ContactPrivacyService::class)
            ->contentFingerprint(null, 'racer@example.test', 'Subject', 'Body text for the constraint.');

        $this->assertIsString($fingerprint, 'Deduplication must produce a fingerprint with the default window.');

        $row = [
            'name' => 'Race Visitor',
            'email' => 'racer@example.test',
            'subject' => 'Subject',
            'message' => 'Body text for the constraint.',
            'status' => ContactMessage::STATUS_RECEIVED,
            'delivery_state' => ContactMessage::DELIVERY_NOT_ATTEMPTED,
            'content_fingerprint' => $fingerprint,
        ];

        ContactMessage::query()->create($row);

        $this->expectException(UniqueConstraintViolationException::class);

        ContactMessage::query()->create($row);
    }

    public function test_45_a_disabled_duplicate_window_stores_no_fingerprint(): void
    {
        // With deduplication switched off the unique index must not bind at
        // all, or an operator turning the window off would instead get a hard
        // rejection of every repeated message.
        config()->set('contact.anti_spam.duplicate_window_seconds', 0);

        $fingerprint = app(ContactPrivacyService::class)
            ->contentFingerprint(null, 'racer@example.test', 'Subject', 'Body');

        $this->assertNull($fingerprint);

        $this->post(route('contact.submit'), $this->validPayload());
        $this->post(route('contact.submit'), $this->validPayload([
            'subject' => 'A different subject entirely',
            'message' => 'A different synthetic body so the limiter is not the thing under test.',
        ]));

        $this->assertSame(2, ContactMessage::query()->count());
        $this->assertNull(ContactMessage::query()->first()->content_fingerprint);
    }

    public function test_41_the_delivery_service_reports_configuration_without_secrets(): void
    {
        config()->set('contact.delivery.enabled', false);

        $status = app(ContactDeliveryService::class)->status();

        $this->assertSame(ContactMessage::DELIVERY_NOT_CONFIGURED, $status['state']);
        $this->assertFalse(app(ContactDeliveryService::class)->isConfigured());

        // The status block carries booleans, not addresses.
        $this->assertSame(['state', 'provider', 'destination_configured', 'from_configured'], array_keys($status));
    }
}
