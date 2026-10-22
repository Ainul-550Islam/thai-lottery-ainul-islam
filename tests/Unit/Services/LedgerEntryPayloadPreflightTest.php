<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Services\Finance\LedgerEntryPayloadPreflight;
use Illuminate\Database\Eloquent\Casts\Json;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LedgerEntryPayloadPreflightTest extends TestCase
{
    public static function texts(): array
    {
        $cases = [];
        foreach (['description', 'reference_type'] as $field) {
            foreach ([false, true] as $fallback) {
                foreach (['null', 'empty', 'ascii255', 'unicode255', 'thai255', 'ascii256', 'unicode256', 'invalid'] as $kind) {
                    $cases[$field.'-'.($fallback ? 'fallback' : 'draft').'-'.$kind] = [$field, $fallback, $kind];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('texts')]
    public function test_text_limits_are_lossless_unicode_and_apply_after_null_fallback(string $field, bool $fallback, string $kind): void
    {
        $value = match ($kind) {
            'null' => null, 'empty' => '', 'ascii255' => str_repeat('a', 255),
            'unicode255' => str_repeat('😀', 255), 'thai255' => str_repeat('ก', 255),
            'ascii256' => str_repeat('a', 256), 'unicode256' => str_repeat('😀', 256), default => "\xC3\x28",
        };
        $draft = [$field => $fallback ? null : $value];
        $defaults = ['description' => null, 'reference_type' => null];
        if ($fallback) {
            $defaults[$field] = $value;
        }
        $error = null;
        try {
            $plan = (new LedgerEntryPayloadPreflight)->prepare([$draft], Currency::BDT, $defaults['description'], $defaults['reference_type']);
        } catch (FinancialException $caught) {
            $error = $caught;
        }
        if (in_array($kind, ['ascii256', 'unicode256', 'invalid'], true)) {
            $this->assertInstanceOf(FinancialException::class, $error);
            $this->assertSame('ledger_entry_'.$field.'_'.($kind === 'invalid' ? 'invalid' : 'too_long'), $error->errorCode());
            $this->assertSame(['entry_index' => 0, 'field' => $field, 'currency' => 'BDT'], $error->context());
            $this->assertNull($error->getPrevious());
        } else {
            $this->assertNull($error);
            $this->assertSame($value, $plan[0][$field]);
            $this->assertNull($plan[0]['metadata']);
        }
    }

    public static function invalidJson(): array
    {
        return array_map(fn ($kind) => [$kind], ['utf8', 'inf', 'nan', 'resource', 'recursive', 'depth', 'throwing']);
    }

    #[DataProvider('invalidJson')]
    public function test_unencodable_later_metadata_refuses_the_complete_plan_without_payload_context(string $kind): void
    {
        $resource = null;
        $value = match ($kind) {
            'utf8' => ["\xB1\x31"], 'inf' => [INF], 'nan' => [NAN],
            'resource' => [$resource = fopen('php://memory', 'r+')],
            'throwing' => [new class implements JsonSerializable
            {
                public function jsonSerialize(): mixed
                {
                    throw new RuntimeException('secret serializer payload');
                }
            }],
            default => [],
        };
        if ($kind === 'recursive') {
            $value['self'] = &$value;
        }
        if ($kind === 'depth') {
            for ($i = 0; $i < 513; $i++) {
                $value = [$value];
            }
        }
        try {
            (new LedgerEntryPayloadPreflight)->prepare([['metadata' => ['ok']], ['metadata' => $value]], Currency::THB);
            $this->fail('Expected complete JSON refusal.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_entry_metadata_invalid', $error->errorCode());
            $this->assertSame(['entry_index' => 1, 'field' => 'metadata', 'currency' => 'THB'], $error->context());
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('secret', $error->getMessage());
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    public static function validJson(): array
    {
        return [[null], [[]], [[1, 'a', null]], [['ไทย' => 'বাংলা', 'emoji' => '😀']], [['nested' => ['ok' => true, 'n' => 1.25]]]];
    }

    #[DataProvider('validJson')]
    public function test_valid_metadata_retains_the_existing_array_cast_bytes(?array $metadata): void
    {
        $plan = (new LedgerEntryPayloadPreflight)->prepare([['metadata' => $metadata]], Currency::THB);
        $this->assertSame($metadata === null ? null : json_encode($metadata), $plan[0]['metadata']);
        $this->assertNull($plan[0]['description']);
        $this->assertNull($plan[0]['reference_type']);
    }

    public function test_stateful_serialization_is_evaluated_once_and_frozen(): void
    {
        $value = new class implements JsonSerializable
        {
            public int $calls = 0;

            public function jsonSerialize(): mixed
            {
                if (++$this->calls !== 1) {
                    throw new RuntimeException('A second serialization must never occur.');
                }

                return ['frozen' => 1];
            }
        };
        $plan = (new LedgerEntryPayloadPreflight)->prepare([['metadata' => ['value' => $value]]], Currency::THB);
        $this->assertSame(1, $value->calls);
        $this->assertSame('{"value":{"frozen":1}}', $plan[0]['metadata']);
    }

    public function test_installed_eloquent_encoder_is_used_once_without_altering_bytes(): void
    {
        $calls = 0;
        Json::encodeUsing(function ($value) use (&$calls) {
            $calls++;

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });
        try {
            $plan = (new LedgerEntryPayloadPreflight)->prepare([['metadata' => ['বাংলা' => 'https://example.invalid/😀']]], Currency::BDT);
            $this->assertSame(1, $calls);
            $this->assertSame('{"বাংলা":"https://example.invalid/😀"}', $plan[0]['metadata']);
        } finally {
            Json::encodeUsing(null);
        }
    }

    public function test_custom_encoder_cannot_bypass_valid_json_bytes(): void
    {
        foreach (['broken json', "\xC3\x28", 42, null] as $bad) {
            Json::encodeUsing(fn () => $bad);
            try {
                (new LedgerEntryPayloadPreflight)->prepare([['metadata' => ['x']]], Currency::THB);
                $this->fail('Expected invalid custom encoding refusal.');
            } catch (FinancialException $error) {
                $this->assertSame('ledger_entry_metadata_invalid', $error->errorCode());
            } finally {
                Json::encodeUsing(null);
            }
        }
    }

    public function test_later_text_failure_has_only_safe_context_and_leaves_input_unchanged(): void
    {
        $entries = [['description' => 'first'], ['reference_type' => str_repeat('x', 256)]];
        $before = $entries;
        try {
            (new LedgerEntryPayloadPreflight)->prepare($entries, Currency::THB);
            $this->fail('Expected later text refusal.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_entry_reference_type_too_long', $error->errorCode());
            $this->assertSame(['entry_index' => 1, 'field' => 'reference_type', 'currency' => 'THB'], $error->context());
            $this->assertSame($before, $entries);
        }
    }
}
