<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance attestations — the ONLY thing that can put a credential on a
 * player's screen.
 *
 * THE RULE THIS TABLE EXISTS TO ENFORCE
 * No licence, audit or age-policy badge may be returned by an API or rendered
 * in a view unless a row here is valid: not revoked, valid_from <= now, and
 * (valid_to is null OR valid_to >= now). The mockup's "Licensed by GLO" footer
 * text is a forbidden claim (config/player_experience.php) and
 * MarketingClaimGuard + ForbiddenClaimsTest make it unrepresentable in code
 * except through ComplianceBadgeService.
 *
 * `document_hash` is the SHA-256 of the evidence file the operator holds. The
 * file itself does NOT live in this repository: storing a licence PDF in git
 * would be both an evidence-integrity and a confidentiality defect. The hash is
 * what ties the claim to one specific document version.
 *
 * REVOCATION IS A FACT, NOT A DELETE
 * Revocation stamps revoked_at/revoked_by/revoked_reason. A badge disappears
 * because the row stopped being valid, not because somebody removed history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compliance_attestations')) {
            return;
        }

        Schema::create('compliance_attestations', function (Blueprint $table): void {
            $table->id();

            // licence | security_audit | age_policy | responsible_gaming
            $table->string('code', 40);

            $table->string('reference', 190);
            $table->string('issuer', 190)->nullable();

            // SHA-256 of the evidence document, hex.
            $table->string('document_hash', 64)->nullable();

            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->foreignId('verified_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('revoked_reason', 500)->nullable();

            // Player-facing label text. It is scanned by MarketingClaimGuard on
            // save; a label claiming more than the attestation proves is
            // refused there, not here.
            $table->string('public_label', 120)->nullable();
            $table->string('public_url', 500)->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['code', 'valid_from', 'valid_to'], 'compliance_attestations_code_window_idx');
            $table->index('revoked_at', 'compliance_attestations_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_attestations');
    }
};
