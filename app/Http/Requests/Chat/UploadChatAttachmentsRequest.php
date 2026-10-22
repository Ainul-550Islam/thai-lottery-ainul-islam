<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an attachment upload BEFORE the service touches the disk.
 *
 * ============================================================================
 * THE TWO JOBS THIS REQUEST DOES
 * ============================================================================
 * 1. Reject structurally wrong uploads (no files, too many files) with the
 *    standard validation envelope, before anything is stored.
 * 2. Enforce the per-file size ceiling at the framework level, so a 50 MB
 *    upload never even reaches finfo — the service's own size check remains
 *    as the second lock, because the service is reachable without this
 *    request (tests, console).
 *
 * MIME validation is deliberately NOT done here. The extension a browser
 * claims and the mime rule Laravel derives from it are both client-
 * influenced; the trust decision belongs to ChatAttachmentService, which
 * sniffs the BYTES. Listing 'mimes:' here would create the false impression
 * that the extension-based check is the security gate. It is not.
 */
final class UploadChatAttachmentsRequest extends FormRequest
{
    /**
     * The caller must already own an open conversation; the controller
     * re-verifies ownership after this request passes. Authorizing here
     * would duplicate that check in a place that cannot see the
     * conversation, so the request stays honest about what it can know.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxFiles = (int) config('chat.attachments.max_per_message', 3);
        $maxKilobytes = (int) config('chat.attachments.max_kb', 5120);

        return [
            'attachments' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'attachments.*' => ['required', 'file', 'max:'.$maxKilobytes],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachments.required' => 'Choose at least one file to attach.',
            'attachments.max' => 'Too many files were attached to one message.',
            'attachments.*.max' => 'That file is larger than this chat accepts.',
            'attachments.*.file' => 'Each attachment must be an uploaded file.',
        ];
    }

    /**
     * The uploaded files, as the attachment service consumes them.
     *
     * @return array<int, \Illuminate\Http\UploadedFile>
     */
    public function attachmentFiles(): array
    {
        $files = $this->file('attachments');

        return is_array($files) ? array_values($files) : [];
    }
}
