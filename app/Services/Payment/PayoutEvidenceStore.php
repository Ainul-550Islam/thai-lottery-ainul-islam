<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Private encrypted inert bytes. No caller URL, client filename, public disk, document parser or authenticity claim. */
final class PayoutEvidenceStore
{
    private function disk(): Filesystem
    {
        return Storage::build(['driver' => 'local', 'root' => storage_path('app/private/payout-evidence'), 'visibility' => 'private', 'throw' => true]);
    }

    public function store(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() < 1 || $file->getSize() > 2097152
            || ! in_array($file->getMimeType(), ['application/pdf', 'application/json', 'text/plain'], true)) {
            $this->refuse();
        }
        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > 2097152) {
            $this->refuse();
        }
        $record = ['blob_key' => bin2hex(random_bytes(32)), 'sha256' => hash('sha256', $bytes),
            'bytes' => strlen($bytes), 'mime' => $file->getMimeType()];
        try {
            $this->disk()->put($record['blob_key'], Crypt::encryptString($bytes), ['visibility' => 'private']);
            $this->read((object) $record);
        } catch (\Throwable) {
            $this->discardUncommitted($record['blob_key']);
            $this->refuse();
        }

        return $record;
    }

    public function read(object $record): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $record->blob_key ?? '') !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $record->sha256 ?? '') !== 1 || $record->bytes < 1 || $record->bytes > 2097152) {
            $this->refuse();
        }
        try {
            $bytes = Crypt::decryptString($this->disk()->get($record->blob_key));
            if (strlen($bytes) !== (int) $record->bytes || ! hash_equals($record->sha256, hash('sha256', $bytes))) {
                $this->refuse();
            }

            return $bytes;
        } catch (\Throwable) {
            $this->refuse();
        }
    }

    /** Only a just-created uncommitted random object may be removed by upload failure cleanup. No retained-evidence deletion API. */
    public function discardUncommitted(string $key): void
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $key) !== 1) {
            $this->refuse();
        }
        try {
            if (DB::table('payout_transfer_evidence')->where('blob_key', $key)->exists()) {
                return; // Never delete an object referenced by a retained SQL archive.
            }
            $this->disk()->delete($key);
        } catch (\Throwable) {
            // A crash/failed cleanup can leave an encrypted orphan, never a valid archived SQL record.
        }
    }

    private function refuse(): never
    {
        throw FinancialException::withCode('private_payout_evidence_invalid', 'Private evidence is missing, invalid or corrupt; preserve uncertainty and original financial history.');
    }
}
