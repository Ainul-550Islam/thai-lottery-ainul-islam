<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Services\Security\FieldEncryptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class ReencryptEncryptedFieldsCommand extends Command
{
    protected $signature = 'security:reencrypt-fields
        {--from-app-key : Decrypt legacy values with APP_KEY}
        {--dry-run : Count legacy values without changing them}
        {--chunk=200 : Rows to process per transaction}
        {--i-have-a-backup : Confirm that a restorable production backup exists}';

    protected $description = 'Rotate FieldEncryptionService values to the dedicated field-encryption key.';

    /** @var array<string, list<string>> */
    private const PROTECTED_COLUMNS = [
        'kyc_documents' => ['document_number'],
    ];

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('i-have-a-backup')) {
            $this->printCounts(0, 0, 0);

            return self::FAILURE;
        }

        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        $newKey = config('app.field_encryption_key');
        $appKey = config('app.key');
        if ($chunk === false || ! is_string($newKey) || trim($newKey) === ''
            || (is_string($appKey) && hash_equals($newKey, $appKey))) {
            $this->printCounts(0, 0, 0);

            return self::FAILURE;
        }

        $legacyKeys = [];
        if ($this->option('from-app-key')) {
            if (! is_string($appKey) || trim($appKey) === '') {
                $this->printCounts(0, 0, 0);

                return self::FAILURE;
            }
            $legacyKeys[] = $appKey;
        }
        foreach ((array) config('app.field_encryption_previous_keys', []) as $key) {
            if (is_string($key) && trim($key) !== '' && ! hash_equals($newKey, $key)) {
                $legacyKeys[] = $key;
            }
        }
        $legacyKeys = array_values(array_unique($legacyKeys, SORT_STRING));

        try {
            $encryption = new FieldEncryptionService($newKey);
            $counts = $this->rotate($encryption, $legacyKeys, (int) $chunk, (bool) $this->option('dry-run'));
        } catch (Throwable) {
            $this->printCounts(0, 0, 0);

            return self::FAILURE;
        }

        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($counts): void {
                $audit = new AuditLog;
                $audit->fill([
                    'user_id' => null,
                    'action' => AuditAction::ConfigChange,
                    'risk_level' => RiskLevel::High,
                    'auditable_type' => null,
                    'auditable_id' => null,
                    'description' => 'Field-encryption key rotation completed.',
                    'new_values' => $counts,
                    'metadata' => ['command' => 'security:reencrypt-fields'],
                ]);
                $audit->save();
            });
        }

        $this->printCounts($counts['scanned'], $counts['rotated'], $counts['skipped']);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $legacyKeys
     * @return array{scanned: int, rotated: int, skipped: int}
     */
    private function rotate(FieldEncryptionService $encryption, array $legacyKeys, int $chunk, bool $dryRun): array
    {
        $counts = ['scanned' => 0, 'rotated' => 0, 'skipped' => 0];

        foreach (self::PROTECTED_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $lastId = 0;

            do {
                $rows = DB::transaction(function () use ($table, $columns, $lastId, $chunk, $encryption, $legacyKeys, $dryRun, &$counts): array {
                    $rows = DB::table($table)->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->lockForUpdate()->get();
                    $changed = [];

                    foreach ($rows as $row) {
                        foreach ($columns as $column) {
                            if (! Schema::hasColumn($table, $column)) {
                                continue;
                            }
                            $value = $row->{$column};
                            if (! is_string($value) || $value === '') {
                                continue;
                            }
                            $counts['scanned']++;

                            if ($this->decrypts($encryption, $value)) {
                                $counts['skipped']++;

                                continue;
                            }

                            $plain = null;
                            foreach ($legacyKeys as $legacyKey) {
                                try {
                                    $plain = $encryption->decryptWithKey($value, $legacyKey);
                                    break;
                                } catch (Throwable) {
                                    // Try the next explicitly configured legacy key.
                                }
                            }

                            if ($plain === null) {
                                if ($this->hasEncryptedShape($value)) {
                                    throw new RuntimeException('An encrypted field could not be decrypted with a configured key.');
                                }
                                $counts['skipped']++;

                                continue;
                            }

                            $counts['rotated']++;
                            if (! $dryRun) {
                                $changed[] = ['id' => (int) $row->id, 'column' => $column, 'before' => $value, 'after' => $encryption->encrypt($plain)];
                            }
                        }
                    }

                    if (! $dryRun) {
                        foreach ($changed as $update) {
                            $updated = DB::table($table)->where('id', $update['id'])->where($update['column'], $update['before'])
                                ->update([$update['column'] => $update['after']]);
                            if ($updated !== 1) {
                                throw new RuntimeException('A protected field changed during key rotation.');
                            }
                        }
                    }

                    return $rows->all();
                }, 3);

                if ($rows !== []) {
                    $lastId = (int) end($rows)->id;
                }
            } while (count($rows) === $chunk);
        }

        return $counts;
    }

    private function decrypts(FieldEncryptionService $encryption, string $value): bool
    {
        try {
            $encryption->decrypt($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasEncryptedShape(string $value): bool
    {
        $decoded = base64_decode($value, true);

        return is_string($decoded) && strlen($decoded) >= 28 && base64_encode($decoded) === $value;
    }

    private function printCounts(int $scanned, int $rotated, int $skipped): void
    {
        $this->output->writeln(sprintf('scanned=%d rotated=%d skipped=%d', $scanned, $rotated, $skipped));
    }
}
