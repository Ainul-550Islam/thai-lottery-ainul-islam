<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\NationalIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NationalIdentityTest extends TestCase
{
    public static function normaliseProvider(): array
    {
        return [
            'plain' => ['AB123456', 'AB123456'],
            'lower case is upper-cased' => ['ab123456', 'AB123456'],
            'spaces are removed' => ['AB 123 456', 'AB123456'],
            'dashes are removed' => ['1-2345-67890-12-3', '1234567890123'],
            'surrounding whitespace' => ['  AB123456  ', 'AB123456'],
            'mixed separators' => [' ne-1 2 3 ', 'NE123'],
        ];
    }

    #[DataProvider('normaliseProvider')]
    public function test_normalise_strips_separators_and_upper_cases(string $raw, string $expected): void
    {
        $this->assertSame($expected, NationalIdentity::normalise($raw));
    }

    public static function emptyProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'only spaces' => ['   '],
            'only dashes' => ['---'],
        ];
    }

    #[DataProvider('emptyProvider')]
    public function test_blank_input_normalises_to_null(?string $raw): void
    {
        $this->assertNull(NationalIdentity::normalise($raw));
    }

    public static function validityProvider(): array
    {
        return [
            'six characters (minimum)' => ['ABC123', true],
            'thirty-two characters (maximum)' => [str_repeat('A1', 16), true],
            'five characters (too short)' => ['ABC12', false],
            'thirty-three characters (too long)' => [str_repeat('A', 33), false],
            'contains a symbol' => ['AB12!3', false],
            'symbol survives normalisation' => ['AB12!', false],
        ];
    }

    #[DataProvider('validityProvider')]
    public function test_validity_follows_the_accepted_shape(string $normalised, bool $expected): void
    {
        $this->assertSame($expected, NationalIdentity::isValid($normalised));
    }

    public function test_hash_is_deterministic_for_the_same_number(): void
    {
        $this->assertSame(NationalIdentity::hash('AB123456'), NationalIdentity::hash('AB123456'));
    }

    public function test_hash_differs_for_different_numbers(): void
    {
        $this->assertNotSame(NationalIdentity::hash('AB123456'), NationalIdentity::hash('AB123457'));
    }

    public function test_hash_is_a_64_character_hex_digest_and_never_the_plain_value(): void
    {
        $hash = NationalIdentity::hash('AB123456');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        $this->assertStringNotContainsString('AB123456', $hash);
    }

    public function test_hash_depends_on_the_application_key(): void
    {
        $before = NationalIdentity::hash('AB123456');

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('z', 32)));

        $this->assertNotSame($before, NationalIdentity::hash('AB123456'));
    }

    public function test_mask_shows_only_the_last_four_characters(): void
    {
        $this->assertSame('****3456', NationalIdentity::mask('AB123456'));
    }

    public function test_mask_of_a_short_value_keeps_it_visible_without_going_negative(): void
    {
        $this->assertSame('1234', NationalIdentity::mask('1234'));
    }
}
