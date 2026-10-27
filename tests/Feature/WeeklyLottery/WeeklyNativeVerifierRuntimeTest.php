<?php

declare(strict_types=1);

namespace Tests\Feature\WeeklyLottery;

use App\Services\Lottery\WeeklyResultIntegrityService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Cross-language runtime contract for the Weekly native verifier (PROMPT 6).
 *
 * This suite is the reason CI builds the Rust executable before it runs PHP:
 * it exercises the REAL binary at the configured path, end to end, through
 * the same service the importer uses. There is deliberately no availability
 * skip and no faked process anywhere in this file. When the binary has not
 * been provisioned the suite fails loudly, because a silently skipped
 * cross-language check is how PHP and Rust canonicalization drift apart
 * while every dashboard stays green.
 *
 * The fixture values are synthetic and chosen to exercise byte-exact rules:
 * 001234 proves a six-digit leading-zero round trip, 049 and 09 prove the
 * three- and two-digit fields, and SYNTHETIC-F40 marks the provenance of
 * this fixture so no real provider record can ever be confused with it.
 */
final class WeeklyNativeVerifierRuntimeTest extends TestCase
{
    /**
     * Canonical field set shared by every test in this suite.
     *
     * @return array<string, string>
     */
    private function fields(): array
    {
        return [
            'draw_reference' => 'WK-20260918',
            'draw_date' => '2026-09-18',
            'first_6' => '001234',
            'three_ball' => '049',
            'two_ball' => '09',
            'source_identifier' => 'SYNTHETIC-F40',
            'parser_version' => '1',
        ];
    }

    private function integrity(): WeeklyResultIntegrityService
    {
        return app(WeeklyResultIntegrityService::class);
    }

    public function test_native_verifier_is_provisioned_and_agrees_with_php(): void
    {
        $integrity = $this->integrity();

        // Provisioning is part of the contract: CI builds the real executable
        // with scripts/build-weekly-native-verifier.sh before this suite runs.
        $this->assertTrue(
            $integrity->isNativeVerifierAvailable(),
            'The native verifier executable must be built before this suite runs; run bash scripts/build-weekly-native-verifier.sh.',
        );

        $report = $integrity->verify($this->fields());

        $this->assertTrue($report['native']);
        $this->assertSame(WeeklyResultIntegrityService::STATUS_HASH_ONLY, $report['status']);
        $this->assertTrue($report['acceptable']);
        $this->assertNull($report['error_code']);
        $this->assertSame(
            $integrity->canonicalFingerprint($this->fields()),
            $report['fingerprint'],
            'PHP and the native verifier must agree on the canonical fingerprint.',
        );
    }

    public function test_native_verifier_accepts_a_valid_ed25519_signature(): void
    {
        $integrity = $this->integrity();
        $this->assertTrue($integrity->isNativeVerifierAvailable());

        // A throwaway keypair, generated for this test only. The signature is
        // over the canonical bytes themselves, exactly as the Rust verifier
        // expects - not over the hex digest and not over any JSON encoding.
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $signature = sodium_crypto_sign_detached(
            $integrity->canonicalBytes($this->fields()),
            $secretKey,
        );

        config(['weekly_lottery.integrity.public_key_hex' => bin2hex($publicKey)]);

        $report = $integrity->verify($this->fields(), bin2hex($signature));

        $this->assertTrue($report['native']);
        $this->assertSame(WeeklyResultIntegrityService::STATUS_SIGNED_VERIFIED, $report['status']);
        $this->assertTrue($report['acceptable']);
        $this->assertNull($report['error_code']);
    }

    public function test_native_verifier_rejects_a_signature_over_different_bytes(): void
    {
        $integrity = $this->integrity();
        $this->assertTrue($integrity->isNativeVerifierAvailable());

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $signature = sodium_crypto_sign_detached(
            $integrity->canonicalBytes($this->fields()),
            $secretKey,
        );

        config(['weekly_lottery.integrity.public_key_hex' => bin2hex($publicKey)]);

        // Same signature, one digit changed in the payload it claims to cover.
        $tampered = $this->fields();
        $tampered['first_6'] = '001235';

        $report = $integrity->verify($tampered, bin2hex($signature));

        $this->assertTrue($report['native']);
        $this->assertSame(WeeklyResultIntegrityService::STATUS_SIGNATURE_INVALID, $report['status']);
        $this->assertFalse($report['acceptable']);
    }

    public function test_native_verifier_reports_incomplete_signature_material(): void
    {
        $integrity = $this->integrity();
        $this->assertTrue($integrity->isNativeVerifierAvailable());

        // Half the signature material is not a weaker signature; the native
        // verifier names it as the misconfiguration it is. This is exercised
        // against the real binary directly, because the PHP service only ever
        // forwards a complete key/signature pair.
        $request = [
            'draw_reference' => 'WK-20260918',
            'draw_date' => '2026-09-18',
            'first_6' => '001234',
            'three_ball' => '049',
            'two_ball' => '09',
            'source_identifier' => 'SYNTHETIC-F40',
            'parser_version' => '1',
            'expected_fingerprint' => $integrity->canonicalFingerprint($this->fields()),
            'signature_hex' => bin2hex(str_repeat("\x01", SODIUM_CRYPTO_SIGN_BYTES)),
        ];

        $process = new Process([$integrity->binaryPath()]);
        $process->setInput((string) json_encode($request));
        $process->setTimeout(10);
        $process->run();

        $decoded = json_decode(trim($process->getOutput()), true);

        $this->assertSame(2, $process->getExitCode());
        $this->assertIsArray($decoded);
        $this->assertSame(WeeklyResultIntegrityService::STATUS_SIGNATURE_INVALID, $decoded['status']);
        $this->assertFalse($decoded['acceptable']);
        $this->assertSame('INCOMPLETE_SIGNATURE_MATERIAL', $decoded['error_code']);
    }
}
