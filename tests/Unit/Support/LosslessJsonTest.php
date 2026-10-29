<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LosslessJson;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LosslessJsonTest extends TestCase
{
    public function test_preserves_number_lexemes_and_nested_types_without_float_rounding(): void
    {
        $json = <<<'JSON'
{"number":99999999999999.99,"integer":123456789012345678901234,"exponent":121.25e-2,"negative":-0.01,"nested":[true,false,null,{"am\u006funt":1.2300,"text":"quote \" and slash \\ and number 42"}]}
JSON;
        $value = LosslessJson::decode($json);

        self::assertSame('99999999999999.99', $value['number']);
        self::assertSame('123456789012345678901234', $value['integer']);
        self::assertSame('121.25e-2', $value['exponent']);
        self::assertSame('-0.01', $value['negative']);
        self::assertTrue($value['nested'][0]);
        self::assertFalse($value['nested'][1]);
        self::assertNull($value['nested'][2]);
        self::assertSame('1.2300', $value['nested'][3]['amount']);
        self::assertSame('quote " and slash \\ and number 42', $value['nested'][3]['text']);
    }

    public static function invalidJson(): array
    {
        return [
            'duplicate root key' => ['{"amount":1,"amount":2}'],
            'escaped duplicate key' => ['{"amount":1,"am\\u006funt":2}'],
            'duplicate nested key after null' => ['{"data":{"amount":null,"amount":1}}'],
            'invalid number' => ['{"amount":1.}'],
            'invalid string' => ['{"amount":"unterminated}'],
            'extra value' => ['{} {}'],
            'invalid utf8' => ["{\"a\":\"\xff\"}"],
        ];
    }

    #[DataProvider('invalidJson')]
    public function test_rejects_invalid_or_ambiguous_json(string $json): void
    {
        $this->expectException(JsonException::class);
        LosslessJson::decode($json);
    }

    public function test_varied_valid_json_preserves_every_numeric_lexeme(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $integer = (($i * 47317) % 2000001) - 1000000;
            $decimal = ((($i * 7313) % 200001) - 100000) / 100;
            $input = [
                'id' => $i,
                'items' => [$integer, $decimal, true, false, null, 'quote " slash \\ thai ไทย', ['n' => $decimal]],
                'flag' => ($i % 2) === 0,
            ];
            $encoded = json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
            $expected = [
                'id' => (string) $i,
                'items' => [(string) $integer, json_encode($decimal, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, false, null, 'quote " slash \\ thai ไทย', ['n' => json_encode($decimal, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)]],
                'flag' => ($i % 2) === 0,
            ];
            self::assertSame($expected, LosslessJson::decode($encoded));
        }
    }

    public function test_empty_objects_arrays_and_scalars_are_handled(): void
    {
        self::assertSame([], LosslessJson::decode('{}'));
        self::assertSame([], LosslessJson::decode('[]'));
        self::assertSame('0', LosslessJson::decode('0'));
        self::assertSame('a', LosslessJson::decode('"a"'));
        self::assertNull(LosslessJson::decode('null'));
        self::assertSame(['0' => 'first', '00' => 'second'], LosslessJson::decode('{"0":"first","00":"second"}'));
    }
}
