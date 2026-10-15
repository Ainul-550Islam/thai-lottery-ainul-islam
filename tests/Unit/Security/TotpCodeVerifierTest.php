<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Services\Security\TotpCodeVerifier;
use DateTimeImmutable;
use DateTimeZone;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;

final class TotpCodeVerifierTest extends TestCase
{
    // RFC6238 Appendix B SHA1 key: ASCII 12345678901234567890. Not a deployment secret.
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private function clock(int $time, string $zone = 'UTC'): ClockInterface
    {
        return new class($time, $zone) implements ClockInterface
        {
            public function __construct(private readonly int $time, private readonly string $zone) {}

            public function now(): DateTimeImmutable
            {
                return (new DateTimeImmutable('@'.$this->time))->setTimezone(new DateTimeZone($this->zone));
            }
        };
    }

    public static function rfcVectors(): array
    {
        // Independent fixed expected strings, not computed by the implementation.
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
            [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_pinned_library_matches_exact_eight_digit_rfc_vectors(int $time, string $expected): void
    {
        $totp = TOTP::create(self::RFC_SECRET, 30, 'sha1', 8, 0, $this->clock($time));
        self::assertSame($expected, $totp->at($time));
    }

    #[DataProvider('rfcVectors')]
    public function test_six_digit_adapter_matches_rfc_sha1_modulo_projection(int $time, string $expected): void
    {
        // Truncation modulo 10^6 equals the last six digits of the RFC's 10^8 output.
        $verifier = new TotpCodeVerifier($this->clock($time));
        self::assertSame(intdiv($time, 30), $verifier->matchingTimeStep(self::RFC_SECRET, substr($expected, -6)));
        self::assertNull($verifier->matchingTimeStep(self::RFC_SECRET, $expected));
    }

    public static function malformedCodes(): array
    {
        return [[''], ['81804'], ['0081804'], [' 081804'], ['081804 '], ["081804\n"], ["081804\0"], ['０８１８０４'], ['٠٨١٨٠٤'], ['+81804'], ['08e804'], ['abcdef']];
    }

    #[DataProvider('malformedCodes')]
    public function test_code_is_exactly_six_ascii_digits(string $code): void
    {
        self::assertNull((new TotpCodeVerifier($this->clock(1111111109)))->matchingTimeStep(self::RFC_SECRET, $code));
    }

    public static function malformedSecrets(): array
    {
        return [[''], [str_repeat('A', 31)], [str_repeat('A', 33)], [strtolower(self::RFC_SECRET)], [self::RFC_SECRET.'='], [self::RFC_SECRET."\n"], [' '.self::RFC_SECRET], [str_repeat('0', 32)], [str_repeat('1', 32)], [str_repeat('8', 32)], [str_repeat('9', 32)]];
    }

    #[DataProvider('malformedSecrets')]
    public function test_secret_is_canonical_160_bit_base32(string $secret): void
    {
        self::assertNull((new TotpCodeVerifier($this->clock(59)))->matchingTimeStep($secret, '287082'));
    }

    public function test_window_is_previous_current_next_steps_not_arbitrary_leeway(): void
    {
        // RFC4226 counter0=755224, counter1=287082, counter2=359152, counter3=969429.
        $verifier = new TotpCodeVerifier($this->clock(59));
        self::assertSame(0, $verifier->matchingTimeStep(self::RFC_SECRET, '755224'));
        self::assertSame(1, $verifier->matchingTimeStep(self::RFC_SECRET, '287082'));
        self::assertSame(2, $verifier->matchingTimeStep(self::RFC_SECRET, '359152'));
        self::assertNull($verifier->matchingTimeStep(self::RFC_SECRET, '969429'));
        self::assertNull((new TotpCodeVerifier($this->clock(90)))->matchingTimeStep(self::RFC_SECRET, '287082'));
    }

    public function test_epoch_and_thirty_second_boundary_preserve_zero_counter(): void
    {
        self::assertSame(0, (new TotpCodeVerifier($this->clock(0)))->matchingTimeStep(self::RFC_SECRET, '755224'));
        self::assertSame(0, (new TotpCodeVerifier($this->clock(29)))->matchingTimeStep(self::RFC_SECRET, '755224'));
        self::assertSame(1, (new TotpCodeVerifier($this->clock(30)))->matchingTimeStep(self::RFC_SECRET, '287082'));
        self::assertNull((new TotpCodeVerifier($this->clock(60)))->matchingTimeStep(self::RFC_SECRET, '755224'));
    }

    public function test_wrong_code_and_wrong_secret_do_not_match(): void
    {
        $verifier = new TotpCodeVerifier($this->clock(59));
        self::assertNull($verifier->matchingTimeStep(self::RFC_SECRET, '000000'));
        self::assertNull($verifier->matchingTimeStep(str_repeat('A', 32), '287082'));
    }

    public function test_repeated_code_still_matches_because_this_is_not_replay_consumption(): void
    {
        $verifier = new TotpCodeVerifier($this->clock(59));
        self::assertSame(1, $verifier->matchingTimeStep(self::RFC_SECRET, '287082'));
        self::assertSame(1, $verifier->matchingTimeStep(self::RFC_SECRET, '287082'));
    }

    public function test_timezone_does_not_change_unix_counter(): void
    {
        foreach (['UTC', 'Asia/Dhaka', 'America/New_York'] as $zone) {
            self::assertSame(1, (new TotpCodeVerifier($this->clock(59, $zone)))->matchingTimeStep(self::RFC_SECRET, '287082'));
        }
    }

    public function test_upper_clock_boundary_has_no_overflow(): void
    {
        $time = TotpCodeVerifier::MAX_TIMESTAMP;
        $clock = $this->clock($time);
        $code = TOTP::create(self::RFC_SECRET, 30, 'sha1', 6, 0, $clock)->at($time);
        self::assertSame(intdiv($time, 30), (new TotpCodeVerifier($clock))->matchingTimeStep(self::RFC_SECRET, $code));
    }

    public function test_invalid_clock_is_an_operational_error_not_a_success_or_silent_mismatch(): void
    {
        foreach ([-1, TotpCodeVerifier::MAX_TIMESTAMP + 1] as $time) {
            try {
                (new TotpCodeVerifier($this->clock($time)))->matchingTimeStep(self::RFC_SECRET, '287082');
                self::fail('Invalid server clock accepted.');
            } catch (RuntimeException $exception) {
                self::assertSame('TOTP server clock is outside the supported range.', $exception->getMessage());
            }
        }
    }

    public function test_clock_is_read_once_and_errors_are_not_swallowed(): void
    {
        $clock = new class implements ClockInterface
        {
            public int $calls = 0;

            public function now(): DateTimeImmutable
            {
                $this->calls++;
                if ($this->calls > 1) {
                    throw new RuntimeException('fixture clock failed');
                }

                return new DateTimeImmutable('@59');
            }
        };
        $verifier = new TotpCodeVerifier($clock);
        self::assertSame(1, $verifier->matchingTimeStep(self::RFC_SECRET, '287082'));
        self::assertSame(1, $clock->calls);
        $this->expectExceptionMessage('fixture clock failed');
        $verifier->matchingTimeStep(self::RFC_SECRET, '287082');
    }

    public function test_generated_secrets_have_required_shape_and_interoperate_with_library(): void
    {
        $clock = $this->clock(1234567890);
        $verifier = new TotpCodeVerifier($clock);
        $secrets = [];
        for ($i = 0; $i < 16; $i++) {
            $secret = $verifier->generateSecret();
            self::assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $secret);
            $code = TOTP::create($secret, 30, 'sha1', 6, 0, $clock)->at(1234567890);
            self::assertSame(intdiv(1234567890, 30), $verifier->matchingTimeStep($secret, $code));
            $secrets[] = $secret;
        }
        self::assertCount(16, array_unique($secrets));
        // Sample uniqueness/shape does not certify randomness; library uses random_bytes(20).
    }

    public function test_secret_and_code_parameters_are_marked_sensitive(): void
    {
        $method = new ReflectionMethod(TotpCodeVerifier::class, 'matchingTimeStep');
        foreach ($method->getParameters() as $parameter) {
            self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        }
    }
}
