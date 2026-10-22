<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SupportedLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupportedLocaleTest extends TestCase
{
    public function test_the_allow_list_is_exactly_english_thai_and_bangla(): void
    {
        $this->assertSame(['en', 'th', 'bn'], SupportedLocale::ALL);
        $this->assertSame('en', SupportedLocale::EN);
        $this->assertSame('th', SupportedLocale::TH);
        $this->assertSame('bn', SupportedLocale::BN);
    }

    public static function supportedProvider(): array
    {
        return [
            'english' => ['en'],
            'thai' => ['th'],
            'bangla' => ['bn'],
        ];
    }

    #[DataProvider('supportedProvider')]
    public function test_supported_codes_are_accepted(string $code): void
    {
        $this->assertTrue(SupportedLocale::isSupported($code));
        $this->assertSame($code, SupportedLocale::normalise($code));
    }

    public static function rejectedProvider(): array
    {
        return [
            'unknown language' => ['fr'],
            'uppercase is not fuzzy-matched' => ['BN'],
            'region tag is not fuzzy-matched' => ['bn-BD'],
            'surrounding whitespace' => [' bn'],
            'empty string' => [''],
            'path traversal attempt' => ['../en'],
            'sql-ish payload' => ["en' OR 1=1"],
            'null' => [null],
            'integer' => [1],
            'array' => [['bn']],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function test_anything_outside_the_allow_list_is_rejected(mixed $candidate): void
    {
        $this->assertFalse(SupportedLocale::isSupported($candidate));
        $this->assertNull(SupportedLocale::normalise($candidate));
    }
}
