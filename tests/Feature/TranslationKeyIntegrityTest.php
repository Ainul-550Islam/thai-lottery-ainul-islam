<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TRANSLATION-KEY INTEGRITY (audit v2, finding C2).
 *
 * The Fees page shipped with sixteen literal translation keys that existed
 * in no language file, so the rendered page showed raw key strings. This
 * suite makes that class of defect impossible to reintroduce silently:
 *
 *  1. every namespace referenced by a literal __()/trans()/Lang::get() call
 *     in the view, controller, service, rule, request or notification layer
 *     must exist as a lang file;
 *  2. every literal referenced key must exist in BOTH lang/en and lang/th;
 *  3. the EN and TH key sets must be exactly symmetric, per namespace.
 *
 * Dynamic keys (translated with a concatenated suffix, e.g.
 * trans('account_services.verification_document_type_'.$type)) are out of
 * scope here by construction: the matcher only accepts a complete literal
 * string and rejects one immediately followed by a concatenation dot.
 */
class TranslationKeyIntegrityTest extends TestCase
{
    /** Framework-owned namespaces that legitimately have no app lang file. */
    private const FRAMEWORK_NAMESPACES = ['validation', 'auth', 'passwords', 'pagination'];

    /**
     * @return array<string, array{string}>
     */
    public static function namespaceProvider(): array
    {
        $namespaces = [];
        foreach (glob(self::langDir('en').'/*.php') ?: [] as $file) {
            $namespaces[basename((string) $file, '.php')] = [basename((string) $file, '.php')];
        }

        return $namespaces;
    }

    /**
     * Framework-free lang path: data providers run before the application
     * boots, so no container-dependent helpers are used anywhere in this
     * suite. tests/Feature -> two levels up is the repository root.
     */
    private static function langDir(string $locale): string
    {
        return dirname(__DIR__, 2).'/lang/'.$locale;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $roots = [
            $root.'/resources/views',
            $root.'/app/Http/Controllers',
            $root.'/app/Services',
            $root.'/app/Rules',
            $root.'/app/Http/Requests',
            $root.'/app/Notifications',
            $root.'/app/Console/Commands',
        ];

        $files = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()
                    && in_array($file->getExtension(), ['php', 'blade.php', 'php'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * Flatten a lang array into dotted keys.
     *
     * @param  array<mixed> $array
     * @return array<string, true>
     */
    private function flatten(array $array, string $prefix = ''): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix.'.'.(string) $key;
            if (is_array($value)) {
                $out += $this->flatten($value, $dotted);
            } else {
                $out[$dotted] = true;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function langKeys(string $locale, string $namespace): array
    {
        $path = self::langDir($locale).'/'.$namespace.'.php';
        $loaded = is_file($path) ? include $path : [];

        return $this->flatten(is_array($loaded) ? $loaded : []);
    }

    public function test_every_literal_translation_reference_resolves_in_both_locales(): void
    {
        $namespaces = array_column(self::namespaceProvider(), 0);
        $langEn = [];
        $langTh = [];
        foreach ($namespaces as $ns) {
            $langEn[$ns] = $this->langKeys('en', $ns);
            $langTh[$ns] = $this->langKeys('th', $ns);
        }

        // Complete literal keys only: the negative lookahead rejects a
        // string immediately followed by a concatenation dot, which is the
        // dynamic-key pattern ('ns.prefix_' . $variable).
        $pattern = "/(?:__|trans|Lang::get)\(\s*['\\\"]([a-z0-9_]+)\.([A-Za-z0-9_]+)['\\\"](?!\s*\.)/u";

        $missing = [];
        $checked = 0;

        foreach ($this->sourceFiles() as $file) {
            $body = (string) file_get_contents($file);
            preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                [$all, $ns, $key] = $match;
                $checked++;

                if (in_array($ns, self::FRAMEWORK_NAMESPACES, true)) {
                    continue;
                }

                if (! in_array($ns, $namespaces, true)) {
                    $missing[] = sprintf('%s: reference to namespace "%s" which has no lang file (%s)', $file, $ns, $all);

                    continue;
                }

                if (! isset($langEn[$ns][$key])) {
                    $missing[] = sprintf('%s: %s missing from lang/en/%s.php', $file, $all, $ns);
                }

                if (! isset($langTh[$ns][$key])) {
                    $missing[] = sprintf('%s: %s missing from lang/th/%s.php', $file, $all, $ns);
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'The scanner unexpectedly found no translation references — the pattern is broken, not the codebase.');
        $this->assertSame([], $missing, sprintf(
            "%d literal translation references checked; %d unresolved:\n%s",
            $checked,
            count($missing),
            implode("\n", array_slice($missing, 0, 50))
        ));
    }

    #[DataProvider('namespaceProvider')]
    public function test_en_and_th_key_sets_are_exactly_symmetric(string $namespace): void
    {
        $en = $this->langKeys('en', $namespace);
        $th = $this->langKeys('th', $namespace);

        $enOnly = array_keys(array_diff_key($en, $th));
        $thOnly = array_keys(array_diff_key($th, $en));

        $this->assertSame([], $enOnly, "lang/en/{$namespace}.php has keys with no TH counterpart: ".implode(', ', $enOnly));
        $this->assertSame([], $thOnly, "lang/th/{$namespace}.php has keys with no EN counterpart: ".implode(', ', $thOnly));
    }

    public function test_every_configured_fee_category_has_a_translated_label_in_both_locales(): void
    {
        // The dynamic family the literal scanner cannot see: the Fees page
        // renders trans('account_services.fees_category_'.$key) for every
        // key under config('fees.categories'). Each one must exist in BOTH
        // locales, or the table shows a raw key string (audit v2 / C2).
        $categories = array_keys((array) config('fees.categories'));
        $this->assertNotSame([], $categories, 'config/fees.php unexpectedly exposes no fee categories — the guard is broken, not the codebase.');

        $en = $this->langKeys('en', 'account_services');
        $th = $this->langKeys('th', 'account_services');

        $missing = [];
        foreach ($categories as $key) {
            $dotted = 'fees_category_'.$key;
            if (! isset($en[$dotted])) {
                $missing[] = 'lang/en/account_services.php: '.$dotted;
            }
            if (! isset($th[$dotted])) {
                $missing[] = 'lang/th/account_services.php: '.$dotted;
            }
        }

        $this->assertSame([], $missing, "Fee category labels missing translations:
".implode("
", $missing));
    }

    public function test_the_fees_page_renders_no_raw_translation_keys(): void
    {
        // The C2 regression itself: the Fees page must not leak any
        // account_services.* / public_pages.* key string into its HTML.
        $response = $this->get(route('fees'));
        $response->assertOk();

        $html = (string) $response->getContent();

        foreach (['account_services.', 'public_pages.'] as $prefix) {
            $this->assertStringNotContainsString($prefix.'fees_', $html, "Raw translation key leaked onto the Fees page.");
        }

        // Spot-check a few of the sixteen restored keys render as copy.
        $this->assertStringContainsString('Fee Preview', $html);
        $this->assertStringContainsString('Preview fee', $html);
        $this->assertStringContainsString('Rule version', $html);
    }

    public function test_the_fees_page_renders_the_restored_keys_in_thai(): void
    {
        // The application locale is not negotiated from Accept-Language;
        // it is set directly, which is how the running app does it too.
        app()->setLocale('th');

        $response = $this->get(route('fees'));
        $response->assertOk();

        $html = (string) $response->getContent();
        $this->assertStringContainsString('ตัวอย่างการคำนวณค่าธรรมเนียม', $html);
        $this->assertStringNotContainsString('account_services.fees_', $html);
    }

    /**
     * FINAL AUDIT #13 acceptance: the authenticated player surface (the
     * newly localized views) renders no raw key strings in EN or TH.
     *
     * @return array<int, array{0: string}>
     */
    public static function playerPageProvider(): array
    {
        return [
            ['/dashboard'],
            ['/draws'],
            ['/bets'],
            ['/wallet'],
            ['/deposit'],
            ['/withdraw'],
            ['/profile'],
        ];
    }

    #[DataProvider('playerPageProvider')]
    public function test_player_pages_render_no_raw_translation_keys(string $path): void
    {
        $user = \App\Models\User::factory()->create();
        \App\Models\Wallet::factory()->for($user)->withBalance('1000.00')->create(['currency' => 'THB']);

        $response = $this->actingAs($user)->get($path);
        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('player.', $html, 'Raw player.* key leaked onto '.$path.'.');
        $this->assertStringNotContainsString('account_services.', $html, 'Raw account_services.* key leaked onto '.$path.'.');
    }

    public function test_player_pages_render_localized_copy_in_thai(): void
    {
        // The application locale is set directly (no Accept-Language
        // negotiation in this app).
        app()->setLocale('th');

        $user = \App\Models\User::factory()->create();
        \App\Models\Wallet::factory()->for($user)->withBalance('1000.00')->create(['currency' => 'THB']);

        $html = (string) $this->actingAs($user)->get('/wallet')->assertOk()->getContent();

        // Spot-check the newly localized wallet copy renders in Thai and
        // no raw key leaks through.
        $this->assertStringContainsString('กระเป๋าเงินและบัญชีแยกประเภท', $html);
        $this->assertStringNotContainsString('player.wallet_', $html);
    }
}
