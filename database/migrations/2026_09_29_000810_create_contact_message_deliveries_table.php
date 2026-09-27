<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt to hand a contact message to an outbound provider (PROMPT 10).
 *
 * WHY ATTEMPTS ARE A TABLE AND NOT A COLUMN. A message may be attempted more
 * than once, and "it failed twice then succeeded" is a different operational
 * story from "it succeeded". Keeping the history means a support lead can see
 * an outage rather than a single confusing state.
 *
 * NO CREDENTIALS, EVER. There is no column for a host, a username, a password,
 * an API token or a raw provider exception. What is stored is an error CLASS
 * and a short, already-sanitised code - enough to tell an SMTP timeout from a
 * rejected recipient, and not enough to leak a secret into a database that is
 * backed up, exported and read by people who should not see one.
 *
 * The foreign key points from attempts to messages and never the other way, so
 * there is no cycle: the message's delivery_state is a plain column updated in
 * the same transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_message_deliveries', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('contact_message_id')
                ->constrained('contact_messages')
                ->cascadeOnDelete();

            // 'mail' today. A label, not a connection string.
            $table->string('provider', 40);

            // Closed vocabulary from config('contact.delivery_states').
            $table->string('state', 24)->index();

            $table->timestamp('attempted_at');

            // Short, safe classification. Examples: 'TRANSPORT_FAILURE',
            // 'PROVIDER_NOT_CONFIGURED'. Never a message from the provider.
            $table->string('error_code', 64)->nullable();

            // The exception CLASS name only, so an operator can tell a
            // configuration error from a network one without the exception's
            // text - which routinely contains hosts, addresses and paths.
            $table->string('error_class', 190)->nullable();

            $table->timestamps();

            $table->index(['contact_message_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_message_deliveries');
    }
};
