<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\ComplianceAttestation;
use App\Services\Compliance\ComplianceBadgeService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * NO ATTESTATION, NO BADGE. That is the whole feature.
 *
 * The failure mode this class exists to prevent is subtle: a badge rendered from a
 * config flag or a hardcoded template looks identical to one backed by evidence, and
 * a regulator cannot tell the difference from the outside. So the assertions here are
 * about ABSENCE — a revoked, expired, future-dated or missing row produces no badge,
 * and the only thing still rendered is the policy notice that claims nothing.
 */
final class ComplianceBadgeServiceTest extends TestCase
{
    use DatabaseTruncation;

    private ComplianceBadgeService $badges;

    protected function setUp(): void
    {
        parent::setUp();

        $this->badges = app(ComplianceBadgeService::class);
    }

    private function attest(array $overrides = []): ComplianceAttestation
    {
        return ComplianceAttestation::query()->create(array_merge([
            'code' => 'licence',
            'reference' => 'GLO-2026-0001',
            'issuer' => 'Government Lottery Office',
            'document_hash' => str_repeat('a', 64),
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'verified_at' => now(),
        ], $overrides));
    }

    public function test_with_no_attestations_only_the_policy_notices_are_returned(): void
    {
        $payload = $this->badges->publicPayload();

        foreach ($payload['badges'] as $badge) {
            $this->assertArrayNotHasKey('code', $badge, 'A badge with a code asserts a credential and needs evidence.');
        }

        foreach ($payload['notices'] as $notice) {
            $this->assertArrayHasKey('key', $notice);
        }
    }

    public function test_a_valid_attestation_produces_a_badge_and_never_a_notice(): void
    {
        $this->attest();

        $payload = $this->badges->publicPayload();

        $codes = array_values(array_filter(array_map(
            static fn (array $b): ?string => $b['code'] ?? null,
            $payload['badges'],
        )));

        $this->assertContains('licence', $codes);
    }

    public function test_a_revoked_attestation_produces_no_badge(): void
    {
        $this->attest([
            'revoked_at' => now()->subDay(),
            'revoked_reason' => 'Licence lapsed and was replaced.',
        ]);

        $payload = $this->badges->publicPayload();

        foreach ($payload['badges'] as $badge) {
            $this->assertNotSame('licence', $badge['code'] ?? null);
        }

        $this->assertSame(
            [],
            array_values(array_filter($payload['badges'], static fn (array $b): bool => ($b['code'] ?? null) === 'licence')),
        );
    }

    public function test_an_expired_attestation_produces_no_badge(): void
    {
        $this->attest([
            'valid_from' => now()->subYear()->toDateString(),
            'valid_to' => now()->subDay()->toDateString(),
        ]);

        $payload = $this->badges->publicPayload();

        $this->assertSame(
            [],
            array_values(array_filter($payload['badges'], static fn (array $b): bool => ($b['code'] ?? null) === 'licence')),
        );
    }

    public function test_a_future_dated_attestation_produces_no_badge(): void
    {
        $this->attest([
            'valid_from' => now()->addWeek()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
        ]);

        $payload = $this->badges->publicPayload();

        $this->assertSame(
            [],
            array_values(array_filter($payload['badges'], static fn (array $b): bool => ($b['code'] ?? null) === 'licence')),
        );
    }

    public function test_the_18_plus_and_responsible_gaming_notices_need_no_attestation(): void
    {
        $payload = $this->badges->publicPayload();

        $keys = array_map(static fn (array $n): string => (string) $n['key'], $payload['notices']);

        $this->assertContains('age_18_plus', $keys);
        $this->assertContains('responsible_gaming_notice', $keys);

        foreach ($payload['notices'] as $notice) {
            $this->assertArrayNotHasKey('code', $notice);
            $this->assertArrayNotHasKey('document_hash', $notice);
        }
    }

    public function test_the_payload_never_carries_a_numeric_claim(): void
    {
        $this->attest();

        $json = json_encode($this->badges->badges(), JSON_THROW_ON_ERROR);

        // No fabricated percentage or rating anywhere in the public payload.
        $this->assertStringNotContainsString('%', $json);

        foreach (['Guaranteed', 'guaranteed', '100%', 'risk free', 'Risk free'] as $banned) {
            $this->assertStringNotContainsString($banned, $json);
        }
    }

    public function test_a_row_without_an_evidence_hash_is_not_a_badge(): void
    {
        /*
         * THE ROW IS ALLOWED TO EXIST - THE BADGE IS NOT.
         *
         * document_hash is nullable in the schema on purpose: an operator may record
         * that a licence exists before the PDF has been filed. What must never happen
         * is that such a row publishes a credential, because the hash is the only
         * thing that ties the claim to a document a reader could ask for. So the row
         * is stored, hasEvidence() is false, and no badge appears.
         */
        $this->attest(['document_hash' => null]);

        $payload = $this->badges->publicPayload();

        $this->assertSame(
            [],
            array_values(array_filter($payload['badges'], static fn (array $b): bool => ($b['code'] ?? null) === 'licence')),
            'A row with no evidence hash must not become a public badge.',
        );
    }

    public function test_a_row_without_a_reference_is_not_a_badge(): void
    {
        // Symmetrically: a hash is evidence OF something, and without a reference
        // nobody can ask the issuer what.
        $this->attest(['reference' => '']);

        $payload = $this->badges->publicPayload();

        $this->assertSame(
            [],
            array_values(array_filter($payload['badges'], static fn (array $b): bool => ($b['code'] ?? null) === 'licence')),
            'A row with no reference cannot be verified by a reader, so it is not published.',
        );
    }

    public function test_the_attested_count_is_the_number_of_badges(): void
    {
        $payload = $this->badges->publicPayload();

        $this->assertSame(count($payload['badges']), $payload['attested_count']);
    }

    public function test_reading_the_badges_writes_nothing(): void
    {
        $this->attest();

        $before = ComplianceAttestation::query()->count();

        $this->badges->badges();
        $this->badges->badges();

        $this->assertSame($before, ComplianceAttestation::query()->count());
    }

    public function test_the_public_payload_is_stable_and_typed(): void
    {
        $this->attest();

        $payload = $this->badges->publicPayload();

        $this->assertArrayHasKey('badges', $payload);
        $this->assertArrayHasKey('notices', $payload);
        $this->assertIsArray($payload['badges']);
        $this->assertIsArray($payload['notices']);
    }
}
