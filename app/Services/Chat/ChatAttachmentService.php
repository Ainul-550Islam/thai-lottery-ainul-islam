<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Exceptions\ChatException;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatMessage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Validated, non-public storage for chat message attachments.
 */
final class ChatAttachmentService
{
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar',
        'sh', 'bash', 'zsh', 'exe', 'bat', 'cmd', 'com', 'msi',
        'js', 'mjs', 'cjs', 'html', 'htm', 'xhtml', 'svg', 'swf',
        'py', 'rb', 'pl', 'cgi', 'asp', 'aspx', 'jsp',
    ];

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{disk: string, path: string, original_name: string, mime: string, size: int}>
     */
    public function storeMany(ChatConversation $conversation, array $files): array
    {
        if ($files === []) {
            return [];
        }

        if (! (bool) config('chat.attachments.enabled', true)) {
            throw ChatException::attachmentRejected('Chat attachments are currently disabled.');
        }

        $maxPerMessage = max(1, (int) config('chat.attachments.max_per_message', 3));
        if (count($files) > $maxPerMessage) {
            throw ChatException::attachmentRejected(
                "At most {$maxPerMessage} attachments may be sent in a single message.",
            );
        }

        $disk = (string) config('chat.attachments.disk', 'local');
        $baseDir = trim((string) config('chat.attachments.prefix', 'chat_attachments'), '/');
        $maxBytes = max(1, (int) config('chat.attachments.max_kb', 5120)) * 1024;
        /** @var list<string> $allowedMimes */
        $allowedMimes = array_values((array) config('chat.attachments.allowed_mimes', []));
        /** @var list<string> $allowedExtensions */
        $allowedExtensions = array_map(
            static fn (string $ext): string => strtolower($ext),
            array_values((array) config('chat.attachments.allowed_extensions', [])),
        );

        $stored = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw ChatException::attachmentRejected('Uploaded file is invalid.');
            }

            $size = (int) $file->getSize();
            if ($size <= 0 || $size > $maxBytes) {
                throw ChatException::attachmentRejected('Attachment exceeds maximum allowed size.');
            }

            $originalName = $this->sanitiseOriginalName((string) $file->getClientOriginalName());
            $this->assertSafeExtensions($originalName, $allowedExtensions);

            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
                throw ChatException::attachmentRejected("File extension '.{$extension}' is not allowed.");
            }

            $detectedMime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
            if ($detectedMime === '' || ! in_array($detectedMime, $allowedMimes, true)) {
                throw ChatException::attachmentRejected("File MIME type '{$detectedMime}' is not allowed.");
            }

            $targetDir = $baseDir.'/'.$conversation->getKey();
            $targetFilename = Str::uuid()->toString().'.'.$extension;
            $storedPath = $file->storeAs($targetDir, $targetFilename, ['disk' => $disk]);

            if (! is_string($storedPath) || $storedPath === '') {
                throw ChatException::attachmentRejected('Unable to persist attachment.');
            }

            $stored[] = [
                'disk' => $disk,
                'path' => $storedPath,
                'original_name' => $originalName,
                'mime' => $detectedMime,
                'size' => $size,
            ];
        }

        return $stored;
    }

    /**
     * @return array{disk: Filesystem, path: string, original_name: string, mime: string, size: int}
     */
    public function resolveForDownload(ChatMessage $message, int $index): array
    {
        $attachments = is_array($message->attachments) ? array_values($message->attachments) : [];

        if (! isset($attachments[$index]) || ! is_array($attachments[$index])) {
            throw ChatException::attachmentNotFound($index);
        }

        $record = $attachments[$index];
        $diskName = (string) ($record['disk'] ?? config('chat.attachments.disk', 'local'));
        $path = (string) ($record['path'] ?? '');
        $disk = Storage::disk($diskName);

        if ($path === '' || ! $disk->exists($path)) {
            throw ChatException::attachmentNotFound($index);
        }

        return [
            'disk' => $disk,
            'path' => $path,
            'original_name' => (string) ($record['original_name'] ?? 'attachment'),
            'mime' => (string) ($record['mime'] ?? 'application/octet-stream'),
            'size' => (int) ($record['size'] ?? 0),
        ];
    }

    private function sanitiseOriginalName(string $name): string
    {
        $basename = basename(str_replace('\\', '/', $name));
        $cleaned = preg_replace('/[^\w.\- ]+/u', '_', $basename) ?? 'attachment';
        $trimmed = trim($cleaned, " ._\t\n\r\0\x0B");

        return $trimmed !== '' ? mb_substr($trimmed, 0, 120) : 'attachment';
    }

    /**
     * @param  list<string>  $allowedExtensions
     */
    private function assertSafeExtensions(string $filename, array $allowedExtensions): void
    {
        $parts = explode('.', strtolower($filename));
        if (count($parts) < 2) {
            throw ChatException::attachmentRejected('Attachment must have a valid file extension.');
        }

        array_shift($parts);
        foreach ($parts as $part) {
            if (in_array($part, self::FORBIDDEN_EXTENSIONS, true)) {
                throw ChatException::attachmentRejected("Executable or script extension '.{$part}' is forbidden.");
            }
        }

        $lastExtension = end($parts);
        if (! is_string($lastExtension) || ! in_array($lastExtension, $allowedExtensions, true)) {
            throw ChatException::attachmentRejected('File extension is not permitted.');
        }
    }
}
