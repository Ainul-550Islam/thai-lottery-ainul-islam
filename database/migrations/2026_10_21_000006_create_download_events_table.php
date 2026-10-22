<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('download_events')) {
            Schema::create('download_events', function (Blueprint $table): void {
                $table->id();
                $table->string('platform', 16)->index();
                $table->string('version', 64)->nullable();
                $table->string('sha256', 64)->nullable();
                $table->string('source', 32)->default('web');
                $table->string('ip_hash', 64)->index();
                $table->string('user_agent_family', 32)->default('other');
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['ip_hash', 'platform', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('download_events');
    }
};
