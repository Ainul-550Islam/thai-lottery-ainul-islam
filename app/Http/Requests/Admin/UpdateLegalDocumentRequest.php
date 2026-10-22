<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

final class UpdateLegalDocumentRequest extends LegalDocumentPayloadRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->payloadRules($this->routeDocumentId());
    }
}
