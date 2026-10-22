<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\StoreLegalDocumentRequest;
use App\Http\Requests\Admin\UpdateLegalDocumentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\LegalDocument;
use App\Models\User;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Least-privilege API for drafting and publishing versioned legal pages. */
final class LegalDocumentAdminController
{
    public function __construct(
        private readonly LegalDocumentService $documents,
    ) {}

    public function index(): JsonResponse
    {
        $documents = LegalDocument::query()
            ->orderBy('document_type')
            ->orderBy('locale')
            ->orderByDesc('id')
            ->paginate(50);

        return ApiResponse::success(
            data: [
                'items' => array_map(
                    fn (LegalDocument $document): array => $this->serialize($document),
                    $documents->items(),
                ),
                'pagination' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                ],
            ],
            message: 'Versioned legal documents.',
        );
    }

    public function template(string $type, string $locale): JsonResponse
    {
        return ApiResponse::success(
            data: [
                'document_type' => $type,
                'locale' => $locale,
                'content' => $this->documents->template($type, $locale),
            ],
            message: 'Editable starting content from the current config-backed legal page.',
        );
    }

    public function store(StoreLegalDocumentRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $document = $this->documents->createDraft($request->validated(), $actor);

        return ApiResponse::success(
            data: $this->serialize($document),
            message: 'Legal-document draft created.',
            status: 201,
        );
    }

    public function show(LegalDocument $document): JsonResponse
    {
        return ApiResponse::success(
            data: $this->serialize($document),
            message: 'Legal-document version.',
        );
    }

    public function update(UpdateLegalDocumentRequest $request, LegalDocument $document): JsonResponse
    {
        $updated = $this->documents->updateDraft(
            (int) $document->getKey(),
            $request->validated(),
        );

        return ApiResponse::success(
            data: $this->serialize($updated),
            message: 'Legal-document draft updated.',
        );
    }

    public function publish(Request $request, LegalDocument $document): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $published = $this->documents->publish((int) $document->getKey(), $actor);

        return ApiResponse::success(
            data: $this->serialize($published),
            message: 'Legal-document version published.',
        );
    }

    /** @return array<string, mixed> */
    private function serialize(LegalDocument $document): array
    {
        return [
            'id' => (int) $document->getKey(),
            'document_type' => $document->document_type,
            'locale' => $document->locale,
            'version' => $document->version,
            'title' => $document->title,
            'meta_title' => $document->meta_title,
            'meta_description' => $document->meta_description,
            'sections' => $document->sections,
            'content_hash' => $document->content_hash,
            'effective_at' => $document->effective_at?->toDateString(),
            'status' => $document->status,
            'created_by' => $document->created_by,
            'published_by' => $document->published_by,
            'published_at' => $document->published_at?->toIso8601String(),
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}
