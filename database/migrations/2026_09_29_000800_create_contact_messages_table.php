<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor contact messages (PROMPT 10).
 *
 * WHAT IS DELIBERATELY ABSENT
 * ---------------------------------------------------------------------------
 * There is no ip_address column, no user_agent column, no headers column and
 * no raw-request column. A support form does not need a visitor's address to
 * answer their question, and a column that exists will eventually be read,
 * exported and leaked. Abuse control needs to recognise a repeat sender, not
 * identify a person, so what is stored is sender_fingerprint: a keyed hash
 * that cannot be reversed into an address and is useless outside this table.
 *
 * public_reference IS THE ONLY IDENTIFIER A VISITOR EVER SEES. The primary key
 * is an auto-increment; showing it would tell every visitor how many messages
 * the platform has received and let them count new ones.
 *
 * DELIVERY STATE LIVES HERE AND ON THE ATTEMPTS TABLE. This column is the
 * CURRENT answer; the attempts table is the history of how it got there. They
 * are separate because "the message is stored" and "an email arrived" are
 * different facts, and collapsing them is exactly the dishonesty this lane
 * exists to avoid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Shown to the visitor on the confirmation screen so they can quote
            // it. Derived from randomness, never from the row id.
            $table->string('public_reference', 32)->unique();

            $table->string('name', 190);
            $table->string('email', 190);
            $table->string('subject', 190);

            // text, not string: a support message is prose and may legitimately
            // run to several paragraphs. The application bound is what limits
            // it, so the bound can be changed without a migration.
            $table->text('message');

            // Closed vocabulary from config('contact.statuses').
            $table->string('status', 24)->default('RECEIVED')->index();

            // Closed vocabulary from config('contact.delivery_states').
            $table->string('delivery_state', 24)->default('NOT_ATTEMPTED')->index();

            // Keyed hash of the sender signal used for rate limiting and
            // duplicate detection. NOT an address, NOT reversible, and never
            // rendered.
            $table->string('sender_fingerprint', 64)->nullable()->index();

            // Keyed hash of (sender + subject + message + time bucket).
            //
            // UNIQUE, AND THAT IS THE WHOLE POINT. The service also runs a
            // SELECT before inserting, but a SELECT followed by an INSERT is a
            // race: two requests arriving together both find nothing and both
            // write. Only the database can settle that, so the guarantee lives
            // here and the SELECT is just the fast path that avoids an
            // exception in the ordinary case.
            //
            // The hash includes a coarse TIME BUCKET so the constraint does not
            // become permanent. A visitor who genuinely writes the same words
            // again next week lands in a different bucket and gets a new
            // record; two requests milliseconds apart are always in the same
            // one, which is the collision that actually needs preventing.
            //
            // Nullable because a message can legitimately have no fingerprint -
            // a honeypot row, or a deployment that has switched deduplication
            // off - and SQLite, MySQL and Postgres all allow repeated NULLs in
            // a unique index.
            $table->string('content_fingerprint', 64)->nullable()->unique();

            $table->string('locale', 12)->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Retention sweeps read status plus age; the public confirmation
            // reads the reference. Both are indexed for the query they serve.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
