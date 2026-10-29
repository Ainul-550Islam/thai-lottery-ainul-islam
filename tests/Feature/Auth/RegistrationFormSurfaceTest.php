<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Services\Legal\LegalDocumentService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The actual registration form must supply the independent server-required proofs. */
final class RegistrationFormSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_displays_separate_unchecked_consent_and_current_document_snapshots(): void
    {
        $html = (string) $this->get(route('register'))->assertOk()->getContent();
        $xpath = $this->document($html);

        foreach (['age_attestation', 'terms', 'privacy_notice'] as $name) {
            $input = $this->input($xpath, $name);
            $this->assertSame('checkbox', $input->getAttribute('type'));
            $this->assertTrue($input->hasAttribute('required'), $name.' must be explicit.');
            $this->assertFalse($input->hasAttribute('checked'), $name.' must not be preselected.');
            $this->assertSame(1, $xpath->query('//label[@for="'.$name.'"]')->length);
        }

        $documents = app(LegalDocumentService::class);
        foreach (['terms' => 'terms', 'privacy' => 'privacy'] as $field => $type) {
            $document = $documents->currentData($type, (string) app()->getLocale());
            $this->assertSame('AVAILABLE', $document['status']);
            foreach (['version' => 'version', 'hash' => 'content_hash'] as $suffix => $key) {
                $input = $this->input($xpath, $field.'_'.$suffix);
                $this->assertSame('hidden', $input->getAttribute('type'));
                $this->assertSame((string) $document[$key], $input->getAttribute('value'));
            }
        }
        $this->assertSame(1, $xpath->query('//form[@method="POST"]//a[@href="'.route('terms').'"]')->length);
        $this->assertSame(1, $xpath->query('//form[@method="POST"]//a[@href="'.route('privacy').'"]')->length);
    }

    public function test_enabled_captcha_is_present_on_the_registration_form(): void
    {
        config([
            'auth_security.captcha.enabled' => true,
            'auth_security.captcha.register' => true,
            'auth_security.captcha.driver' => 'fixed',
        ]);

        $xpath = $this->document((string) $this->get(route('register'))->assertOk()->getContent());
        $this->assertNotSame('', $this->input($xpath, 'captcha_token')->getAttribute('value'));
        $this->assertTrue($this->input($xpath, 'captcha_answer')->hasAttribute('required'));
    }

    public function test_unavailable_legal_documents_disable_submission(): void
    {
        // Exercise the real degraded legal-page path rather than attempting to
        // mock the final document service. A cache outage makes both public
        // documents UNAVAILABLE and registration must fail closed.
        config(['public_pages.cache_ttl_seconds' => 300]);
        $realCache = app('cache');
        \Illuminate\Support\Facades\Cache::shouldReceive('driver')
            ->andReturnUsing(static fn (...$arguments) => $realCache->driver(...$arguments));
        \Illuminate\Support\Facades\Cache::shouldReceive('remember')
            ->twice()->andThrow(new \RuntimeException('synthetic cache outage'));

        $xpath = $this->document((string) $this->get(route('register'))->assertOk()->getContent());
        $buttons = $xpath->query('//form[@method="POST"]//button[@type="submit"]');
        $this->assertSame(1, $buttons->length);
        $this->assertTrue($buttons->item(0)->hasAttribute('disabled'));
    }

    private function document(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $this->assertTrue($document->loadHTML($html));

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function input(DOMXPath $xpath, string $name): DOMElement
    {
        $nodes = $xpath->query('//form[@method="POST"]//input[@name="'.$name.'"]');
        $this->assertSame(1, $nodes->length, 'Expected exactly one '.$name.' form input.');
        $input = $nodes->item(0);
        $this->assertInstanceOf(DOMElement::class, $input);

        return $input;
    }
}
