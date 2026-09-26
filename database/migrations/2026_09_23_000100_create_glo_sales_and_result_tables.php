<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GLO sales, N3 seat, result-import provenance and sales-reconciliation tables.
 *
 * glo_l6_sales             — L6 unit sales seat (draw_id, product unique)
 * glo_n3_sales             — N3 seat sales (draw_id, product unique; conflict gate)
 * glo_result_imports       — provenance of every official/fixture result import
 * glo_sales_reconciliations — reconciliation runs with GLON3_SALES_CONFLICT gate
 *
 * Money columns are decimal strings (BCMath domain). Ticket numbers never
 * stored as integers.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('glo_l6_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->string('product', 8)->default('l6');
            $table->string('seat_key', 96)->unique();

            // Units sold out of full_sale_units (default 1,000,000).
            $table->unsignedBigInteger('units_sold')->default(0);
            $table->unsignedBigInteger('units_full')->default(1000000);
            $table->decimal('gross_sales', 20, 2)->default('0');
            $table->decimal('ticket_price', 10, 2)->default('80');

            $table->string('source_reference', 128)->nullable();
            $table->string('provenance', 64)->default('operator_seat');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['draw_id', 'product'], 'glo_l6_sales_draw_product_unique');
            $table->index('product');
        });

        Schema::create('glo_n3_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->string('product', 8)->default('n3');
            $table->string('seat_key', 96)->unique();

            // N3 seat quantities and pool inputs (exact strings).
            $table->unsignedBigInteger('seats_sold')->default(0);
            $table->unsignedBigInteger('seats_full')->default(0);
            $table->decimal('gross_sales', 20, 2)->default('0');
            $table->decimal('pool_amount', 20, 2)->default('0');
            $table->decimal('ticket_price', 10, 2)->default('20');

            // seat_state: open | closed | conflicted (GLON3_SALES_CONFLICT).
            $table->string('seat_state', 32)->default('open')->index();
            $table->string('conflict_gate', 64)->nullable();
            $table->string('source_reference', 128)->nullable();
            $table->string('provenance', 64)->default('operator_seat');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['draw_id', 'product'], 'glo_n3_sales_draw_product_unique');
            $table->index(['product', 'seat_state']);
        });

        Schema::create('glo_result_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('import_reference', 64)->unique();
            $table->foreignId('draw_id')->nullable()->constrained('draws')->nullOnDelete();

            // provider: official | fixture
            $table->string('provider', 32)->index();
            $table->string('mode', 32)->default('fixture');
            $table->string('endpoint', 255)->nullable();
            $table->string('upstream_draw_id', 64)->nullable();

            // status: imported | not_configured | failed | skipped
            $table->string('status', 32)->index();
            $table->string('result_fingerprint', 64)->nullable();

            $table->json('payload_summary')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->index(['draw_id', 'status']);
            $table->index(['provider', 'imported_at']);
        });

        Schema::create('glo_sales_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->string('reconciliation_reference', 64)->unique();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->string('product', 8)->index();

            $table->unsignedBigInteger('expected_seats')->default(0);
            $table->unsignedBigInteger('recorded_seats')->default(0);
            $table->decimal('expected_gross', 20, 2)->default('0');
            $table->decimal('recorded_gross', 20, 2)->default('0');
            $table->decimal('variance_gross', 20, 2)->default('0');

            // status: matched | variance | conflicted (GLON3_SALES_CONFLICT)
            $table->string('status', 32)->index();
            $table->string('conflict_gate', 64)->nullable();
            $table->json('details')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at');
            $table->timestamps();

            $table->unique(['draw_id', 'product', 'reconciliation_reference']);
            $table->index(['draw_id', 'product', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glo_sales_reconciliations');
        Schema::dropIfExists('glo_result_imports');
        Schema::dropIfExists('glo_n3_sales');
        Schema::dropIfExists('glo_l6_sales');
    }
};
