<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Models\LedgerEntry;
use Throwable;

/**
 * Freeze the complete ledger text/JSON payload before posting DML.
 *
 * This is a storage codec, not arithmetic, transaction ownership, a foreign-key
 * check or a sandbox for trusted PHP serialization callbacks. The real model
 * cast is used once; the writer must consume its encoded bytes without running
 * that cast a second time. Database hooks and other storage faults still need
 * the caller's whole-operation rollback.
 */
final class LedgerEntryPayloadPreflight
{
    /**
     * @param  list<array<string, mixed>>  $entries  structurally normalised drafts
     * @return list<array{description: string|null, reference_type: string|null, metadata: string|null}>
     */
    public function prepare(array $entries, Currency $currency, ?string $defaultDescription = null, ?string $defaultReferenceType = null): array
    {
        $plan = [];
        foreach ($entries as $index => $entry) {
            $description = $entry['description'] ?? $defaultDescription;
            $referenceType = $entry['reference_type'] ?? $defaultReferenceType;
            $this->text($description, 'description', $index, $currency);
            $this->text($referenceType, 'reference_type', $index, $currency);

            try {
                // Match LedgerEntry's existing array cast, including an installed
                // Eloquent JSON encoder. A fresh staging model is never saved.
                $probe = new LedgerEntry;
                $probe->metadata = $entry['metadata'] ?? null;
                $encoded = $probe->getAttributes()['metadata'] ?? null;
                if (($entry['metadata'] ?? null) !== null) {
                    if (! is_string($encoded)) {
                        throw new \UnexpectedValueException('Non-string JSON encoding.');
                    }
                    // A custom encoder must not bypass JSON syntax/UTF-8 checks.
                    json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
                }
            } catch (Throwable) {
                // Do not leak payloads, serializer messages or exception chains.
                $this->refuse('metadata', 'invalid', $index, $currency);
            }

            $plan[] = ['description' => $description, 'reference_type' => $referenceType, 'metadata' => $encoded];
        }

        return $plan;
    }

    private function text(mixed $value, string $field, int $index, Currency $currency): void
    {
        if ($value === null) {
            return;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->refuse($field, 'invalid', $index, $currency);
        }
        // VARCHAR(255) counts Unicode characters, not UTF-8 bytes. Do not trim,
        // normalise, truncate or replace characters in an immutable audit value.
        if (mb_strlen($value, 'UTF-8') > 255) {
            $this->refuse($field, 'too_long', $index, $currency);
        }
    }

    private function refuse(string $field, string $reason, int $index, Currency $currency): never
    {
        throw FinancialException::withCode(
            'ledger_entry_'.$field.'_'.$reason,
            'A ledger entry payload cannot be stored losslessly.',
            ['entry_index' => $index, 'field' => $field, 'currency' => $currency->value],
        );
    }
}
