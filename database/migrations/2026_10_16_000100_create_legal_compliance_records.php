<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 24);
            $table->string('locale', 12);
            $table->string('version', 64);
            $table->string('title', 255);
            $table->string('meta_title', 255);
            $table->text('meta_description');
            $table->json('sections');
            $table->char('content_hash', 64);
            $table->date('effective_at');
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['document_type', 'locale', 'version'],
                'legal_documents_type_locale_version_unique',
            );
            $table->index(
                ['document_type', 'locale', 'status'],
                'legal_documents_publication_lookup_index',
            );
        });

        Schema::create('legal_document_publications', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 24);
            $table->string('locale', 12);
            $table->foreignId('legal_document_id')
                ->constrained('legal_documents')
                ->restrictOnDelete();
            $table->unique('legal_document_id', 'legal_document_publications_document_unique');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(
                ['document_type', 'locale'],
                'legal_document_publications_type_locale_unique',
            );
        });

        Schema::create('user_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('legal_document_id')->nullable()
                ->constrained('legal_documents')
                ->nullOnDelete();
            $table->string('document_type', 24);
            $table->string('document_version', 64);
            $table->char('document_hash', 64);
            $table->string('action', 20);
            $table->timestamp('consented_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('locale', 12);
            $table->string('source', 32);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['user_id', 'document_type', 'document_version', 'document_hash', 'action'],
                'user_consents_acceptance_identity_unique',
            );
            $table->index(
                ['user_id', 'document_type', 'consented_at'],
                'user_consents_history_index',
            );
        });

        Schema::create('age_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique('user_id', 'age_verifications_user_unique');
            $table->string('status', 16);
            $table->unsignedTinyInteger('minimum_age')->default(18);
            $table->string('method', 32);
            $table->timestamp('attested_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('source', 32);
            $table->timestamps();

            $table->index(['status', 'attested_at'], 'age_verifications_status_attested_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_verifications');
        Schema::dropIfExists('user_consents');
        Schema::dropIfExists('legal_document_publications');
        Schema::dropIfExists('legal_documents');
    }
};
