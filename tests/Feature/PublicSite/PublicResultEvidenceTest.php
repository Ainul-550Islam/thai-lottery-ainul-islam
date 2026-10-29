<?php

declare(strict_types=1);

namespace Tests\Feature\PublicSite;

use App\Enums\GloSourceState;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Models\User;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;
use App\Services\Lottery\GloPublicResultService;
use App\Services\Lottery\PrizeVerificationService;
use App\Services\PublicPages\PublishedResultSourceResolver;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\PublicSite\Concerns\PublishesProviderResults;
use Tests\TestCase;

/** Synthetic persisted evidence, never live-feed authenticity or funds approval. */
final class PublicResultEvidenceTest extends TestCase
{
    use PublishesProviderResults;
    use RefreshDatabase;

    private function heldDraw(): Draw
    {
        return Draw::factory()->closed()->create(['scheduled_at' => now()->subDay()]);
    }

    private function moneyCounts(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), [
            'wallets', 'ledger_entries', 'financial_transactions', 'deposits', 'withdrawals', 'payouts',
        ]);
    }

    public static function locales(): array
    {
        return [['en'], ['th']];
    }

    #[DataProvider('locales')]
    public function test_rendered_result_copy_is_localized_and_disclaims_payment_authority(string $locale): void
    {
        $draw = $this->heldDraw();
        $this->publishProviderResult($draw);
        $before = $this->moneyCounts();
        $response = $this->get('/results?lang='.$locale)->assertOk();
        $dom = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new DOMXPath($dom);
            self::assertSame(trans('results.title', [], $locale), trim($xpath->query('//*[@id="results-title"]')->item(0)->textContent));
            self::assertSame(trans('results.independent_notice', [], $locale), trim($xpath->query('//*[@data-results-disclaimer]')->item(0)->textContent));
            self::assertSame(trans('results.fixture_only', [], $locale), trim($xpath->query('//*[@data-source-state="FIXTURE_ONLY"]')->item(0)->textContent));
            self::assertStringNotContainsString('results.independent_notice', $response->getContent());
            if ($locale === 'th') {
                self::assertStringNotContainsString('Public Results Hub', $response->getContent());
                self::assertStringNotContainsString('First prize', $response->getContent());
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        self::assertSame($before, $this->moneyCounts());
    }

    public function test_translation_key_parity_and_actual_thai_copy(): void
    {
        $en = require base_path('lang/en/results.php');
        $th = require base_path('lang/th/results.php');
        self::assertSame(array_keys($en), array_keys($th));
        foreach ($en as $key => $value) {
            self::assertNotSame($value, $th[$key], $key);
            self::assertMatchesRegularExpression('/[\x{0E00}-\x{0E7F}]/u', $th[$key], $key);
        }
    }

    public function test_matching_official_import_is_verified_in_page_service_and_api_and_sheet(): void
    {
        $draw = $this->heldDraw();
        $result = $this->publishProviderResult($draw, 'official', '000007', '58', ['001122'], ['600001'], ['last_two' => ['58']]);
        $before = $this->moneyCounts();
        self::assertSame(GloSourceState::OfficialSourceVerified, app(PublishedResultSourceResolver::class)->resolve($result, $draw->fresh()));
        $this->get('/results')->assertOk()->assertSee('data-source-state="OFFICIAL_SOURCE_VERIFIED"', false);
        $this->getJson('/api/v1/glo/results/sheet?draw='.$draw->draw_number)->assertOk()->assertJsonPath('data.source_state', 'OFFICIAL_SOURCE_VERIFIED');
        self::assertSame($before, $this->moneyCounts());
    }

    public function test_prize_orchestrator_compares_only_while_full_persisted_provenance_is_valid(): void
    {
        $draw = $this->heldDraw();
        // Synthetic import, but real ingestion, distinct maker/checker and
        // publication evidence; it is not a live provider-authenticity claim.
        $this->publishProviderResult($draw, 'official', '000007', '58');
        $before = $this->moneyCounts();
        $service = app(PrizeVerificationService::class);

        $winning = $service->verify('number', '000007', drawRef: $draw->draw_number);
        self::assertSame(PrizeVerificationService::WINNING, $winning['status']);
        self::assertNotEmpty($winning['prize']['matches']);
        self::assertNotNull($winning['prize']['amount']);
        self::assertFalse($winning['fixture']);
        // A source-verified result is not an official ticket-verification service.
        self::assertFalse($winning['official_source']);
        $losing = $service->verify('number', '111114', drawRef: $draw->draw_number);
        self::assertSame(PrizeVerificationService::FOUND_NOT_WINNING, $losing['status']);

        GloResultImport::query()->where('draw_id', $draw->getKey())->update(['status' => 'failed']);
        foreach (['000007', '111114'] as $number) {
            $answer = $service->verify('number', $number, drawRef: $draw->draw_number);
            self::assertSame(PrizeVerificationService::UNAVAILABLE, $answer['status']);
            self::assertSame('RESULT_UNAVAILABLE', $answer['reason']);
            self::assertSame([], $answer['prize']['matches']);
            self::assertNull($answer['prize']['amount']);
        }
        self::assertSame($before, $this->moneyCounts());
    }

    public static function corruptEvidence(): array
    {
        return array_map(fn (string $name): array => [$name], [
            'missing confirmation', 'same maker checker', 'missing checker', 'failed import',
            'wrong provenance reference', 'changed primary prize', 'changed second prize',
            'invented extra tier', 'changed provider', 'missing publication time', 'future draw',
        ]);
    }

    #[DataProvider('corruptEvidence')]
    public function test_broken_persisted_evidence_never_gets_a_verified_badge_or_cache_publication(string $fault): void
    {
        $draw = $this->heldDraw();
        $result = $this->publishProviderResult($draw, 'official', '000007', '58', ['001122']);
        $meta = $result->metadata;
        $drawMeta = $draw->fresh()->metadata;
        switch ($fault) {
            case 'missing confirmation':
                unset($drawMeta['result_ingestion']);
                $draw->metadata = $drawMeta;
                $draw->save();
                break;
            case 'same maker checker':
                $drawMeta['result_ingestion']['confirmed_by'] = $drawMeta['result_ingestion']['ingested_by'];
                $draw->metadata = $drawMeta;
                $draw->save();
                break;
            case 'missing checker':
                User::query()->whereKey($meta['four_eyes']['confirmed_by'])->delete();
                break;
            case 'failed import':
                GloResultImport::query()->where('draw_id', $draw->getKey())->update(['status' => 'failed']);
                break;
            case 'wrong provenance reference':
                $meta['provenance_reference'] = 'unrelated-import';
                break;
            case 'changed primary prize':
                DrawResult::query()->whereKey($result->getKey())->update(['first_prize' => '111111']);
                break;
            case 'changed second prize':
                DrawResult::query()->whereKey($result->getKey())->update(['second_prize' => ['999999']]);
                break;
            case 'invented extra tier':
                $meta['glo']['last_two'] = ['99'];
                break;
            case 'changed provider':
                $meta['glo']['import_provider'] = 'official_api';
                break;
            case 'missing publication time':
                DrawResult::query()->whereKey($result->getKey())->update(['published_at' => null]);
                break;
            case 'future draw':
                Draw::query()->whereKey($draw->getKey())->update(['scheduled_at' => now()->addDay()]);
                break;
        }
        if ($meta !== $result->metadata) {
            DrawResult::query()->whereKey($result->getKey())->update(['metadata' => $meta]);
        }
        $before = $this->moneyCounts();
        $audits = AuditLog::query()->count();
        self::assertSame(GloSourceState::Unavailable, app(PublishedResultSourceResolver::class)->resolve($result->fresh(), $draw->fresh()));
        self::assertSame('UNAVAILABLE', app(GloPublicResultService::class)->sourceStateFor($result->fresh(), $draw->fresh()));
        self::assertSame(1, Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey()]));
        self::assertNull(Cache::get('glo:public-result:current:'.$draw->getKey()));
        self::assertSame($audits, AuditLog::query()->count());
        self::assertSame($before, $this->moneyCounts());
    }

    public function test_provider_name_and_sha256_alone_do_not_verify_a_result(): void
    {
        $draw = Draw::factory()->create(['status' => 'result_published', 'scheduled_at' => now()->subDay()]);
        $result = DrawResult::factory()->create([
            'draw_id' => $draw->getKey(), 'metadata' => [
                'provenance' => 'source-verified', 'provenance_reference' => 'invented-reference',
                'glo' => ['import_provider' => 'official', 'import_fingerprint' => str_repeat('a', 64)],
            ],
        ]);
        self::assertSame(GloSourceState::Unavailable, app(PublishedResultSourceResolver::class)->resolve($result, $draw));
        $this->get('/results')->assertOk()->assertDontSee('data-source-state="OFFICIAL_SOURCE_VERIFIED"', false);
        self::assertSame(1, Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey()]));
    }

    public function test_confirmed_operator_result_is_internal_not_official(): void
    {
        $draw = app(DrawLifecycleService::class)->markResultPending($this->heldDraw());
        $card = ['first_prize' => '000007', 'bottom_two' => '58'];
        app(DrawResultIngestionService::class)->ingest((int) $draw->getKey(), $card, 'internal', (int) User::factory()->create()->getKey());
        app(DrawResultConfirmationService::class)->confirm((int) $draw->getKey(), (int) User::factory()->create()->getKey(), $card);
        $result = DrawResult::query()->where('draw_id', $draw->getKey())->firstOrFail();
        self::assertSame(GloSourceState::InternalReconciled, app(PublishedResultSourceResolver::class)->resolve($result, $draw->fresh()));
        self::assertSame(1, Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey()]));
    }

    public function test_production_hides_fixture_rows_and_refuses_force_publication(): void
    {
        $draw = $this->heldDraw();
        $this->publishProviderResult($draw, firstPrize: '987654');
        config(['app.env' => 'production', 'glo.official_source.mode' => 'official']);
        $this->get('/results')->assertOk()->assertDontSee('987654')->assertDontSee('data-source-state="FIXTURE_ONLY"', false);
        self::assertSame(1, Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey(), '--force' => true]));
        self::assertNull(Cache::get('glo:public-result:current:'.$draw->getKey()));
    }

    public function test_cache_hits_cannot_preserve_verification_after_import_revocation(): void
    {
        $draw = $this->heldDraw();
        $this->publishProviderResult($draw, 'official');
        $service = app(GloPublicResultService::class);
        self::assertSame('OFFICIAL_SOURCE_VERIFIED', $service->cachedResult((string) $draw->getKey())['source_state']);
        GloResultImport::query()->where('draw_id', $draw->getKey())->update(['status' => 'failed']);
        self::assertSame('UNAVAILABLE', $service->cachedResult((string) $draw->getKey())['source_state']);
    }

    public function test_configured_metadata_key_is_used_by_page_api_and_publisher(): void
    {
        config(['glo.tiers.metadata_key' => 'provider_tiers']);
        $draw = $this->heldDraw();
        $result = $this->publishProviderResult($draw, 'official');
        self::assertSame(GloSourceState::OfficialSourceVerified, app(PublishedResultSourceResolver::class)->resolve($result, $draw->fresh()));
        self::assertSame($result->metadata['provider_tiers']['import_fingerprint'], app(GloPublicResultService::class)->currentResult((string) $draw->getKey())['result_version']);
        $this->get('/results')->assertOk()->assertSee('data-source-state="OFFICIAL_SOURCE_VERIFIED"', false);
        self::assertSame(0, Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey()]));
    }

    public function test_publication_payload_and_alias_expire_in_configured_seconds_not_minutes(): void
    {
        config(['glo.result_experience.cache_ttl_seconds' => 60]);
        $draw = $this->heldDraw();
        $this->publishProviderResult($draw);
        self::assertSame(0, Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey()]));
        $alias = 'glo:public-result:current:'.$draw->getKey();
        $key = Cache::get($alias);
        self::assertNotNull(Cache::get($key));
        $this->travel(61)->seconds();
        self::assertNull(Cache::get($alias));
        self::assertNull(Cache::get($key));
        $this->travelBack();
    }

    public function test_exact_replay_has_one_publication_audit_and_force_only_refreshes_same_version(): void
    {
        $draw = $this->heldDraw();
        $result = $this->publishProviderResult($draw);
        $before = $this->moneyCounts();
        $base = AuditLog::query()->where('description', 'glo_public_result_published')->count();
        foreach ([false, false, true] as $force) {
            self::assertSame(0, Artisan::call('glo:publish-public-result', ['--draw' => (string) $draw->getKey(), '--force' => $force]));
        }
        self::assertSame($base + 2, AuditLog::query()->where('description', 'glo_public_result_published')->count());
        self::assertSame($result->metadata['glo']['import_fingerprint'], Cache::get(Cache::get('glo:public-result:current:'.$draw->getKey()))['result_version']);
        self::assertSame($before, $this->moneyCounts());
    }
}
