<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\WithdrawalReconciliationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/** Local private, content-addressed encrypted evidence. Never fetches caller URLs or parses active content. */
final class WithdrawalReconciliationEvidenceStore
{
    private function disk(): Filesystem
    {
        return Storage::build(['driver' => 'local', 'root' => storage_path('app/private/withdrawal-reconciliation'), 'visibility' => 'private', 'throw' => true]);
    }

    public function store(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > 2097152
            || ! in_array($file->getMimeType(), ['application/pdf', 'application/json', 'text/plain'], true)) {
            throw new WithdrawalReconciliationException('invalid_private_evidence');
        }
        $bytes = file_get_contents($file->getRealPath());
        $hash = hash('sha256', $bytes);
        if (! $this->disk()->exists($hash)) {
            $this->disk()->put($hash, Crypt::encryptString($bytes), ['visibility' => 'private']);
        }
        $this->read($hash, strlen($bytes));

        return ['sha256' => $hash, 'bytes' => strlen($bytes)];
    }

    public function read(string $hash, int $size): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1 || $size < 1 || $size > 2097152) {
            throw new WithdrawalReconciliationException('invalid_private_evidence');
        }
        try {
            $bytes = Crypt::decryptString($this->disk()->get($hash));
            if (strlen($bytes) !== $size || ! hash_equals($hash, hash('sha256', $bytes))) {
                throw new \RuntimeException;
            }

            return $bytes;
        } catch (\Throwable) {
            throw new WithdrawalReconciliationException('private_evidence_missing_or_corrupt');
        }
    }
}
