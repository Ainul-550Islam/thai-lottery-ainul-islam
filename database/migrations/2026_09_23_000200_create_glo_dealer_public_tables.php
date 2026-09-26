<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GLO-15..18 domain tables.
 *
 * glo_dealers                 — user-bound e-Service dealer profile (additive
 *                               to agents + retail_vendors; does not replace)
 * glo_dealer_change_requests  — name/address/phone/sales_location workflow
 * glo_sales_points            — current public sales-point projection
 * glo_sales_point_history     — append-only daily location history
 * glo_saved_tickets           — pre-draw saved tickets (unique user+ticket)
 * glo_notification_deliveries — GLO-specific delivery tracking keyed by
 *                               notification fingerprint (idempotent notify)
 *
 * Coordinates are synthetic/fixture-labeled unless an authorized feed exists.
 * No real GLO seller identities or private credentials are stored.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('glo_dealers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // Optional link to the existing quota retail channel (never replaces it).
            $table->foreignId('retail_vendor_id')->nullable()->constrained('retail_vendors')->nullOnDelete();
            // Optional link to the commission agent lane (never replaces it).
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();

            // SYNTHETIC project dealer reference — never a real GLO ID number.
            $table->string('dealer_ref', 64)->unique();
            $table->string('dealer_type', 32)->default('quota'); // quota | non_quota
            $table->string('status', 32)->default('pending')->index();
            $table->string('verification_state', 32)->default('unverified')->index();

            // Canonical profile fields under change-request control.
            $table->string('display_name', 255)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('province', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('subdistrict', 120)->nullable();
            $table->string('sales_location', 500)->nullable();

            // JSON capabilities list (server-assigned, never client-trusted).
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['dealer_type', 'status']);
        });

        Schema::create('glo_dealer_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_reference', 64)->unique();
            $table->foreignId('dealer_id')->constrained('glo_dealers')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();

            // name | address | phone | sales_location
            $table->string('request_type', 32)->index();
            // submitted | under_review | approved | rejected | cancelled
            $table->string('status', 32)->default('submitted')->index();

            $table->text('old_value')->nullable();
            $table->text('requested_value');
            $table->string('reason', 500)->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('decided_at')->nullable();

            // Audit fingerprint over (dealer, type, requested_value, submitted_at).
            $table->string('audit_fingerprint', 64)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['dealer_id', 'request_type', 'status']);
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('glo_sales_points', function (Blueprint $table): void {
            $table->id();
            $table->string('sales_point_code', 64)->unique();
            $table->foreignId('dealer_id')->nullable()->constrained('glo_dealers')->nullOnDelete();

            $table->string('display_name', 255);
            $table->string('address', 500)->nullable();
            $table->string('province', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('subdistrict', 120)->nullable();

            // Nullable: public search without coordinates still works.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Publishable business contact only when explicitly public.
            $table->string('public_contact', 64)->nullable();
            $table->string('status', 32)->default('active')->index();
            // unverified | verified | rejected
            $table->string('verification_state', 32)->default('unverified')->index();

            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();

            // fixture | operator — never claim OFFICIAL without a feed.
            $table->string('source', 32)->default('fixture')->index();
            $table->string('source_state', 40)->default('FIXTURE_ONLY')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['province', 'status']);
            $table->index(['district', 'status']);
            $table->index(['dealer_id', 'status']);
        });

        Schema::create('glo_sales_point_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_point_id')->constrained('glo_sales_points')->restrictOnDelete();
            $table->foreignId('dealer_id')->nullable()->constrained('glo_dealers')->nullOnDelete();

            $table->string('display_name', 255)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('province', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('subdistrict', 120)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->date('effective_date')->index();
            $table->string('source', 32)->default('operator');
            $table->string('source_state', 40)->default('INTERNAL_RECONCILED');
            $table->json('metadata')->nullable();
            $table->timestamps();

            // One history row per dealer per calendar day (duplicate update refused).
            $table->unique(['dealer_id', 'effective_date'], 'glo_sp_history_dealer_day_unique');
            $table->index(['sales_point_id', 'effective_date']);
        });

        Schema::create('glo_saved_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();

            $table->string('product', 8)->default('l6');
            // Ticket reference digit string / uuid — never int-cast.
            $table->string('ticket_reference', 64);
            $table->string('status', 16)->default('active')->index();

            $table->timestamp('saved_at')->index();
            $table->timestamp('removed_at')->nullable();

            // Result notification state (idempotent via notifications.message_fingerprint).
            $table->string('notification_state', 32)->default('pending')->index();
            $table->timestamp('notification_sent_at')->nullable();
            $table->string('result_version', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Exactly one save row per user+ticket (inactive row still occupies
            // the key so re-save is an explicit reactivate under lock).
            $table->unique(['user_id', 'ticket_id'], 'glo_saved_tickets_user_ticket_unique');
            $table->index(['draw_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('glo_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            // Unique idempotency key: user_id|ticket_id|result_version|type (hashed).
            $table->string('delivery_key', 64)->unique();
            $table->foreignId('notification_id')->nullable()->constrained('notifications')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignId('draw_id')->nullable()->constrained('draws')->nullOnDelete();
            $table->string('result_version', 64)->nullable();
            $table->string('notification_type', 64);
            // queued | sent | failed | not_configured
            $table->string('delivery_state', 32)->default('queued')->index();
            $table->string('channel', 16)->default('in_app');
            $table->string('provider', 32)->default('not_configured');
            $table->string('provider_reference', 128)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->json('payload_summary')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['draw_id', 'delivery_state']);
            $table->index(['user_id', 'notification_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glo_notification_deliveries');
        Schema::dropIfExists('glo_saved_tickets');
        Schema::dropIfExists('glo_sales_point_history');
        Schema::dropIfExists('glo_sales_points');
        Schema::dropIfExists('glo_dealer_change_requests');
        Schema::dropIfExists('glo_dealers');
    }
};
