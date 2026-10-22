<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\LegalDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class LegalDocumentPayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-legal-documents') === true;
    }

    /** @return array<string, array<int, mixed>> */
    protected function payloadRules(?int $ignoreId = null): array
    {
        $versionRule = Rule::unique('legal_documents', 'version')
            ->where(fn ($query) => $query
                ->where('document_type', $this->input('document_type'))
                ->where('locale', $this->input('locale')));

        if ($ignoreId !== null) {
            $versionRule->ignore($ignoreId);
        }

        return [
            'document_type' => ['required', 'string', Rule::in(['terms', 'privacy'])],
            'locale' => ['required', 'string', Rule::in(['en', 'th'])],
            'version' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $versionRule],
            'title' => ['required', 'string', 'max:255'],
            'meta_title' => ['required', 'string', 'max:255'],
            'meta_description' => ['required', 'string', 'max:2000'],
            'effective_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sections' => ['required', 'array', 'min:1', 'max:50'],
            'sections.*' => ['required', 'array:id,title,body,extra'],
            'sections.*.id' => ['required', 'string', 'alpha_dash', 'max:80', 'distinct'],
            'sections.*.title' => ['required', 'string', 'max:255'],
            'sections.*.body' => ['required', 'string', 'max:12000'],
            'sections.*.extra' => ['sometimes', 'array', 'max:10'],
            'sections.*.extra.*' => ['required', 'string', 'max:12000'],
        ];
    }

    protected function routeDocumentId(): ?int
    {
        $document = $this->route('document');

        if ($document instanceof LegalDocument) {
            return (int) $document->getKey();
        }

        return is_numeric($document) ? (int) $document : null;
    }
}
