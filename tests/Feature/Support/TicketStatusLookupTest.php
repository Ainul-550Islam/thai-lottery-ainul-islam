<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ContactMessage;
use App\Services\Support\TicketStatusLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The public "where is my ticket?" lookup.
 *
 * THE ENTIRE SUITE IS ABOUT INDISTINGUISHABILITY. A helpful error message here
 * ("no ticket with that reference", "that address does not match") would turn a
 * support form into a tool for confirming whether a reference exists and whether
 * an address belongs to it. So the tests do not check that the feature works in
 * the happy path only - they check that the sad paths are byte-identical and
 * shape-identical to it.
 */
final class TicketStatusLookupTest extends TestCase
{
    use RefreshDatabase;

    private const OFF_BODY = [
        'success' => false,
        'state' => 'NOT_CONFIGURED',
        'message' => 'Public ticket-status lookup is not configured. Use the reference shown by the canonical contact submission workflow.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function enableLane(): void
    {
        config()->set('support_tickets.status_lookup_enabled', true);
        config()->set('support_tickets.rate_limits.lookup_per_minute', 100);
        config()->set('support_tickets.rate_limits.lookup_per_hour', 500);
    }

    private function ticket(array $overrides = []): ContactMessage
    {
        return ContactMessage::query()->create(array_merge([
            'public_reference' => 'TCK-'.strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Ticket Holder',
            'email' => 'ticket.holder@example.test',
            'subject' => 'A private subject line',
            'message' => 'A private message body',
            'status' => ContactMessage::STATUS_RECEIVED,
            'delivery_state' => ContactMessage::DELIVERY_SENT,
            'locale' => 'en',
        ], $overrides));
    }

    // =====================================================================
    // OFF
    // =====================================================================

    public function test_01_the_endpoint_keeps_its_exact_501_body_while_off(): void
    {
        $ticket = $this->ticket();

        $this->postJson('/api/v1/public/contact/status', [
            'reference' => $ticket->public_reference,
            'email' => (string) $ticket->email,
        ])
            ->assertStatus(501)
            ->assertExactJson(self::OFF_BODY);
    }

    public function test_02_off_answers_501_even_for_a_malformed_request(): void
    {
        $this->postJson('/api/v1/public/contact/status', ['reference' => '!!'])
            ->assertStatus(501)
            ->assertExactJson(self::OFF_BODY);
    }

    // =====================================================================
    // ON
    // =====================================================================

    public function test_03_a_matching_reference_and_address_returns_only_the_public_fields(): void
    {
        $this->enableLane();
        $ticket = $this->ticket();

        $response = $this->postJson('/api/v1/public/contact/status', [
            'reference' => (string) $ticket->public_reference,
            'email' => (string) $ticket->email,
        ])->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('state', 'MATCHED')
            ->assertJsonPath('data.reference', $ticket->public_reference)
            ->assertJsonPath('data.delivery_acknowledged', true);

        $payload = (string) $response->getContent();

        // NOTHING ELSE LEAVES: not the subject, not the body, not the name, not
        // the internal reference numbers.
        $this->assertStringNotContainsString('A private subject line', $payload);
        $this->assertStringNotContainsString('A private message body', $payload);
        $this->assertStringNotContainsString('Ticket Holder', $payload);

        $this->assertSame(
            ['success', 'state', 'data', 'message'],
            array_keys($response->json()),
        );
        $this->assertSame(
            ['reference', 'status', 'status_label', 'last_updated_at', 'delivery_acknowledged'],
            array_keys($response->json('data')),
        );
    }

    public function test_04_a_wrong_address_and_an_unknown_reference_are_indistinguishable(): void
    {
        $this->enableLane();
        $ticket = $this->ticket();

        $wrongAddress = $this->postJson('/api/v1/public/contact/status', [
            'reference' => (string) $ticket->public_reference,
            'email' => 'somebody.else@example.test',
        ])->assertOk();

        $unknownReference = $this->postJson('/api/v1/public/contact/status', [
            'reference' => 'TCK-NOSUCH01',
            'email' => (string) $ticket->email,
        ])->assertOk();

        $this->assertSame($wrongAddress->status(), $unknownReference->status());
        $this->assertSame(
            array_keys($wrongAddress->json('data')),
            array_keys($unknownReference->json('data')),
        );

        // Both are neutral: no status is disclosed in either case.
        $this->assertNull($wrongAddress->json('data.status'));
        $this->assertNull($unknownReference->json('data.status'));
        $this->assertSame('NEUTRAL', $wrongAddress->json('state'));
        $this->assertSame('NEUTRAL', $unknownReference->json('state'));

        // And the same is true of the whole answer, not merely the data block.
        $this->assertSame(
            $wrongAddress->json('state'),
            $unknownReference->json('state'),
        );
    }

    public function test_05_the_matched_and_neutral_answers_have_the_same_keys(): void
    {
        $this->enableLane();
        $ticket = $this->ticket();

        $matched = $this->postJson('/api/v1/public/contact/status', [
            'reference' => (string) $ticket->public_reference,
            'email' => (string) $ticket->email,
        ])->assertOk();

        $neutral = $this->postJson('/api/v1/public/contact/status', [
            'reference' => 'TCK-NOSUCH02',
            'email' => 'nobody@example.test',
        ])->assertOk();

        $this->assertSame(array_keys($matched->json('data')), array_keys($neutral->json('data')));
    }

    public function test_06_the_address_comparison_is_case_insensitive_but_not_loose(): void
    {
        $this->enableLane();
        $ticket = $this->ticket();

        $this->postJson('/api/v1/public/contact/status', [
            'reference' => strtolower((string) $ticket->public_reference),
            'email' => 'TICKET.HOLDER@EXAMPLE.TEST',
        ])->assertOk()->assertJsonPath('state', 'MATCHED');

        $this->postJson('/api/v1/public/contact/status', [
            'reference' => (string) $ticket->public_reference,
            'email' => 'ticket.holder@example.test.evil.test',
        ])->assertOk()->assertJsonPath('state', 'NEUTRAL');
    }

    public function test_07_the_digest_is_stable_and_never_the_plain_address(): void
    {
        $digest = TicketStatusLookupService::digest('Someone@Example.TEST');

        $this->assertSame($digest, TicketStatusLookupService::digest('  someone@example.test '));
        $this->assertSame(64, strlen($digest));
        $this->assertStringNotContainsString('someone', $digest);
    }

    public function test_08_a_flood_from_one_connection_is_rate_limited(): void
    {
        $this->enableLane();
        config()->set('support_tickets.rate_limits.lookup_per_minute', 2);
        config()->set('support_tickets.rate_limits.lookup_per_hour', 2);

        $ticket = $this->ticket();
        $payload = ['reference' => (string) $ticket->public_reference, 'email' => 'nobody@example.test'];

        $this->postJson('/api/v1/public/contact/status', $payload)->assertOk();
        $this->postJson('/api/v1/public/contact/status', $payload)->assertOk();
        $this->postJson('/api/v1/public/contact/status', $payload)->assertStatus(429);
    }

    public function test_09_validation_failures_do_not_distinguish_their_cause(): void
    {
        $this->enableLane();

        $badReference = $this->postJson('/api/v1/public/contact/status', [
            'reference' => '$',
            'email' => 'valid@example.test',
        ])->assertStatus(422);

        $badEmail = $this->postJson('/api/v1/public/contact/status', [
            'reference' => 'TCK-ABCD1234',
            'email' => 'not-an-address',
        ])->assertStatus(422);

        // The project's ApiResponse envelope wraps validation failures, so the
        // field sentence is read from error.details.fields. Both causes must
        // carry the SAME sentence: a message that said which field was at fault
        // would tell a caller that a reference-shaped guess was otherwise valid.
        $this->assertSame(
            (string) trans('support_tickets.lookup.invalid'),
            (string) $badReference->json('error.details.fields.reference.0'),
        );
        $this->assertSame(
            (string) trans('support_tickets.lookup.invalid'),
            (string) $badEmail->json('error.details.fields.email.0'),
        );
        $this->assertSame($badReference->json('error.code'), $badEmail->json('error.code'));
    }

    public function test_10_every_public_status_has_a_translated_label(): void
    {
        $this->enableLane();

        foreach ([
            ContactMessage::STATUS_RECEIVED,
            ContactMessage::STATUS_IN_REVIEW,
            ContactMessage::STATUS_RESOLVED,
            ContactMessage::STATUS_SPAM,
            ContactMessage::STATUS_ARCHIVED,
        ] as $status) {
            $key = app(TicketStatusLookupService::class)->statusKeyFor($status);

            $this->assertNotNull($key, "The [{$status}] state has no public label key.");

            foreach (['en', 'th', 'bn'] as $locale) {
                $this->assertNotSame($key, (string) trans($key, [], $locale), "{$key} is untranslated in {$locale}.");
            }
        }

        // SPAM and ARCHIVED must read as closed, not as "spam": the visitor is
        // not told how their message was classified.
        $this->assertSame('support_tickets.status.closed', app(TicketStatusLookupService::class)->statusKeyFor(ContactMessage::STATUS_SPAM));
        $this->assertSame('support_tickets.status.closed', app(TicketStatusLookupService::class)->statusKeyFor(ContactMessage::STATUS_ARCHIVED));
    }
}
