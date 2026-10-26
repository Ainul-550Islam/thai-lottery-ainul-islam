<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wechat ID and IC No. registration fields.
 *
 * Both columns are nullable and additive — existing accounts keep
 * working without either value. Unique constraints prevent two
 * members from registering the same Wechat ID or IC No.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('wechat_id')->nullable()->unique()->after('nationality');
            $table->string('ic_no')->nullable()->unique()->after('wechat_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['wechat_id']);
            $table->dropUnique(['ic_no']);
            $table->dropColumn(['ic_no', 'wechat_id']);
        });
    }
};
