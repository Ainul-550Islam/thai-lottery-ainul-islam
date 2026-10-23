<?php

declare(strict_types=1);

namespace App\Services\Legal;

use App\Models\LegalDocument;
use App\Models\LegalDocumentPublication;
use App\Models\User;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Resolves, versions, and publishes the public Terms and Privacy Notice.
 *
 * A deployment's current config-backed page remains the fallback until an
 * operator publishes a database version. Published versions cannot be edited;
 * a change is authored as a new draft and atomically becomes the one current
 * version for its document type and locale.
 */
final class LegalDocumentService
{
    public function __construct(
        private readonly PublicPageDataService $publicPages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function currentData(string $documentType, string $locale): array
    {
        if ($locale === 'bn') {
            if (! in_array($documentType, ['terms', 'privacy'], true)) {
                throw ValidationException::withMessages([
                    'document_type' => 'Supported legal document types are terms and privacy.',
                ]);
            }

            return $this->withHash($this->fallbackData($documentType, $locale), $documentType);
        }

        $this->assertSupported($documentType, $locale);
        $fallback = $this->fallbackData($documentType, $locale);

        $publication = LegalDocumentPublication::query()
            ->with('document')
            ->where('document_type', $documentType)
            ->where('locale', $locale)
            ->first();

        $document = $publication?->document;

        if (! $document instanceof LegalDocument
            || $document->status !== 'published'
            || $document->effective_at->toDateString() > today()->toDateString()) {
            return $this->withHash($fallback, $documentType);
        }

        $content = $this->contentFromDocument($document);

        return array_replace($fallback, $content, [
            'status' => 'AVAILABLE',
            'locale' => $locale,
            'version' => $document->version,
            'effective_at' => $document->effective_at->toDateString(),
            'updated_at' => $document->published_at?->toDateString() ?? $document->effective_at->toDateString(),
            'content_hash' => $document->content_hash,
            'legal_document_id' => (int) $document->getKey(),
        ]);
    }

    /**
     * @return array{
     *     document_type: string,
     *     locale: string,
     *     version: string,
     *     hash: string,
     *     legal_document_id: int|null,
     *     effective_at: string,
     *     content: array<string, mixed>
     * }
     */
    public function currentSnapshot(string $documentType, string $locale): array
    {
        $data = $this->currentData($documentType, $locale);

        if (($data['status'] ?? null) !== 'AVAILABLE'
            || ! is_array($data['sections'] ?? null)
            || $data['sections'] === []) {
            throw new LogicException('The current legal document is unavailable or has no sections.');
        }

        return [
            'document_type' => $documentType,
            'locale' => $locale,
            'version' => (string) ($data['version'] ?? ''),
            'hash' => (string) ($data['content_hash'] ?? ''),
            'legal_document_id' => isset($data['legal_document_id']) ? (int) $data['legal_document_id'] : null,
            'effective_at' => (string) ($data['effective_at'] ?? ''),
            'content' => $this->contentFromData($data),
        ];
    }

    /**
     * Return the current config-backed copy as an editable admin starting point.
     *
     * @return array<string, mixed>
     */
    public function template(string $documentType, string $locale): array
    {
        $this->assertSupported($documentType, $locale);

        return $this->contentFromData($this->fallbackData($documentType, $locale));
    }

    /** @param array<string, mixed> $validated */
    public function createDraft(array $validated, User $actor): LegalDocument
    {
        $content = $this->validatedContent($validated);

        $document = new LegalDocument;
        $document->fill([
            'document_type' => (string) $validated['document_type'],
            'locale' => (string) $validated['locale'],
            'version' => trim((string) $validated['version']),
            'title' => $content['title'],
            'meta_title' => $content['meta_title'],
            'meta_description' => $content['meta_description'],
            'sections' => $content['sections'],
            'content_hash' => $this->hash($content),
            'effective_at' => (string) $validated['effective_at'],
            'status' => 'draft',
            'created_by' => (int) $actor->getKey(),
        ]);
        $document->save();

        return $document->refresh();
    }

    /** @param array<string, mixed> $validated */
    public function updateDraft(int $documentId, array $validated): LegalDocument
    {
        return DB::transaction(function () use ($documentId, $validated): LegalDocument {
            $document = LegalDocument::query()->lockForUpdate()->findOrFail($documentId);

            if ($document->status !== 'draft') {
                throw ValidationException::withMessages([
                    'document' => 'Only draft legal documents may be edited. Published versions are immutable.',
                ]);
            }

            $documentType = (string) $validated['document_type'];
            $locale = (string) $validated['locale'];
            $this->assertSupported($documentType, $locale);

            if ($document->document_type !== $documentType || $document->locale !== $locale) {
                throw ValidationException::withMessages([
                    'document_type' => 'A draft cannot be moved to a different document type or locale.',
                ]);
            }

            $content = $this->validatedContent($validated);
            $document->fill([
                'version' => trim((string) $validated['version']),
                'title' => $content['title'],
                'meta_title' => $content['meta_title'],
                'meta_description' => $content['meta_description'],
                'sections' => $content['sections'],
                'content_hash' => $this->hash($content),
                'effective_at' => (string) $validated['effective_at'],
            ]);
            $document->save();

            return $document->refresh();
        }, 3);
    }

    public function publish(int $documentId, User $actor): LegalDocument
    {
        return DB::transaction(function () use ($documentId, $actor): LegalDocument {
            $document = LegalDocument::query()->lockForUpdate()->findOrFail($documentId);

            if ($document->status !== 'draft') {
                throw ValidationException::withMessages([
                    'document' => 'Only a draft legal document may be published.',
                ]);
            }

            if ($document->effective_at->toDateString() > today()->toDateString()) {
                throw ValidationException::withMessages([
                    'effective_at' => 'Future publication dates are not supported; publish on or after the effective date.',
                ]);
            }

            $now = now();
            $where = [
                'document_type' => (string) $document->document_type,
                'locale' => (string) $document->locale,
            ];

            $publication = LegalDocumentPublication::query()
                ->where($where)
                ->lockForUpdate()
                ->first();

            if (! $publication instanceof LegalDocumentPublication) {
                DB::table('legal_document_publications')->insertOrIgnore([
                    ...$where,
                    'legal_document_id' => (int) $document->getKey(),
                    'published_by' => (int) $actor->getKey(),
                    'published_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $publication = LegalDocumentPublication::query()
                    ->where($where)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $publication instanceof LegalDocumentPublication) {
                throw new LogicException('The legal publication pointer could not be created or loaded.');
            }

            $previousId = (int) $publication->legal_document_id;

            if ($previousId !== (int) $document->getKey()) {
                $previous = LegalDocument::query()->lockForUpdate()->find($previousId);

                if ($previous instanceof LegalDocument && $previous->status === 'published') {
                    $previous->status = 'superseded';
                    $previous->save();
                }

                $publication->legal_document_id = (int) $document->getKey();
            }

            $publication->published_by = (int) $actor->getKey();
            $publication->published_at = $now;
            $publication->save();

            $document->status = 'published';
            $document->published_by = (int) $actor->getKey();
            $document->published_at = $now;
            $document->save();

            return $document->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $validated
     *  @return array{title: string, meta_title: string, meta_description: string, sections: array<int, array<string, mixed>>}
     */
    private function validatedContent(array $validated): array
    {
        return [
            'title' => trim((string) $validated['title']),
            'meta_title' => trim((string) $validated['meta_title']),
            'meta_description' => trim((string) $validated['meta_description']),
            'sections' => array_values((array) $validated['sections']),
        ];
    }

    /** @return array{title: string, meta_title: string, meta_description: string, sections: array<int, array<string, mixed>>} */
    private function contentFromDocument(LegalDocument $document): array
    {
        return [
            'title' => (string) $document->title,
            'meta_title' => (string) $document->meta_title,
            'meta_description' => (string) $document->meta_description,
            'sections' => is_array($document->sections) ? array_values($document->sections) : [],
        ];
    }

    /** @param array<string, mixed> $data
     *  @return array{title: string, meta_title: string, meta_description: string, sections: array<int, mixed>}
     */
    private function contentFromData(array $data): array
    {
        return [
            'title' => (string) ($data['title'] ?? ''),
            'meta_title' => (string) ($data['meta_title'] ?? ''),
            'meta_description' => (string) ($data['meta_description'] ?? ''),
            'sections' => is_array($data['sections'] ?? null) ? array_values($data['sections']) : [],
        ];
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function withHash(array $data, string $documentType): array
    {
        $data['content_hash'] = $this->hash($this->contentFromData($data));
        $data['legal_document_id'] = null;
        $data['document_type'] = $documentType;

        return $data;
    }

    /** @param array<string, mixed> $content */
    private function hash(array $content): string
    {
        $canonical = $this->sortRecursively($content);

        return hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function fallbackData(string $documentType, string $locale): array
    {
        return match ($documentType) {
            'terms' => $this->publicPages->terms($locale),
            'privacy' => $this->publicPages->privacy($locale),
            default => throw ValidationException::withMessages([
                'document_type' => 'Unsupported legal document type.',
            ]),
        };
    }

    private function assertSupported(string $documentType, string $locale): void
    {
        if (! in_array($documentType, ['terms', 'privacy'], true)) {
            throw ValidationException::withMessages([
                'document_type' => 'Supported legal document types are terms and privacy.',
            ]);
        }

        if (! in_array($locale, ['en', 'th'], true)) {
            throw ValidationException::withMessages([
                'locale' => 'Supported legal document locales are en and th.',
            ]);
        }
    }
}
