<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Captcha;

use App\Services\Auth\Captcha\DTOs\ExternalCaptchaResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalCaptchaResultTest extends TestCase
{
    public function test_only_verified_is_accepted_and_payload_is_minimal(): void
    {
        $result = new ExternalCaptchaResult('verified', 'VERIFIED');
        self::assertTrue($result->isVerified());
        self::assertSame(['status' => 'verified', 'code' => 'VERIFIED'], $result->toArray());
        self::assertTrue((new \ReflectionClass($result))->isReadOnly());
        foreach (['rejected', 'unavailable', 'misconfigured'] as $status) {
            self::assertFalse((new ExternalCaptchaResult($status, 'SAFE_REASON'))->isVerified());
        }
    }

    public static function invalidOutcomes(): array
    {
        return [['unknown', 'SAFE_REASON'], ['verified', 'PROVIDER_REJECTED'], ['rejected', 'VERIFIED'], ['unavailable', 'secret@example.test'], ['unavailable', '']];
    }

    #[DataProvider('invalidOutcomes')]
    public function test_invalid_outcome_is_rejected(string $status, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExternalCaptchaResult($status, $code);
    }
}
