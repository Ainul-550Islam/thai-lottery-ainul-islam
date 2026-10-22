<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('security_audit_logs')) {
            return;
        }

        Schema::table('security_audit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('security_audit_logs', 'actor_user_id')) {
                $table->string('actor_user_id', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('security_audit_logs', 'client_ip')) {
                $table->string('client_ip', 45)->nullable();
            }
            if (! Schema::hasColumn('security_audit_logs', 'severity')) {
                $table->string('severity', 32)->nullable()->index();
            }
            if (! Schema::hasColumn('security_audit_logs', 'payload')) {
                $table->json('payload')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('security_audit_logs')) {
            return;
        }

        Schema::table('security_audit_logs', function (Blueprint $table): void {
            $cols = array_values(array_filter(
                ['actor_user_id', 'client_ip', 'severity', 'payload'],
                static fn (string $c): bool => Schema::hasColumn('security_audit_logs', $c),
            ));
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
