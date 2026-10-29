<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

/**
 * Decodes validated provider JSON without turning JSON numbers into floats.
 * JSON number lexemes are returned as strings; ordinary strings, booleans and
 * null retain their normal meaning. Duplicate object keys are rejected, even
 * when the duplicate is expressed using a JSON escape sequence.
 *
 * Use only when a field must be inspected exactly (for example, money). Do not
 * treat the strings returned here as proof that the original JSON type was a
 * string: use the ordinary decoded payload for boolean/type checks.
 */
final class LosslessJson
{
    private int $offset = 0;

    private function __construct(private readonly string $source) {}

    /** @throws JsonException */
    public static function decode(string $source): mixed
    {
        // Let PHP validate the JSON grammar, UTF-8 and the nesting limit first.
        json_decode($source, true, 64, JSON_THROW_ON_ERROR);
        $parser = new self($source);
        $value = $parser->readValue();
        $parser->skipWhitespace();
        if ($parser->offset !== strlen($source)) {
            throw new JsonException('Unexpected content after JSON value.');
        }

        return $value;
    }

    /** @throws JsonException */
    private function readValue(): mixed
    {
        $this->skipWhitespace();
        $byte = $this->source[$this->offset] ?? '';
        if ($byte === '{') {
            return $this->readObject();
        }
        if ($byte === '[') {
            return $this->readArray();
        }
        if ($byte === '"') {
            return $this->readString();
        }
        if ($byte === 't') {
            $this->offset += 4;

            return true;
        }
        if ($byte === 'f') {
            $this->offset += 5;

            return false;
        }
        if ($byte === 'n') {
            $this->offset += 4;

            return null;
        }
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $this->source, $match, 0, $this->offset) !== 1) {
            throw new JsonException('Invalid JSON value.');
        }
        $this->offset += strlen($match[0]);

        return $match[0];
    }

    /** @return array<string|int, mixed> @throws JsonException */
    private function readObject(): array
    {
        $this->offset++; // {
        $this->skipWhitespace();
        if (($this->source[$this->offset] ?? '') === '}') {
            $this->offset++;

            return [];
        }
        $result = [];
        $seen = [];
        while (true) {
            $this->skipWhitespace();
            if (($this->source[$this->offset] ?? '') !== '"') {
                throw new JsonException('Invalid JSON object key.');
            }
            $key = $this->readString();
            // Prefix prevents PHP from coercing a numeric-looking key to int.
            $identity = ':'.$key;
            if (array_key_exists($identity, $seen)) {
                throw new JsonException('Duplicate JSON object key.');
            }
            $seen[$identity] = true;
            $this->skipWhitespace();
            if (($this->source[$this->offset] ?? '') !== ':') {
                throw new JsonException('Invalid JSON object separator.');
            }
            $this->offset++;
            $result[$key] = $this->readValue();
            $this->skipWhitespace();
            $separator = $this->source[$this->offset] ?? '';
            $this->offset++;
            if ($separator === '}') {
                return $result;
            }
            if ($separator !== ',') {
                throw new JsonException('Invalid JSON object delimiter.');
            }
        }
    }

    /** @return list<mixed> @throws JsonException */
    private function readArray(): array
    {
        $this->offset++; // [
        $this->skipWhitespace();
        if (($this->source[$this->offset] ?? '') === ']') {
            $this->offset++;

            return [];
        }
        $result = [];
        while (true) {
            $result[] = $this->readValue();
            $this->skipWhitespace();
            $separator = $this->source[$this->offset] ?? '';
            $this->offset++;
            if ($separator === ']') {
                return $result;
            }
            if ($separator !== ',') {
                throw new JsonException('Invalid JSON array delimiter.');
            }
        }
    }

    /** @throws JsonException */
    private function readString(): string
    {
        $start = $this->offset++;
        $length = strlen($this->source);
        while ($this->offset < $length) {
            $byte = $this->source[$this->offset++];
            if ($byte === '\\') {
                $this->offset++; // escaped character; syntax was validated above
            } elseif ($byte === '"') {
                $raw = substr($this->source, $start, $this->offset - $start);
                $decoded = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
                if (! is_string($decoded)) {
                    throw new JsonException('Invalid JSON string.');
                }

                return $decoded;
            }
        }
        throw new JsonException('Unterminated JSON string.');
    }

    private function skipWhitespace(): void
    {
        $length = strlen($this->source);
        while ($this->offset < $length && str_contains(" \t\r\n", $this->source[$this->offset])) {
            $this->offset++;
        }
    }
}
