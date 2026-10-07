<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            return;
        }

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->string('provider_reference', 191)->nullable()->after('provider');
            $table->string('webhook_event_id', 96)->nullable()->after('provider_reference');
            $table->foreignId('financial_transaction_id')->nullable()->after('wallet_id')
                ->constrained('financial_transactions')->restrictOnDelete();

            $table->unique(
                ['provider', 'provider_reference'],
                'payment_transactions_provider_reference_unique',
            );
            $table->unique(
                ['provider', 'webhook_event_id'],
                'payment_transactions_provider_event_unique',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_transactions')) {
            return;
        }

        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropUnique('payment_transactions_provider_reference_unique');
            $table->dropUnique('payment_transactions_provider_event_unique');
            $table->dropConstrainedForeignId('financial_transaction_id');
            $table->dropColumn(['provider_reference', 'webhook_event_id']);
        });
    }
};
