<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every official-result delivery the ingestion lane received, and what became of it.
 *
 * THE UNIQUE KEY IS THE IDEMPOTENCY MECHANISM
 * UNIQUE(game_code, draw_no, source) makes "this exact result from this source
 * was already delivered" a database fact. A provider that re-sends draw 123
 * cannot create a second row, so the service detects the duplicate before anyone
 * asks the confirmation boundary to publish anything, and no lock is held while
 * the feed is being queried.
 *
 * source IS PART OF THE KEY ON PURPOSE
 * Two feeds for one game (an official feed and a reconciliation feed) are a
 * legitimate configuration. They must each be able to record the same draw, and
 * a disagreement between them must be visible as two rows rather than silently
 * overwriting one with the other.
 *
 * payload_hash SURVIVES THE PAYLOAD
 * The retention purge nulls `raw` and moves the row to `pruned`; the hash stays,
 * so the audit can still prove which bytes were consumed after the bytes are gone.
 *
 * THIS TABLE CANNOT TOUCH A DRAW
 * No draw_id column, no foreign key to draw_results, no result columns. A row
 * here is a claim about a payload. Turning a claim into a published draw result
 * is the existing confirmation boundary's job (Rule §0.5), and a test fails if
 * any new code writes draw results directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_result_imports', function (Blueprint $table): void {
            $table->id();

            $table->string('game_code', 64)->index();

            // The provider's own draw identifier, kept as a string: providers use
            // zero-padded and prefixed identifiers, and casting would collide
            // "0042" with "42".
            $table->string('draw_no', 64);

            $table->string('source', 64);

            // sha256 of the canonicalised payload (GameResultImport::hashPayload()).
            $table->string('payload_hash', 64);

            // staged | published | rejected | superseded | pruned.
            $table->string('status', 16)->default('staged')->index();

            // The payload as received. Nullable because pruning removes it.
            $table->json('raw')->nullable();

            // Why a row was refused or superseded, in operator language.
            $table->string('message', 255)->nullable();

            $table->timestamp('imported_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // The idempotency key. See the class docblock.
            $table->unique(['game_code', 'draw_no', 'source']);

            // The purge command walks old, non-published rows by age.
            $table->index(['status', 'imported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_result_imports');
    }
};
