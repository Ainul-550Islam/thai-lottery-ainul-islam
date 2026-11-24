<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The system settings store.
 *
 * ADDITIVE AND NON-DESTRUCTIVE: a new table with no foreign key into any existing row set and
 * no change to an existing column. Nothing is backfilled — an empty table means "every setting
 * is exactly what the deployed configuration says", which is the correct default for a store
 * that only ever holds an OPERATOR OVERRIDE.
 *
 * WHY A TABLE AT ALL
 *
 * The panel's settings surface used to be a read-only projection, and the reason was sound:
 * writing `.env` or a PHP config file from a web session is a privilege-escalation surface (a
 * compromised `settings.manage` grant would become code execution). A table removes that
 * objection without pretending the problem away: a row here can only ever override a key that
 * appears in the whitelist at `config/admin.php → settings_catalogue`, with the type and bounds
 * declared there, through a service that validates, audits old→new and refuses everything else.
 *
 * `value` is a string column on purpose. Money settings are decimal strings and booleans are
 * '1'/'0', so nothing is silently coerced through a float on the way in or out; `value_type`
 * records how the catalogue declared the key when the row was written, so a future type change
 * can be detected instead of reinterpreted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_settings')) {
            return;
        }

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();

            // The config key this row overrides, e.g. 'lottery.draw.interval_minutes'.
            $table->string('key', 96)->unique();

            $table->text('value');
            $table->string('value_type', 16);

            // Who last changed it. Nullable and nullOnDelete: the audit log keeps the durable
            // record of who did what, and losing a user must not lose the setting.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
