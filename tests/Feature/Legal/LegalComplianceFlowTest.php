<?php

declare(strict_types=1);

namespace Tests\Feature\Legal;

use App\Models\AgeVerification;
use App\Models\User;
use App\Models\UserConsent;
use App\Services\Legal\LegalDocumentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class LegalComplianceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_terms_are_delivered_from_the_published_document(): void
    {
        $operator = User::factory()->create();
        $documents = app(LegalDocumentService::class);
        $content = $documents->template('terms', 'en');
        $content['title'] = 'Published Versioned Terms';
        $content['meta_title'] = 'Published Versioned Terms';
        $content['meta_description'] = 'A verified current Terms version for this test.';
        $content['sections'][0]['body'] = 'This is the current published terms body.';

        $draft = $documents->createDraft([
            'document_type' => 'terms',
            'locale' => 'en',
            'version' => 'test-terms-2',
            'effective_at' => today()->toDateString(),
            ...$content,
        ], $operator);
        $documents->publish((int) $draft->getKey(), $operator);

        $this->get('/terms')
            ->assertOk()
            ->assertSee('test-terms-2')
            ->assertSee('Published Versioned Terms')
            ->assertSee('This is the current published terms body.');

        $this->getJson('/api/v1/public/terms')
            ->assertOk()
            ->assertJsonPath('data.version', 'test-terms-2')
            ->assertJsonPath('data.title', 'Published Versioned Terms');
    }

    public function test_current_privacy_notice_is_delivered_from_the_published_document(): void
    {
        $operator = User::factory()->create();
        $documents = app(LegalDocumentService::class);
        $content = $documents->template('privacy', 'en');
        $content['title'] = 'Published Privacy Notice';
        $content['meta_title'] = 'Published Privacy Notice';
        $content['meta_description'] = 'A verified current Privacy Notice for this test.';

        $draft = $documents->createDraft([
            'document_type' => 'privacy',
            'locale' => 'en',
            'version' => 'test-privacy-2',
            'effective_at' => today()->toDateString(),
            ...$content,
        ], $operator);
        $documents->publish((int) $draft->getKey(), $operator);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('test-privacy-2')
            ->assertSee('Published Privacy Notice');

        $this->getJson('/api/v1/public/privacy')
            ->assertOk()
            ->assertJsonPath('data.version', 'test-privacy-2')
            ->assertJsonPath('data.title', 'Published Privacy Notice');
    }

    public function test_regular_member_cannot_manage_published_legal_documents(): void
    {
        // The admin gate resolves 'manage system settings' by name. Production always
        // has the permission seeded; without it the gate throws and the member sees a 500.
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/legal-documents')
            ->assertForbidden();
    }

    public function test_api_records_separate_terms_and_privacy_acknowledgements_idempotently(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/legal/consents', $this->consentPayload())
            ->assertOk()
            ->assertJsonPath('data.terms.action', 'accepted')
            ->assertJsonPath('data.privacy.action', 'acknowledged');

        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'document_type' => 'terms',
            'action' => 'accepted',
            'source' => 'api',
        ]);
        $this->assertDatabaseHas('user_consents', [
            'user_id' => $user->id,
            'document_type' => 'privacy',
            'action' => 'acknowledged',
            'source' => 'api',
        ]);
        $this->assertSame(2, UserConsent::query()->where('user_id', $user->id)->count());

        $this->postJson('/api/v1/legal/consents', $this->consentPayload())->assertOk();

        $this->assertSame(2, UserConsent::query()->where('user_id', $user->id)->count());

        $this->getJson('/api/v1/legal/consents')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    public function test_api_consent_is_bound_to_the_exact_displayed_version_and_hash(): void
    {
        $user = User::factory()->create();
        $displayed = $this->consentPayload();
        $operator = User::factory()->create();
        $documents = app(LegalDocumentService::class);
        $content = $documents->template('terms', 'en');
        $content['title'] = 'Terms published after the form was displayed';
        $content['meta_title'] = 'Terms published after the form was displayed';
        $content['sections'][0]['body'] = 'Changed terms content must require a new affirmative action.';
        $draft = $documents->createDraft([
            'document_type' => 'terms',
            'locale' => 'en',
            'version' => 'test-terms-race',
            'effective_at' => today()->toDateString(),
            ...$content,
        ], $operator);
        $documents->publish((int) $draft->getKey(), $operator);

        // This API's envelope places field errors under error.details.fields, not under
        // Laravel's default top-level `errors` key.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/legal/consents', $displayed)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['legal_documents']]]]);

        $this->assertSame(0, UserConsent::query()->where('user_id', $user->id)->count());
    }

    public function test_consent_evidence_cannot_be_updated_or_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/legal/consents', $this->consentPayload())
            ->assertOk();

        $consent = UserConsent::query()->where('user_id', $user->id)->firstOrFail();

        try {
            $consent->source = 'rewritten';
            $consent->save();
            $this->fail('Consent evidence must reject updates.');
        } catch (LogicException $exception) {
            $this->assertSame('Consent evidence is immutable and cannot be updated.', $exception->getMessage());
        }

        try {
            $consent->delete();
            $this->fail('Consent evidence must reject deletions.');
        } catch (LogicException $exception) {
            $this->assertSame('Consent evidence is immutable and cannot be deleted.', $exception->getMessage());
        }
    }

    public function test_web_consent_flow_records_both_current_acknowledgements(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('legal.consent.form'))
            ->assertOk()
            ->assertSee('name="terms_version"', false)
            ->assertSee('name="privacy_hash"', false);

        $this->post(route('legal.consent.store'), $this->consentPayload())
            ->assertRedirect(route('player.dashboard'));

        $this->assertSame(2, UserConsent::query()->where('user_id', $user->id)->count());
    }

    public function test_web_age_attestation_uses_the_date_of_birth_already_on_file(): void
    {
        $user = User::factory()->create([
            'date_of_birth' => '1990-02-03',
        ]);

        $this->actingAs($user)
            ->get(route('account.age-verification.form'))
            ->assertOk()
            ->assertSee('18+ age attestation')
            ->assertSee('cannot be changed through this form');

        $this->post(route('account.age-verification.store'), [
            'age_attestation' => '1',
        ])->assertRedirect(route('player.dashboard'));

        $this->assertDatabaseHas('age_verifications', [
            'user_id' => $user->id,
            'status' => 'eligible',
            'source' => 'account_settings',
        ]);
    }

    public function test_age_attestation_api_uses_the_dob_on_file_without_rewriting_it(): void
    {
        $user = User::factory()->create([
            'date_of_birth' => '1990-02-03',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/legal/age-attestation', [
                'age_attestation' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.method', 'self_declaration');

        $this->assertSame('1990-02-03', $user->fresh()->date_of_birth->toDateString());
        $this->assertDatabaseHas('age_verifications', [
            'user_id' => $user->id,
            'method' => 'self_declaration',
            'source' => 'api',
        ]);
    }

    public function test_financial_api_requires_a_separate_age_attestation(): void
    {
        $user = User::factory()->create([
            'date_of_birth' => '1990-02-03',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bets/purchase', [])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'age_attestation_required');
    }

    public function test_financial_api_requires_current_consent_after_age_is_eligible(): void
    {
        $user = User::factory()->create([
            'date_of_birth' => '1990-02-03',
        ]);
        AgeVerification::query()->create([
            'user_id' => $user->id,
            'status' => 'eligible',
            'minimum_age' => 18,
            'method' => 'self_declaration',
            'attested_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'LegalComplianceFlowTest',
            'source' => 'test',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bets/purchase', [])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'current_legal_acknowledgement_required');
    }

    public function test_a_newly_published_terms_hash_invalidates_old_acceptance(): void
    {
        $user = User::factory()->create([
            'date_of_birth' => '1990-02-03',
        ]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/legal/consents', $this->consentPayload())
            ->assertOk();

        $operator = User::factory()->create();
        $documents = app(LegalDocumentService::class);
        $content = $documents->template('terms', 'en');
        $content['title'] = 'Terms updated after user consent';
        $content['meta_title'] = 'Terms updated after user consent';
        $content['sections'][0]['body'] = 'The changed text has a different hash.';
        $draft = $documents->createDraft([
            'document_type' => 'terms',
            'locale' => 'en',
            'version' => 'test-terms-3',
            'effective_at' => today()->toDateString(),
            ...$content,
        ], $operator);
        $documents->publish((int) $draft->getKey(), $operator);

        AgeVerification::query()->create([
            'user_id' => $user->id,
            'status' => 'eligible',
            'minimum_age' => 18,
            'method' => 'self_declaration',
            'attested_at' => now(),
            'source' => 'test',
        ]);

        $this->postJson('/api/v1/bets/purchase', [])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'current_legal_acknowledgement_required');
    }

    /** @return array<string, string> */
    private function consentPayload(): array
    {
        $documents = app(LegalDocumentService::class);
        $locale = (string) app()->getLocale();
        $terms = $documents->currentData('terms', $locale);
        $privacy = $documents->currentData('privacy', $locale);

        return [
            'terms' => '1',
            'privacy_notice' => '1',
            'terms_version' => (string) $terms['version'],
            'terms_hash' => (string) $terms['content_hash'],
            'privacy_version' => (string) $privacy['version'],
            'privacy_hash' => (string) $privacy['content_hash'],
        ];
    }
}
