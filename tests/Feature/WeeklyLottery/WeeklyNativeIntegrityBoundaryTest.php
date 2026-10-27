<?php

declare(strict_types=1);

namespace Tests\Feature\WeeklyLottery;

use App\Models\WeeklyLotteryDraw;
use App\Models\WeeklyLotteryResult;
use App\Models\WeeklyLotteryResultVersion;
use App\Services\Lottery\WeeklyLotteryImportService;
use App\Services\Lottery\WeeklyResultIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real release binary, real PHP/Sodium signatures, real importer and database. */
final class WeeklyNativeIntegrityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['weekly_lottery.integrity.enabled' => true, 'weekly_lottery.integrity.required' => true,
            'weekly_lottery.integrity.public_key_hex' => null, 'weekly_lottery.integrity.canonical_version' => 'WKLY1']);
        if (! app(WeeklyResultIntegrityService::class)->isNativeVerifierAvailable()) {
            $this->markTestSkipped('Build the pinned weekly-result-integrity release binary; the native CI lane refuses skips.');
        }
    }

    private function payload(): array
    {
        return ['draw_date' => '2026-09-18', 'first_6' => '001234', 'three_ball' => '049',
            'two_ball' => '09', 'source_identifier' => 'SYNTHETIC-NATIVE',
            'retrieved_at' => '2026-09-18T12:00:00+00:00'];
    }

    private function fields(array $payload): array
    {
        return array_intersect_key($payload, array_flip(['draw_date', 'first_6', 'three_ball', 'two_ball', 'source_identifier']))
            + ['draw_reference' => WeeklyLotteryDraw::buildReference($payload['draw_date']), 'parser_version' => '1'];
    }

    private function signedPayload(): array
    {
        $payload = $this->payload();
        $pair = sodium_crypto_sign_keypair();
        config(['weekly_lottery.integrity.public_key_hex' => bin2hex(sodium_crypto_sign_publickey($pair))]);
        $payload['signature_hex'] = bin2hex(sodium_crypto_sign_detached(
            app(WeeklyResultIntegrityService::class)->canonicalBytes($this->fields($payload)),
            sodium_crypto_sign_secretkey($pair),
        ));

        return $payload;
    }

    private function assertRefusedWithoutWrites(array $payload): array
    {
        $outcome = app(WeeklyLotteryImportService::class)->import($payload, 'fixture');
        $this->assertSame('rejected', $outcome['status'], json_encode($outcome));
        $this->assertFalse($outcome['published']);
        $this->assertSame(0, WeeklyLotteryDraw::query()->count());
        $this->assertSame(0, WeeklyLotteryResult::query()->count());
        $this->assertSame(0, WeeklyLotteryResultVersion::query()->count());

        return $outcome;
    }

    public function test_php_sodium_signature_verifies_in_rust_and_persists_the_exact_fingerprint(): void
    {
        $payload = $this->signedPayload();
        $outcome = app(WeeklyLotteryImportService::class)->import($payload, 'fixture');
        $this->assertSame('imported', $outcome['status'], json_encode($outcome));
        $this->assertTrue($outcome['integrity']['native']);
        $this->assertSame('SIGNED_VERIFIED', $outcome['integrity']['status']);
        $version = WeeklyLotteryResultVersion::query()->firstOrFail();
        $this->assertSame('SIGNED_VERIFIED', $version->integrity_status);
        $this->assertSame(app(WeeklyResultIntegrityService::class)->canonicalFingerprint($this->fields($payload)), $version->normalized_fingerprint);
        $result = WeeklyLotteryResult::query()->firstOrFail();
        $this->assertSame('001234', $result->first_6);
        $this->assertSame('049', $result->three_ball);
        $this->assertSame('09', $result->two_ball);
    }

    public function test_modified_result_cannot_reuse_a_valid_signature(): void
    {
        $payload = $this->signedPayload();
        $payload['first_6'] = '001235';
        $outcome = $this->assertRefusedWithoutWrites($payload);
        $this->assertSame('SIGNATURE_INVALID', $outcome['integrity']['status']);
        $this->assertTrue($outcome['integrity']['native']);
    }

    public function test_configured_signing_key_requires_a_signature_before_any_write(): void
    {
        $payload = $this->signedPayload();
        unset($payload['signature_hex']);
        $this->assertRefusedWithoutWrites($payload);
    }

    public function test_supplied_signature_requires_a_trusted_key_before_any_write(): void
    {
        $payload = $this->signedPayload();
        config(['weekly_lottery.integrity.public_key_hex' => null]);
        $this->assertRefusedWithoutWrites($payload);
    }

    public static function malformedSignatures(): array
    {
        return ['empty' => [''], 'non-hex' => [str_repeat('z', 128)], 'short' => ['ab'],
            'number' => [123], 'array' => [['ab']], 'boolean' => [false]];
    }

    #[DataProvider('malformedSignatures')]
    public function test_malformed_signature_is_not_normalized_to_unsigned(mixed $signature): void
    {
        $payload = $this->payload();
        $payload['signature_hex'] = $signature;
        $outcome = $this->assertRefusedWithoutWrites($payload);
        $this->assertContains('INVALID_SIGNATURE_ENCODING', $outcome['errors']);
    }

    public function test_weak_identity_key_and_trivial_signature_are_refused(): void
    {
        $payload = $this->payload();
        config(['weekly_lottery.integrity.public_key_hex' => '01'.str_repeat('00', 31)]);
        $payload['signature_hex'] = '01'.str_repeat('00', 63);
        $outcome = $this->assertRefusedWithoutWrites($payload);
        $this->assertSame('SIGNATURE_INVALID', $outcome['integrity']['status']);
    }

    public static function internationalIdentifiers(): array
    {
        return ['Thai identifier' => [str_repeat('ผล', 80)], '191-character boundary' => [str_repeat('ক', 191)]];
    }

    #[DataProvider('internationalIdentifiers')]
    public function test_international_identifier_uses_the_database_character_limit(string $identifier): void
    {
        $payload = $this->payload();
        $payload['source_identifier'] = $identifier;
        $outcome = app(WeeklyLotteryImportService::class)->import($payload, 'fixture');
        $this->assertSame('imported', $outcome['status'], json_encode($outcome));
        $this->assertTrue($outcome['integrity']['native']);
        $this->assertSame(app(WeeklyResultIntegrityService::class)->canonicalFingerprint($this->fields($payload)),
            $outcome['integrity']['fingerprint']);
    }

    public function test_unsigned_native_agreement_remains_hash_only_not_official_authentication(): void
    {
        $outcome = app(WeeklyLotteryImportService::class)->import($this->payload(), 'fixture');
        $this->assertSame('imported', $outcome['status'], json_encode($outcome));
        $this->assertSame('INTEGRITY_HASH_ONLY', $outcome['integrity']['status']);
        $this->assertTrue($outcome['integrity']['native']);
        $this->assertSame('FIXTURE_ONLY', $outcome['source_state']);
    }
}
