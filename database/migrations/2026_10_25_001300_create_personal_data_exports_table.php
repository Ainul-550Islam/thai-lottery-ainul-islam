<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A built personal-data archive: where it is, how big it is, whether it is
 * still there.
 *
 * THE PATH IS NOT A URL. The archive lives on a private disk under a random
 * name and is streamed by an authorised controller; there is no public link to
 * leak, and the mail that announces it carries a signed, expiring URL instead.
 *
 * THE HASH IS THE DELIVERY CONTRACT. sha256 is the digest of the exact bytes
 * written, so the archive can be re-verified before it is handed over and a
 * truncated or replaced file cannot be served as if it were the original.
 *
 * EXPIRY IS ENFORCED, NOT SUGGESTED. expires_at, download_count and
 * max_downloads are all checked at download time, and privacy:purge-expired
 * deletes the file and marks the row. An export is a copy of everything a
 * person has: it must not sit on a disk forever because a queue was busy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_data_exports')) {
            return;
        }

        Schema::create('personal_data_exports', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('request_id')
                ->constrained('data_subject_requests')
                ->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('disk', 32);
            $table->string('path', 255);

            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);

            // 'building' | 'ready' | 'failed' | 'expired'
            $table->string('status', 16)->default('building')->index();

            // Set only once staff have released the archive to the requester.
            $table->timestamp('released_at')->nullable();

            $table->timestamp('expires_at')->index();
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('max_downloads')->default(3);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->timestamps();

            $table->index(['request_id', 'status'], 'personal_data_exports_request_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_data_exports');
    }
};
