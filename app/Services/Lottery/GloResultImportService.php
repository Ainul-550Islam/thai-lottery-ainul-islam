<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\ResultSourceType;
use App\Enums\RiskLevel;
use App\Exceptions\DrawResultException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloResultImport;
use App\Models\User;
use App\Services\Draw\DrawResultIngestionService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * GLO result import service — provenance-tracked intake of official/fixture
 * results via GloResultProviderChain and DrawResultIngestionService.
 */
class GloResultImportService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloResultProviderChain $chain,
        private readonly DrawResultIngestionService $ingestion,
    ) {}

    public function provider(): GloResultProvider
    {
        return $this->chain;
    }

    /**
     * Import a result for a draw.
     *
     * @return array{
     *     import: GloResultImport,
     *     draw_result: DrawResult|null,
     *     payload: array<string, mixed>,
     *     ingestion: array<string, mixed>|null
     * }
     */
    public function importForDraw(Draw $draw, ?User $actor = null, ?string $drawNumberOverride = null): array
    {
        $provider = $this->provider();
        $drawNumber = $drawNumberOverride ?? (string) $draw->draw_number;
        $payload = $provider->fetch($drawNumber);

        return $this->persistPayload($draw, $provider, $payload, $actor);
    }

    /**
     * Import without a local Draw row (latest provider replay) — records
     * provenance only when no local Draw matches and never writes a result.
     *
     * @return array{
     *     import: GloResultImport,
     *     draw_result: DrawResult|null,
     *     payload: array<string, mixed>,
     *     ingestion: array<string, mixed>|null
     * }
     */
    public function importLatest(?User $actor = null): array
    {
        $provider = $this->provider();
        $payload = $provider->fetch(null);

        $draw = null;
        $providerDraw = (string) ($payload['draw_number'] ?? '');

        if ($providerDraw !== '') {
            $draw = Draw::query()
                ->where('draw_number', $providerDraw)
                ->orWhere('uuid', $providerDraw)
                ->first();
        }

        if ($draw === null) {
            $import = $this->recordImport(null, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        return $this->persistPayload($draw, $provider, $payload, $actor);
    }

    /**
     * Bounded redacted intake summary for operator health/status diagnostics.
     *
     * @return list<array<string, mixed>>
     */
    public function recentIntake(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        return GloResultImport::query()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(static fn (GloResultImport $import): array => [
                'id' => $import->id,
                'import_reference' => $import->import_reference,
                'draw_id' => $import->draw_id,
                'provider' => $import->provider,
                'mode' => $import->mode,
                'status' => $import->status,
                'upstream_draw_id' => $import->upstream_draw_id,
                'result_fingerprint' => $import->result_fingerprint,
                'failure_reason' => $import->failure_reason,
                'payload_summary' => $import->payload_summary,
                'imported_at' => $import->imported_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * File the provider payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     import: GloResultImport,
     *     draw_result: DrawResult|null,
     *     payload: array<string, mixed>,
     *     ingestion: array<string, mixed>|null
     * }
     */
    private function persistPayload(Draw $draw, GloResultProvider $provider, array $payload, ?User $actor): array
    {
        $drawId = (int) $draw->getKey();
        $status = (string) ($payload['status'] ?? 'failed');

        if ($status !== 'imported') {
            $payload['status'] = (string) ($payload['provider_status'] ?? $status);
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        $fingerprint = trim((string) ($payload['fingerprint'] ?? ''));

        if ($fingerprint === '') {
            $payload['status'] = 'failed';
            $payload['failure_reason'] = 'Provider returned an imported payload without a result fingerprint.';
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        $bottomTwo = $this->extractBottomTwo($payload);

        if ($bottomTwo === null) {
            $payload['status'] = 'refused';
            $payload['failure_reason'] = 'Ingestion refused: bottom_two is required and must be a separately drawn two-digit number.';
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        $existing = GloResultImport::query()
            ->where('draw_id', $drawId)
            ->where('result_fingerprint', $fingerprint)
            ->where('status', 'imported')
            ->first();

        if ($existing instanceof GloResultImport) {
            return [
                'import' => $existing,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => $this->ingestion->currentIngestion($drawId),
            ];
        }

        $answeringProvider = (string) ($payload['provider'] ?? $provider->name());

        try {
            $ingestion = $this->ingestion->ingest(
                $drawId,
                [
                    'first_prize' => (string) ($payload['first_prize'] ?? ''),
                    'bottom_two' => $bottomTwo,
                    'second_prize' => array_values((array) ($payload['second_prize'] ?? [])),
                    'third_prize' => array_values((array) ($payload['third_prize'] ?? [])),
                    'tiers' => (array) ($payload['tiers'] ?? []),
                    'n3' => (array) ($payload['n3'] ?? []),
                    'provider' => $answeringProvider,
                    'provider_fingerprint' => $fingerprint,
                ],
                source: sprintf('glo_import:%s', $answeringProvider),
                actorUserId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
            );
        } catch (DrawResultException $e) {
            $payload['status'] = 'refused';
            $payload['failure_reason'] = 'Ingestion refused: '.$e->getMessage();
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return [
                'import' => $import,
                'draw_result' => null,
                'payload' => $payload,
                'ingestion' => null,
            ];
        }

        $import = $this->recordImport($drawId, $provider, $payload, $actor, $fingerprint);

        $this->recordAudit($drawId, $answeringProvider, $import, $ingestion, $fingerprint, $actor);

        return [
            'import' => $import,
            'draw_result' => null,
            'payload' => $payload,
            'ingestion' => $ingestion,
        ];
    }

    /**
     * Extract the separately drawn two-digit prize from the provider payload.
     * Never derives bottom_two from first_prize.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractBottomTwo(array $payload): ?string
    {
        $direct = $payload['bottom_two'] ?? null;

        if (is_string($direct) && preg_match('/^\d{2}$/', $direct) === 1) {
            return $direct;
        }

        $lastTwo = $payload['tiers']['last_two'] ?? null;

        if (is_array($lastTwo) && count($lastTwo) === 1) {
            $candidate = reset($lastTwo);
            if (is_string($candidate) && preg_match('/^\d{2}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Write the intake provenance row.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordImport(
        ?int $drawId,
        GloResultProvider $provider,
        array $payload,
        ?User $actor,
        ?string $normalizedFingerprint = null,
    ): GloResultImport {
        $status = (string) ($payload['status'] ?? 'failed');
        $mode = (string) config('glo.official_source.mode', 'fixture');
        $answeringProvider = (string) ($payload['provider'] ?? $provider->name());

        return GloResultImport::query()->create([
            'draw_id' => $drawId,
            'provider' => $answeringProvider,
            'mode' => $mode,
            'status' => $status,
            'endpoint' => isset($payload['endpoint']) ? (string) $payload['endpoint'] : null,
            'upstream_draw_id' => isset($payload['draw_number']) ? (string) $payload['draw_number'] : null,
            'result_fingerprint' => $normalizedFingerprint
                ?? (isset($payload['fingerprint']) && (string) $payload['fingerprint'] !== '' ? (string) $payload['fingerprint'] : null),
            'failure_reason' => isset($payload['failure_reason']) ? (string) $payload['failure_reason'] : null,
            'import_reference' => 'glo-'.Str::lower(Str::ulid()->toBase32()),
            'imported_by' => $actor?->exists ? $actor->getKey() : null,
            'imported_at' => now(),
            'payload_summary' => $this->redact($payload),
        ]);
    }

    /**
     * @param  array<string, mixed>  $ingestion
     */
    private function recordAudit(
        int $drawId,
        string $providerName,
        GloResultImport $import,
        array $ingestion,
        string $providerFingerprint,
        ?User $actor,
    ): void {
        AuditLog::create([
            'user_id' => $actor?->exists ? $actor->getKey() : null,
            'action' => AuditAction::DataImport,
            'risk_level' => RiskLevel::High,
            'auditable_type' => GloResultImport::class,
            'auditable_id' => $import->getKey(),
            'description' => 'glo_result_ingested_pending_confirmation',
            'metadata' => [
                'action_type' => 'glo_result_ingested_pending_confirmation',
                'provider' => $providerName,
                'draw_id' => $drawId,
                'import_reference' => $import->import_reference,
                'provider_fingerprint' => $providerFingerprint,
                'ingestion_fingerprint' => $ingestion['fingerprint'] ?? null,
                'ingestion_status' => $ingestion['status'] ?? null,
                'first_prize' => (string) ($ingestion['first_prize'] ?? ''),
                'bottom_two' => (string) ($ingestion['bottom_two'] ?? ''),
                'source_type' => ResultSourceType::Import->value,
                'publication_state' => 'pending_second_operator_confirmation',
            ],
        ]);
    }

    /**
     * Strip anything credential-shaped before a provider payload is persisted.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $forbidden = ['authorization', 'api_key', 'apikey', 'secret', 'token', 'password', 'signature'];

        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }
}
