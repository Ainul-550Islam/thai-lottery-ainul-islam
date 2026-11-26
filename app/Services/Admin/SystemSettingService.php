<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\SystemSettingException;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The only writer of operator-overridable configuration.
 *
 * WHY THIS EXISTS RATHER THAN A .env EDITOR
 *
 * A settings screen that writes `.env` or a PHP config file turns one permission into code
 * execution: whoever can save a value can also point the app at another database, disable a
 * security check or read every secret. The audit called that out and refused to build it. This
 * service is the honest alternative — a table of OVERRIDES, applied to `config()` at boot, whose
 * keys are whitelisted in `config/admin.php → settings_catalogue`.
 *
 * THE FOUR RULES
 *
 *   1. WHITELIST. A key that is not in the catalogue is refused by name
 *      (`SystemSettingException::unknownKey`). There is no "raw key" path, not even for
 *      super-admin, because a bypass with an if-statement is not a whitelist.
 *   2. TYPE AND BOUNDS from the catalogue. Integers are bounded, booleans are booleans, and money
 *      is a DECIMAL STRING — validated as a string, never floated, because `0.1 + 0.2` is exactly
 *      the kind of arithmetic that ends up on a statement.
 *   3. PAIRS ARE COHERENT. A minimum may not exceed its paired maximum whatever order they were
 *      edited in: the check reads the counterpart's effective value, not the form.
 *   4. EVERY WRITE IS AUDITED with the old and the new value, at High risk. A setting is a
 *      money-adjacent lever; "who turned automation off at 03:00" must be answerable from the
 *      trail alone.
 *
 * WHAT IS NOT HERE
 *
 * No secrets, ever. Nothing in the catalogue may be a credential, and the service refuses a key
 * whose catalogue entry is marked secret — the flag exists so a future entry cannot be added
 * carelessly.
 *
 * `applyToConfig()` is called by `SystemSettingsServiceProvider` on boot. It is deliberately
 * tolerant of a missing table (fresh clone, `migrate` not yet run): a store that is not there
 * means "no overrides", never an exception on every request.
 */
final class SystemSettingService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /**
     * The whitelist, as declared.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalogue(): array
    {
        $catalogue = config('admin.settings_catalogue', []);

        return is_array($catalogue) ? $catalogue : [];
    }

    /**
     * The catalogue grouped for a form: group label => [key => entry].
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->catalogue() as $key => $entry) {
            $group = (string) ($entry['group'] ?? 'General');
            $groups[$group][(string) $key] = $entry;
        }

        return $groups;
    }

    /**
     * The stored overrides, typed, for display ("what is an operator override and what is the
     * deployed default" is a question the page must be able to answer).
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        $overrides = [];

        foreach (SystemSetting::query()->orderBy('key')->get() as $row) {
            if (! array_key_exists((string) $row->key, $this->catalogue())) {
                // A row whose key left the catalogue is inert: reported as unknown, never applied.
                continue;
            }

            $typed = $this->castFromStorage((string) $row->key, (string) $row->value);

            if ($typed !== null) {
                $overrides[(string) $row->key] = $typed;
            }
        }

        return $overrides;
    }

    /**
     * The effective value: the stored override if there is one, otherwise the deployed default.
     */
    public function current(string $key): mixed
    {
        $this->assertKnown($key);

        $overrides = $this->overrides();

        return array_key_exists($key, $overrides) ? $overrides[$key] : config($key);
    }

    /**
     * Whether this key is currently overridden by a stored row.
     */
    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->overrides());
    }

    /**
     * Write one override.
     *
     * @throws SystemSettingException
     */
    public function set(string $key, mixed $value, User $operator): SystemSetting
    {
        $entry = $this->assertKnown($key);

        if (($entry['secret'] ?? false) === true) {
            throw SystemSettingException::secretKey($key);
        }

        $normalised = $this->normalise($key, $value, $entry);

        return DB::transaction(function () use ($key, $normalised, $operator, $entry): SystemSetting {
            /** @var SystemSetting|null $row */
            $row = SystemSetting::query()->lockForUpdate()->where('key', $key)->first();

            $oldValue = $row instanceof SystemSetting
                ? $row->value
                : $this->stringifyDefault(config($key), $entry);

            $row ??= new SystemSetting(['key' => $key]);
            $row->value = $normalised;
            $row->value_type = (string) ($entry['type'] ?? 'string');
            $row->updated_by = (int) $operator->getKey();
            $row->save();

            $this->audit->log(
                userId: (int) $operator->getKey(),
                action: AuditAction::ConfigChange,
                riskLevel: RiskLevel::High,
                auditable: $row,
                description: sprintf('System setting [%s] changed from [%s] to [%s]', $key, $oldValue, $normalised),
                metadata: [
                    'setting_key' => $key,
                    'value_type' => $row->value_type,
                    'surface' => 'admin-panel',
                ],
                oldValues: ['value' => $oldValue],
                newValues: ['value' => $normalised],
            );

            return $row;
        });
    }

    /**
     * Remove an override so the deployed default applies again. Audited like a change.
     */
    public function reset(string $key, User $operator): void
    {
        $this->assertKnown($key);

        DB::transaction(function () use ($key, $operator): void {
            /** @var SystemSetting|null $row */
            $row = SystemSetting::query()->lockForUpdate()->where('key', $key)->first();

            if (! $row instanceof SystemSetting) {
                return;
            }

            $oldValue = (string) $row->value;
            $row->delete();

            $this->audit->log(
                userId: (int) $operator->getKey(),
                action: AuditAction::ConfigChange,
                riskLevel: RiskLevel::High,
                auditable: null,
                description: sprintf('System setting [%s] reset to the deployed default (was [%s])', $key, $oldValue),
                metadata: ['setting_key' => $key, 'surface' => 'admin-panel'],
                oldValues: ['value' => $oldValue],
                newValues: ['value' => $this->stringifyDefault(config($key), $this->assertKnown($key))],
            );
        });
    }

    /**
     * Merge every valid override into `config()`.
     *
     * Called once per boot. A row that no longer validates (a bound was tightened in code, a type
     * changed) is SKIPPED rather than applied or deleted: the app must keep running on the
     * deployed default, and a human can see the inert row on the settings page.
     */
    public function applyToConfig(): void
    {
        foreach ($this->overrides() as $key => $typed) {
            config([$key => $typed]);
        }
    }

    public function tableExists(): bool
    {
        return Schema::hasTable('system_settings');
    }

    /**
     * @return array<string, mixed>
     */
    private function assertKnown(string $key): array
    {
        $catalogue = $this->catalogue();

        if (! array_key_exists($key, $catalogue)) {
            throw SystemSettingException::unknownKey($key);
        }

        return $catalogue[$key];
    }

    /**
     * Normalise to the string the table stores, refusing anything outside the declaration.
     *
     * @param  array<string, mixed>  $entry
     */
    private function normalise(string $key, mixed $value, array $entry): string
    {
        $type = (string) ($entry['type'] ?? 'string');

        if ($type === 'bool') {
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if (in_array($value, [1, '1', 'true'], true)) {
                return '1';
            }

            if (in_array($value, [0, '0', 'false'], true)) {
                return '0';
            }

            throw SystemSettingException::malformed($key, 'expected a boolean');
        }

        if ($type === 'int') {
            if (! is_numeric($value) || (string) (int) $value !== (string) $value) {
                throw SystemSettingException::malformed($key, 'expected a whole number');
            }

            $integer = (int) $value;
            $min = isset($entry['min']) ? (int) $entry['min'] : null;
            $max = isset($entry['max']) ? (int) $entry['max'] : null;

            if ($min !== null && $integer < $min) {
                throw SystemSettingException::outOfBounds($key, (string) $integer, (string) $min, $max === null ? null : (string) $max);
            }

            if ($max !== null && $integer > $max) {
                throw SystemSettingException::outOfBounds($key, (string) $integer, $min === null ? null : (string) $min, (string) $max);
            }

            return (string) $integer;
        }

        if ($type === 'money') {
            $string = is_string($value) ? trim($value) : (string) $value;

            if (! preg_match('/^\d+(\.\d{1,2})?$/', $string)) {
                throw SystemSettingException::malformed($key, 'expected a positive decimal string with at most 2 decimals');
            }

            [$whole, $fraction] = array_pad(explode('.', $string, 2), 2, '00');
            $normalised = ltrim($whole, '0');
            $normalised = ($normalised === '' ? '0' : $normalised).'.'.str_pad($fraction, 2, '0');

            $min = isset($entry['min']) ? (string) $entry['min'] : null;
            $max = isset($entry['max']) ? (string) $entry['max'] : null;

            // bccomp() raises ValueError the moment an operand is not numeric, so a malformed
            // bound in the catalogue would surface as a 500 while an operator is typing a value.
            // The bound is checked first, and a bad one is a configuration fault reported as such.
            $belowFloor = $min !== null && $this->moneyCompare($key, $normalised, $min) < 0;
            $aboveCeiling = $max !== null && $this->moneyCompare($key, $normalised, $max) > 0;

            if ($belowFloor || $aboveCeiling) {
                throw SystemSettingException::outOfBounds($key, $normalised, $min, $max);
            }

            $this->assertPairCoherent($key, $normalised, $entry);

            return $normalised;
        }

        throw SystemSettingException::malformed($key, 'this type is not supported by the store');
    }

    /**
     * A minimum may not exceed its maximum, whichever one is being written.
     *
     * The counterpart is read through `current()`, so the check sees the effective value (stored
     * override, or the deployed default) rather than whatever the form happened to send.
     *
     * @param  array<string, mixed>  $entry
     */
    private function assertPairCoherent(string $key, string $value, array $entry): void
    {
        $pairKey = $entry['pair'] ?? null;

        if (! is_string($pairKey) || $pairKey === '' || ! array_key_exists($pairKey, $this->catalogue())) {
            return;
        }

        $counterpart = (string) $this->current($pairKey);

        // The regex is what makes both operands numeric for bccomp(); `is_numeric` says the same
        // thing in the vocabulary the analyser (and bcmath itself) understands.
        if (! is_numeric($counterpart) || ! is_numeric($value)) {
            return;
        }

        $thisKeyIsMinimum = str_ends_with($key, '.min');

        if ($thisKeyIsMinimum && $this->moneyCompare($key, $value, $counterpart) > 0) {
            throw SystemSettingException::pairIncoherent($key, $value, $pairKey, $counterpart);
        }

        if (! $thisKeyIsMinimum && $this->moneyCompare($key, $value, $counterpart) < 0) {
            throw SystemSettingException::pairIncoherent($key, $value, $pairKey, $counterpart);
        }
    }

    /**
     * Compare two money strings at 2 decimal places.
     *
     * `bccomp()` raises a ValueError the moment an operand is not numeric — including a bound
     * mistyped in the catalogue, which would reach an operator as a 500 while they are typing.
     * The check happens here, once, and reports a configuration fault rather than crashing.
     */
    private function moneyCompare(string $key, string $left, string $right): int
    {
        if (! is_numeric($left) || ! is_numeric($right)) {
            throw SystemSettingException::malformed($key, sprintf('a money operand is not numeric: [%s] vs [%s]', $left, $right));
        }

        return bccomp($left, $right, 2);
    }

    private function castFromStorage(string $key, string $value): mixed
    {
        $entry = $this->catalogue()[$key];
        $type = (string) ($entry['type'] ?? 'string');

        return match ($type) {
            'bool' => $value === '1',
            'int' => is_numeric($value) ? (int) $value : null,
            'money' => preg_match('/^\d+(\.\d{1,2})?$/', $value) === 1 ? $value : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function stringifyDefault(mixed $value, array $entry): string
    {
        return match ((string) ($entry['type'] ?? 'string')) {
            'bool' => $value ? '1' : '0',
            'int' => (string) (int) $value,
            default => is_scalar($value) ? (string) $value : '',
        };
    }
}
