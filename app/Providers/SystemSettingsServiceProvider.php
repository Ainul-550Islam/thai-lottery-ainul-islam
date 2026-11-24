<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Admin\SystemSettingService;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Applies stored operator overrides to `config()` once per boot.
 *
 * WHY A PROVIDER AND NOT A CALL IN EACH SERVICE
 *
 * The whole point of the store is that an operator can change a value WITHOUT a deployment. If
 * each consumer had to ask `SystemSettingService::current('…')` instead of `config('…')`, every
 * existing lane that reads configuration would have to be rewritten — and the one that was
 * missed would silently ignore the override. Merging the overrides into the config repository at
 * boot means every existing reader (services, commands, jobs, blade) sees the operator's value
 * without knowing the store exists.
 *
 * FAILURE IS QUIET AND SAFE: if the table is not there yet (fresh clone, `migrate` not run) or a
 * row cannot be read, the application runs on the deployed configuration rather than refusing to
 * boot. A settings store must never be able to take the platform down.
 */
final class SystemSettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Console commands that CREATE the schema (migrate:fresh, db:wipe) must not query a table
        // that does not exist yet; the guard inside the service handles the rest.
        try {
            $this->app->make(SystemSettingService::class)->applyToConfig();
        } catch (QueryException|SQLiteDatabaseDoesNotExistException) {
            // There is no schema yet — a fresh clone, or `artisan migrate` itself, which must not
            // be made to query a table it is in the middle of creating. Silence is the correct
            // answer here: the deployed configuration applies, and nothing is logged as a fault
            // that is really just "not installed yet".
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
