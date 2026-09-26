<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\Lottery\GloPublicResultService;
use App\Support\Admin\AdminAccess;
use Filament\Pages\Page;

/**
 * Filament page — GLO-18 public result dashboard (internal ops view).
 *
 * Shows the current verified result, source version fingerprint, publication
 * status, a short history sample, and provider health from config — always
 * labeled with GloSourceState (never bare "OFFICIAL" for internal entries).
 */
class GloResultDashboardPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'GLO Result Dashboard';

    protected static ?string $title = 'GLO Result Dashboard';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.glo-result-dashboard-page';

    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::VIEW_RESULTS,
            AdminAccess::VIEW_AUDIT_LOGS,
            AdminAccess::PUBLISH_GLO_PUBLIC_STATUS,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrent(): array
    {
        try {
            $payload = app(GloPublicResultService::class)->cachedResult(null);
        } catch (\App\Exceptions\GloDealerException $e) {
            return [
                'available' => false,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'available' => true,
            'payload' => $payload,
        ];
    }

    /**
     * Latest published draws with import fingerprint / source labels.
     *
     * @return list<array<string, mixed>>
     */
    public function getHistory(): array
    {
        return DrawResult::query()
            ->orderByDesc('published_at')
            ->limit(20)
            ->get()
            ->map(static function (DrawResult $result): array {
                $meta = is_array($result->metadata) ? $result->metadata : [];
                $lane = is_array($meta['glo'] ?? null) ? $meta['glo'] : [];
                $draw = Draw::query()->find($result->draw_id);
                $provider = (string) ($lane['import_provider'] ?? 'unknown');
                $sourceState = $provider === 'fixture'
                    ? \App\Enums\GloSourceState::FixtureOnly->value
                    : (\App\Enums\GloSourceState::OfficialSourceVerified->value);

                return [
                    'draw_number' => $draw?->draw_number ?? (string) $result->draw_id,
                    'first_prize' => $result->first_prize,
                    'published_at' => $result->published_at?->toDateTimeString(),
                    'source_version' => (string) ($lane['import_fingerprint'] ?? ('pub-'.$result->getKey())),
                    'import_provider' => $provider,
                    'source_state' => $sourceState,
                    'publication_status' => $draw?->status->value ?? 'unknown',
                ];
            })
            ->all();
    }

    /**
     * Provider health from config (no network probe from this page).
     *
     * @return array<string, mixed>
     */
    public function getProviderHealth(): array
    {
        $officialMode = (string) config('glo.official_source.mode', 'fixture');
        $live = (string) config('glo.result_experience.live_draw.mode', 'not_configured');
        $matrix = (string) config('glo.result_experience.data_matrix.mode', 'not_configured');
        $push = (string) config('glo.notifications.push_provider', 'not_configured');
        $sync = (string) config('glo.sales_points.official_sync.mode', 'not_configured');

        return [
            'result_provider_mode' => $officialMode,
            'live_draw' => $live === 'not_configured'
                ? \App\Enums\GloSourceState::NotConfigured->value
                : \App\Enums\GloSourceState::OfficialSourceConfigured->value,
            'data_matrix' => $matrix === 'not_configured'
                ? \App\Enums\GloSourceState::NotConfigured->value
                : \App\Enums\GloSourceState::OfficialSourceConfigured->value,
            'push_provider' => $push === 'not_configured'
                ? \App\Enums\GloSourceState::NotConfigured->value
                : \App\Enums\GloSourceState::OfficialSourceConfigured->value,
            'sales_point_sync' => $sync === 'not_configured'
                ? \App\Enums\GloSourceState::NotConfigured->value
                : \App\Enums\GloSourceState::OfficialSourceConfigured->value,
        ];
    }
}
