<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GLO L6 transactional ticket reservation, immutable pricing snapshot, and
 * active slot-allocation tables.
 *
 * BACKWARDS-AWARE & NON-DESTRUCTIVE:
 * - Guards table creation with Schema::hasTable so re-running against pre-existing
 *   schemas never fails.
 * - down() refuses to drop populated tables so sold/reserved ticket evidence is
 *   never silently destroyed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('glo_l6_ticket_reservations')) {
            Schema::create('glo_l6_ticket_reservations', function (Blueprint $table): void {
                $table->id();
                $table->string('reservation_reference', 64)->unique();
                $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
                $table->foreignId('glo_l6_sale_id')->nullable()->constrained('glo_l6_sales')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
                $table->foreignId('wallet_reservation_id')->nullable()->constrained('wallet_reservations')->nullOnDelete();

                // Six-digit GLO L6 number preserved strictly as string ('000000'..'999999').
                $table->string('ticket_number', 6);
                $table->unsignedInteger('set_series')->default(1);
                $table->string('status', 24)->default('reserved');

                $table->decimal('unit_price', 10, 2);
                $table->string('currency', 3)->default('THB');
                $table->json('pricing_snapshot');

                $table->string('idempotency_key', 128);
                $table->string('purchase_idempotency_key', 128)->nullable();
                $table->string('request_fingerprint', 64);

                $table->timestamp('reserved_until');
                $table->timestamp('purchased_at')->nullable();
                $table->timestamp('released_at')->nullable();

                $table->foreignId('glo_ticket_id')->nullable()->constrained('glo_tickets')->restrictOnDelete();
                $table->foreignId('financial_transaction_id')->nullable()->constrained('financial_transactions')->restrictOnDelete();
                $table->foreignId('refund_financial_transaction_id')->nullable()->constrained('financial_transactions', indexName: 'glo_l6_resv_refund_fin_tx_fk')->restrictOnDelete();

                $table->string('failure_reason', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'idempotency_key'], 'glo_l6_resv_user_idempotency_unique');
                $table->index(['draw_id', 'ticket_number', 'set_series', 'status'], 'glo_l6_resv_slot_status_idx');
                $table->index(['status', 'reserved_until'], 'glo_l6_resv_expiry_idx');
                $table->index(['user_id', 'created_at'], 'glo_l6_resv_user_history_idx');
            });
        }

        if (! Schema::hasTable('glo_l6_active_allocations')) {
            Schema::create('glo_l6_active_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
                $table->foreignId('glo_l6_sale_id')->nullable()->constrained('glo_l6_sales')->restrictOnDelete();
                $table->string('ticket_number', 6);
                $table->unsignedInteger('set_series')->default(1);
                $table->string('state', 16)->default('reserved');
                $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('reservation_id')->nullable()->constrained('glo_l6_ticket_reservations')->nullOnDelete();
                $table->foreignId('glo_ticket_id')->nullable()->constrained('glo_tickets')->restrictOnDelete();
                $table->string('pricing_snapshot_hash', 64);
                $table->timestamp('reserved_until')->nullable();
                $table->timestamp('allocated_at');
                $table->timestamps();

                // Hard DB-level collision guard across SQLite, MySQL/MariaDB, and PostgreSQL:
                // at most ONE active reservation or sold ownership may occupy (draw_id, ticket_number, set_series).
                $table->unique(['draw_id', 'ticket_number', 'set_series'], 'glo_l6_active_alloc_slot_unique');
                $table->index(['draw_id', 'set_series', 'state'], 'glo_l6_active_alloc_series_idx');
                $table->index(['state', 'reserved_until'], 'glo_l6_active_alloc_expiry_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('glo_l6_active_allocations') && DB::table('glo_l6_active_allocations')->exists()) {
            throw new RuntimeException('Refused: preserve populated glo_l6_active_allocations records.');
        }

        if (Schema::hasTable('glo_l6_ticket_reservations') && DB::table('glo_l6_ticket_reservations')->exists()) {
            throw new RuntimeException('Refused: preserve populated glo_l6_ticket_reservations records.');
        }

        Schema::dropIfExists('glo_l6_active_allocations');
        Schema::dropIfExists('glo_l6_ticket_reservations');
    }
};
