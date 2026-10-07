<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_webhooks')) {
            return;
        }

        Schema::table('payment_webhooks', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable()->after('verified_at')->index();
            $table->unsignedInteger('processing_attempts')->default(0)->after('sightings');
            $table->timestamp('last_error_at')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_webhooks')) {
            return;
        }

        Schema::table('payment_webhooks', function (Blueprint $table): void {
            $table->dropColumn(['claimed_at', 'processing_attempts', 'last_error_at']);
        });
    }
};
