<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use App\Models\Chat\ChatCannedResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The app's only locale decision: App\Http\Middleware\SetLocale.
 *
 * Nothing tested this surface before this file existed - not one test in the repo
 * mentioned SetLocale, ?lang= or ?locale= - which is how three of its four documented
 * lanes could be wrong at once: the session lane could not run at all (the middleware
 * was in the global stack, before StartSession), the Accept-Language lane did not
 * exist while PublicLegalHeaders advertised "Vary: Accept-Language", and the cookie
 * lane could never fire because EncryptCookies nulls a cookie it cannot decrypt.
 *
 * Each request here is measured from a known container locale, because a feature test
 * reuses one application instance: app()->setLocale() from an earlier request in the
 * same test would otherwise be credited to the middleware. That leak is also why the
 * persistence test asserts on the session and the rendered body, never on
 * app()->getLocale() - an in-process check can prove the write, not the read-back.
 */
final class LocaleNegotiationTest extends TestCase
{
    use RefreshDatabase;

    private string $baseline;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite(); // asserts page content; public/build/manifest.json is a build artefact

        $this->baseline = (string) config('app.locale');
    }

    public function test_the_lang_query_param_switches_the_locale(): void
    {
        $other = $this->other();

        // The baseline check has to come first in its own test: an accepted ?lang= also
        // writes the session, and a test that switched once would then measure its own
        // residue. That is the persistence behaviour, not a bug - but it is why these
        // two spellings are separate tests instead of one loop.
        $this->assertSame($this->baseline, $this->htmlLanguage($this->request('/about')));
        $this->assertSame($other, $this->htmlLanguage($this->request('/about?lang='.$other)),
            '?lang= was not honoured, so the lane SetLocale documents does not exist');
    }

    public function test_the_locale_query_param_still_switches_the_locale(): void
    {
        $other = $this->other();

        $this->assertSame($this->baseline, $this->htmlLanguage($this->request('/about')));
        $this->assertSame($other, $this->htmlLanguage($this->request('/about?locale='.$other)),
            '?locale= was not honoured; ten controllers used to read it themselves, so it cannot be dropped');
    }

    public function test_an_explicit_choice_is_written_to_the_session_and_read_back(): void
    {
        $other = $this->other();

        // The write: only possible if the middleware runs after StartSession, which is
        // what the group registration is for. This is the assertion that fails when
        // SetLocale is put back on the global stack.
        $this->request("/about?lang=$other")->assertSessionHas('locale', $other);

        // The read-back: the container is forced back to the baseline first, so a
        // surviving locale can only come from the session.
        $this->assertSame($other, $this->htmlLanguage($this->request('/terms')));
    }

    public function test_a_persisted_choice_survives_a_request_asking_for_another_language(): void
    {
        // A returning visitor whose session carries a choice must not be reset to
        // whatever their browser sends: the explicit choice outranks the inferred one.
        // Established with a real first request rather than by writing to the store, so
        // what is asserted is exactly the read-back path. (The header cannot compete:
        // see the pair pinned further down.)
        $chosen = $this->other();
        $this->request('/about?lang='.$chosen);

        $contradicts = $this->baseline === 'th' ? 'en' : 'fr';
        $this->assertSame($chosen, $this->htmlLanguage($this->request('/terms', ['Accept-Language' => $contradicts])),
            'the session lane lost to Accept-Language');
    }

    public function test_the_browser_header_is_ignored_and_the_cache_header_says_so(): void
    {
        // Pinned as a pair on purpose. SetLocale does not consult Accept-Language (the
        // class header explains the fabricated 'en' that makes it harmful), and
        // PublicLegalHeaders therefore must not list Accept-Language in Vary: a cache
        // fragmenting entries on a signal the application ignores buys nothing but misses.
        // If a real negotiation lane is ever added, both halves must change together and
        // this test is where that requirement is written down.
        foreach (['th-TH,th;q=0.9', 'th', 'fr-FR,fr', '*/*', 'de,th;q=0.9,en;q=0.8'] as $header) {
            $this->assertSame($this->baseline, $this->htmlLanguage($this->request('/about', ['Accept-Language' => $header])),
                $header.' changed the locale, so SetLocale now negotiates it - update PublicLegalHeaders too');
        }

        $vary = (string) $this->request('/terms')->headers->get('Vary');
        $this->assertStringContainsString('Cookie', $vary);
        $this->assertStringNotContainsString('Accept-Language', $vary,
            'the cache is told to fragment on a header nothing reads');
    }

    public function test_an_explicit_choice_overrides_a_stale_session_value(): void
    {
        // Lane 1 over lane 2: the session holds 'th' from the request below, and an
        // explicit ?lang= on the next request must win rather than be ignored.
        $chosen = $this->other();
        $this->request('/about?lang='.$chosen);
        $this->assertSame($chosen, $this->app['session']->get('locale'));
        $this->assertSame($this->baseline, $this->htmlLanguage($this->request('/terms?lang='.$this->baseline)),
            'a stale session value outranked an explicit choice');
    }

    public function test_values_this_app_cannot_serve_are_ignored_rather_than_trusted(): void
    {
        foreach (['xx', 'TH', 'th;DROP', '../../.env', ''] as $bad) {
            $response = $this->request('/about?lang='.rawurlencode($bad));

            $this->assertSame($this->baseline, $this->htmlLanguage($response), "lang=$bad was accepted");
            $response->assertStatus(200);
            $this->assertArrayNotHasKey('locale', $this->app['session']->all());
        }

        // The cookie lane is gone because it could never fire: EncryptCookies nulls a
        // cookie this app did not encrypt, and this app writes no locale cookie.
        // No withCookieVariables() helper exists; call() takes the cookie bag directly,
        // which is what a browser sending a plaintext "locale" cookie looks like.
        $withCookie = $this->call('GET', '/about', [], ['locale' => 'th']);
        $this->assertSame($this->baseline, $this->htmlLanguage($withCookie),
            'a plaintext locale cookie was honoured - the lane was documented as unreachable');
    }

    public function test_no_controller_negotiates_the_locale_for_itself(): void
    {
        // Nine public controllers each carried a byte-identical private copy of this
        // rule, reading ?locale= and ignoring ?lang=, the session and the header. None
        // of them could change what was rendered (measured: /privacy?lang=en&locale=th
        // and /privacy?lang=en came back identical), so they were dead weight with one
        // job: to drift. The locale is negotiated once, in the middleware.
        $offenders = [];

        // File::allFiles is recursive; glob('*/*') would have missed the nested
        // controllers, which is where one of the ten copies actually lived.
        foreach (File::allFiles(base_path('app/Http/Controllers')) as $file) {
            $src = (string) file_get_contents($file->getPathname());
            if (str_contains($src, "query('locale'") || str_contains($src, "query('lang'")) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders,
            'these controllers re-read the locale from the query instead of trusting the negotiation: '
            .implode(', ', $offenders));
    }

    public function test_the_middleware_is_registered_in_the_groups_not_globally(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString("\$middleware->appendToGroup('web', SetLocale::class)", $bootstrap);
        $this->assertStringContainsString("\$middleware->appendToGroup('api', SetLocale::class)", $bootstrap);
        $this->assertStringNotContainsString('$middleware->append(SetLocale::class)', $bootstrap,
            'the global stack runs before StartSession, where the session lane is impossible');
    }

    public function test_the_operator_canned_response_picker_follows_the_negotiated_locale(): void
    {
        // The picker is fetched from route('operator.chat.canned') with no query string
        // at all (resources/views/chat/operator.blade.php:24), so the controller used to
        // pass a literal 'en': a Thai operator was handed the English list of canned
        // replies even though Thai rows existed.
        ChatCannedResponse::create(['key' => 'th-welcome', 'title' => 'Thai greeting', 'body' => 'Sawadee', 'locale' => 'th']);
        ChatCannedResponse::create(['key' => 'en-welcome', 'title' => 'English greeting', 'body' => 'Hello', 'locale' => 'en']);

        $operator = $this->makeOperator();

        $thai = $this->actingAs($operator)->getJson(route('operator.chat.canned', ['lang' => 'th']))->assertOk()->json();
        $titles = $this->pickerTitles($thai);
        $this->assertContains('Thai greeting', $titles);
        $this->assertNotContains('English greeting', $titles);

        // And with nothing in the URL: the operator's own session decides.
        $this->app['session']->put('locale', $this->baseline);
        $plain = $this->actingAs($operator)->getJson(route('operator.chat.canned'))->assertOk()->json();
        $titles = $this->pickerTitles($plain);
        $this->assertContains($this->baseline === 'th' ? 'Thai greeting' : 'English greeting', $titles);
        $this->assertNotContains($this->baseline === 'th' ? 'English greeting' : 'Thai greeting', $titles);
    }

    /**
     * @return array<int, string>
     */
    private function pickerTitles(array $payload): array
    {
        // Measured shape: {"status":"OK","groups":{"locale":…,"categories":[{"responses":[…]}]}}
        $categories = $payload['groups']['categories'] ?? [];

        $titles = [];
        foreach ($categories as $group) {
            foreach (($group['responses'] ?? []) as $row) {
                $titles[] = (string) ($row['title'] ?? '');
            }
        }

        $this->assertNotEmpty($titles, 'the picker payload shape changed: '.substr(json_encode($payload), 0, 220));

        return $titles;
    }

    private function makeOperator(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('admin', 'web');

        foreach (['view dashboard', 'chat.view', 'chat.reply', 'chat.manage_canned'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('view dashboard', 'chat.view');

        return $user;
    }

    /**
     * One request, negotiated from the configured default so nothing earlier in the
     * test can be mistaken for the middleware doing its job.
     */
    private function request(string $uri, array $headers = []): TestResponse
    {
        $this->app->setLocale($this->baseline);

        $response = $this->withHeaders($headers)->get($uri);
        $response->assertOk();

        return $response;
    }

    private function htmlLanguage(TestResponse $response): string
    {
        preg_match('/<html lang="([^"]*)"/', (string) $response->getContent(), $m);

        $this->assertArrayHasKey(1, $m, 'the page no longer states its language on <html>, so this test has no signal');

        return (string) $m[1];
    }

    private function other(): string
    {
        return $this->baseline === 'th' ? 'en' : 'th';
    }
}
