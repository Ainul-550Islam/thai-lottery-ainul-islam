<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Content-marketing articles (D5).
 *
 * A 2026 audit found `blog: 0 · cms: 0 · testimonial: 0` — no way to publish a
 * single piece of long-tail content on a product whose category has enormous
 * organic search volume. This table is the store that fixes that.
 *
 * WHY THE SLUG IS UNIQUE PER LOCALE, NOT GLOBALLY
 * The site serves Thai, English and Bengali. A Thai article about ตรวจหวย and
 * the English article it was translated from are different pages for different
 * audiences and both deserve the readable slug. Uniqueness is therefore
 * (locale, slug); the two rows are tied together by `translation_group` so the
 * public pages can emit hreflang alternates, which is how a multi-language site
 * avoids being treated as three sites duplicating each other.
 *
 * WHY PUBLISHED_AT IS A SEPARATE COLUMN AND NOT UPDATED_AT
 * A corrected typo must not change the date a reader sees, and must not change
 * the sitemap's lastmod every time someone fixes a comma. `published_at` is set
 * once, when the article first goes live, and is what every public surface
 * reads. The service refuses to overwrite an existing one.
 *
 * WHY BODY IS HTML AND NOT MARKDOWN
 * The admin editor is a rich-text field, and the application has no Markdown
 * renderer and no composer dependency to add one. Storing Markdown and failing
 * to render it, or adding a parser for the sake of it, are both worse than
 * storing the editor's own output and sanitising it on the way in — which
 * ArticleService::sanitiseHtml() does with an allowlist, not a blocklist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('articles')) {
            Schema::create('articles', function (Blueprint $table): void {
                $table->id();
    
                // Ties translations of the same article together for hreflang.
                $table->uuid('translation_group')->index();
    
                $table->string('locale', 8)->index();
    
                $table->string('slug', 200);
                $table->string('title', 255);
    
                // Short summary for index pages, meta descriptions and feeds.
                // Nullable: it can be derived from the body, and forcing an author
                // to write one blocks publishing for no benefit.
                $table->text('excerpt')->nullable();
    
                // Sanitised rich-text HTML. Never rendered raw.
                $table->longText('body');
    
                $table->string('category', 32)->index();
                $table->string('status', 16)->index();
    
                // Denormalised author name rather than a users foreign key: the
                // byline is editorial text ("ThaiLotto Editorial Team", an
                // attributed external writer) and forcing it to be a staff account
                // would make ordinary attribution impossible.
                $table->string('author_name', 120)->nullable();
    
                $table->unsignedSmallInteger('reading_minutes')->default(1);
    
                $table->boolean('featured')->default(false)->index();
    
                // Optional per-article SEO override; the view falls back to the
                // title and excerpt when these are empty.
                $table->string('meta_title', 255)->nullable();
                $table->string('meta_description', 320)->nullable();
    
                // Set once, on first publication. See the class docblock.
                $table->timestamp('published_at')->nullable()->index();
    
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    
                $table->timestamps();
    
                $table->unique(['locale', 'slug'], 'articles_locale_slug_unique');
    
                // The public index query: live articles, newest first, optionally
                // filtered by category.
                $table->index(['status', 'published_at'], 'articles_status_published_index');
                $table->index(['category', 'status'], 'articles_category_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
