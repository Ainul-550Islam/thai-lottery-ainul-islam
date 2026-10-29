<?php

declare(strict_types=1);

namespace Tests\Unit\Lottery;

use App\Services\Lottery\WeeklyResultIntegrityService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Subprocess protocol fault injection, not evidence of native cryptography. */
final class WeeklyIntegrityProtocolTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/weekly-protocol-'.bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        config(['weekly_lottery.integrity.enabled' => true,
            'weekly_lottery.integrity.required' => false,
            'weekly_lottery.integrity.public_key_hex' => null,
            'weekly_lottery.integrity.canonical_version' => 'WKLY1']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function fields(): array
    {
        return ['draw_reference' => 'WK-20260918', 'draw_date' => '2026-09-18',
            'first_6' => '001234', 'three_ball' => '049', 'two_ball' => '09',
            'source_identifier' => 'SYNTHETIC-PROTOCOL', 'parser_version' => '1'];
    }

    private function service(): WeeklyResultIntegrityService
    {
        return app(WeeklyResultIntegrityService::class);
    }

    private function reply(array $overrides = [], int $exit = 0): void
    {
        $fields = $this->fields();
        $report = array_replace(['status' => 'INTEGRITY_HASH_ONLY', 'acceptable' => true,
            'fingerprint' => $this->service()->canonicalFingerprint($fields),
            'canonical_version' => 'WKLY1', 'canonical_length' => strlen($this->service()->canonicalBytes($fields)),
            'error_code' => null, 'error_field' => null], $overrides);
        $path = $this->directory.'/verifier';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php\nfwrite(STDOUT, "
            .var_export(json_encode($report, JSON_THROW_ON_ERROR), true).");\nexit(".$exit.");\n");
        chmod($path, 0700);
        config(['weekly_lottery.integrity.binary' => $path]);
    }

    public function test_valid_unsigned_protocol_reply_is_accepted_only_as_hash(): void
    {
        $this->reply();
        $report = $this->service()->verify($this->fields());
        $this->assertTrue($report['acceptable']);
        $this->assertTrue($report['native']);
        $this->assertSame('INTEGRITY_HASH_ONLY', $report['status']);
    }

    public static function invalidReplies(): array
    {
        return [
            'unknown status' => [['status' => 'MADE_UP'], 0],
            'string truthiness' => [['acceptable' => 'false'], 0],
            'integer truthiness' => [['acceptable' => 1], 0],
            'status boolean disagreement' => [['status' => 'REJECTED'], 0],
            'unearned signature' => [['status' => 'SIGNED_VERIFIED'], 0],
            'missing fingerprint' => [['fingerprint' => null], 0],
            'wrong fingerprint' => [['fingerprint' => str_repeat('a', 64)], 0],
            'wrong version' => [['canonical_version' => 'WKLY2'], 0],
            'missing version' => [['canonical_version' => null], 0],
            'wrong canonical length' => [['canonical_length' => 1], 0],
            'missing canonical length' => [['canonical_length' => null], 0],
            'error attached to success' => [['error_code' => 'SIGNATURE_MISMATCH'], 0],
            'nonzero success exit' => [[], 2],
        ];
    }

    #[DataProvider('invalidReplies')]
    public function test_invalid_native_protocol_never_authorizes_import(array $reply, int $exit): void
    {
        $this->reply($reply, $exit);
        $report = $this->service()->verify($this->fields());
        $this->assertFalse($report['acceptable'], json_encode($report));
    }

    public function test_hash_only_reply_cannot_satisfy_a_configured_signature(): void
    {
        $this->reply();
        config(['weekly_lottery.integrity.public_key_hex' => str_repeat('ab', 32)]);
        $report = $this->service()->verify($this->fields(), str_repeat('cd', 64));
        $this->assertFalse($report['acceptable']);
    }

    public function test_signature_without_a_trusted_key_is_not_dropped(): void
    {
        $this->reply();
        $report = $this->service()->verify($this->fields(), str_repeat('cd', 64));
        $this->assertFalse($report['acceptable']);
        $this->assertSame('SIGNATURE_INVALID', $report['status']);
    }

    public function test_trusted_key_without_a_signature_is_not_dropped(): void
    {
        $this->reply();
        config(['weekly_lottery.integrity.public_key_hex' => str_repeat('ab', 32)]);
        $report = $this->service()->verify($this->fields());
        $this->assertFalse($report['acceptable']);
        $this->assertSame('SIGNATURE_INVALID', $report['status']);
    }

    public function test_optional_missing_verifier_still_allows_genuinely_unsigned_hash(): void
    {
        config(['weekly_lottery.integrity.binary' => $this->directory.'/missing']);
        $report = $this->service()->verify($this->fields());
        $this->assertTrue($report['acceptable']);
        $this->assertFalse($report['native']);
        $this->assertSame('VERIFIER_UNAVAILABLE', $report['status']);
    }

    public function test_missing_verifier_cannot_downgrade_a_signed_request(): void
    {
        config(['weekly_lottery.integrity.binary' => $this->directory.'/missing',
            'weekly_lottery.integrity.public_key_hex' => str_repeat('ab', 32)]);
        $report = $this->service()->verify($this->fields(), str_repeat('cd', 64));
        $this->assertFalse($report['acceptable']);
    }

    public function test_required_verifier_cannot_be_bypassed_by_disabled_flag(): void
    {
        config(['weekly_lottery.integrity.enabled' => false, 'weekly_lottery.integrity.required' => true]);
        $this->assertFalse($this->service()->verify($this->fields())['acceptable']);
    }

    public function test_signed_request_cannot_be_bypassed_by_disabled_flag(): void
    {
        config(['weekly_lottery.integrity.enabled' => false,
            'weekly_lottery.integrity.public_key_hex' => str_repeat('ab', 32)]);
        $this->assertFalse($this->service()->verify($this->fields(), str_repeat('cd', 64))['acceptable']);
    }

    public function test_configured_version_cannot_relabel_wkly1_bytes(): void
    {
        $this->reply();
        config(['weekly_lottery.integrity.canonical_version' => 'WKLY2']);
        $this->assertFalse($this->service()->verify($this->fields())['acceptable']);
    }

    public function test_unreadable_output_from_installed_verifier_is_not_an_optional_bypass(): void
    {
        $path = $this->directory.'/bad-verifier';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php echo 'not-json';\n");
        chmod($path, 0700);
        config(['weekly_lottery.integrity.binary' => $path]);
        $this->assertFalse($this->service()->verify($this->fields())['acceptable']);
    }

    public function test_optional_verifier_timeout_cannot_downgrade_a_signed_request(): void
    {
        $path = $this->directory.'/slow-verifier';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php usleep(3000000);\n");
        chmod($path, 0700);
        config(['weekly_lottery.integrity.binary' => $path,
            'weekly_lottery.integrity.timeout_seconds' => 1,
            'weekly_lottery.integrity.required' => false,
            'weekly_lottery.integrity.public_key_hex' => str_repeat('ab', 32)]);

        $report = $this->service()->verify($this->fields(), str_repeat('cd', 64));
        $this->assertFalse($report['acceptable']);
        $this->assertSame('VERIFIER_UNAVAILABLE', $report['status']);
        $this->assertSame('VERIFIER_EXECUTION_FAILED', $report['error_code']);
    }

    public function test_malformed_signature_is_never_treated_as_unsigned(): void
    {
        $this->reply();
        foreach (['', 'abcd', str_repeat('z', 128)] as $signature) {
            $report = $this->service()->verify($this->fields(), $signature);
            $this->assertFalse($report['acceptable']);
            $this->assertSame('SIGNATURE_INVALID', $report['status']);
            $this->assertSame('INVALID_SIGNATURE_ENCODING', $report['error_code']);
        }
    }

    public function test_exit_two_preserves_a_well_formed_native_signature_refusal(): void
    {
        $this->reply(['status' => 'SIGNATURE_INVALID', 'acceptable' => false,
            'error_code' => 'SIGNATURE_MISMATCH'], 2);
        config(['weekly_lottery.integrity.public_key_hex' => str_repeat('ab', 32)]);
        $report = $this->service()->verify($this->fields(), str_repeat('cd', 64));
        $this->assertTrue($report['native']);
        $this->assertFalse($report['acceptable']);
        $this->assertSame('SIGNATURE_INVALID', $report['status']);
    }
}
