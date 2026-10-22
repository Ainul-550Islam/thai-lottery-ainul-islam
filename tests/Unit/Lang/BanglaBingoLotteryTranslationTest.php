<?php

declare(strict_types=1);

namespace Tests\Unit\Lang;

use PHPUnit\Framework\TestCase;

final class BanglaBingoLotteryTranslationTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function load(string $locale): array
    {
        $path = dirname(__DIR__, 3).'/lang/'.$locale.'/bingo_lottery.php';
        self::assertFileExists($path);

        return require $path;
    }

    /** @return array<string, string> */
    private static function flatten(array $items, string $prefix = ''): array
    {
        $flat = [];
        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = (string) $value;
            }
        }

        return $flat;
    }

    public function test_bangla_covers_the_complete_bingo_lottery_catalog(): void
    {
        $english = self::flatten(self::load('en'));
        $bangla = self::flatten(self::load('bn'));

        $this->assertSame([], array_diff_key($english, $bangla), 'Bangla is missing Bingo/Mega Lottery keys.');
        $this->assertSame([], array_diff_key($bangla, $english), 'Bangla contains unknown Bingo/Mega Lottery keys.');
    }

    public function test_bangla_bingo_lottery_placeholders_match_english(): void
    {
        $english = self::flatten(self::load('en'));
        $bangla = self::flatten(self::load('bn'));

        foreach ($english as $key => $value) {
            preg_match_all('/:([a-z_]+)/', $value, $englishPlaceholders);
            preg_match_all('/:([a-z_]+)/', $bangla[$key], $banglaPlaceholders);
            sort($englishPlaceholders[0]);
            sort($banglaPlaceholders[0]);
            $this->assertSame($englishPlaceholders[0], $banglaPlaceholders[0], 'Placeholder mismatch for '.$key);
        }
    }
}
