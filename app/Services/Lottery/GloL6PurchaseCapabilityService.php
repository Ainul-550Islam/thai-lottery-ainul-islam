<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\DTOs\Lottery\GloL6PricingSnapshotData;
use App\Enums\DrawStatus;
use App\Exceptions\GloL6PurchaseException;
use App\Models\Draw;
use App\Models\GloL6Sale;
use App\Models\LegalDocumentPublication;
use App\Services\Legal\AgeVerificationService;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Evaluates GLO L6 public/API ticket reservation & purchase capability.
 *
 * Fail-closed contract:
 *  - Returns status = NOT_CONFIGURED and enabled = false unless ALL required
 *    live/sandbox readiness gates pass (real-money lane, explicit GLO L6 purchase
 *    enablement, authoritative pricing contract, official result source without
 *    fixture fallback, legal/responsible-gaming admission gate, authorized
 *    inventory provider, and seated open draw series).
 *  - Never fabricates official GLO feeds, custodian inventory, or ticket availability.
 *  - Preserves canonical missing-requirement labels consumed by views/glo-l6/buy.blade.php.
 */
final class GloL6PurchaseCapabilityService
{
    public const STATUS_NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const STATUS_CONFIGURED = 'CONFIGURED';

    /**
     * Evaluate GLO L6 purchase capability, optionally scoped to a specific draw/series.
     *
     * @return array{
     *     status: string,
     *     enabled: bool,
     *     reason: string,
     *     missing: list<string>,
     *     gates: array<string, bool>,
     *     pricing: array<string, mixed>|null
     * }
     */
    public function capability(?Draw $draw = null, ?int $series = null): array
    {
        $isProduction = app()->environment('production');
        // Do not treat a mis-typed truthy string as authority to sell tickets.
        $realMoneyEnabled = app(\App\Services\Compliance\RealMoneyGate::class)->isOpen();
        $purchaseEnabled = (bool) config('glo.l6.purchase.enabled', false);

        $officialSourceReady = $this->isOfficialResultSourceReady($isProduction);
        $inventoryProviderReady = $this->isInventoryProviderReady($isProduction);
        $seatedDrawReady = $this->isSeatedDrawInventoryReady($draw, $series);
        $legalAndRgReady = $this->isLegalAndResponsibleGamingReady();
        $pricingSnapshot = $this->resolvePricingSnapshotSafely();
        $pricingReady = $pricingSnapshot instanceof GloL6PricingSnapshotData;

        $gates = [
            'real_money_enabled' => $realMoneyEnabled,
            'glo_l6_purchase_enabled' => $purchaseEnabled,
            'official_result_source_ready' => $officialSourceReady,
            'inventory_provider_ready' => $inventoryProviderReady,
            'seated_draw_inventory_ready' => $seatedDrawReady,
            'legal_and_rg_gate_ready' => $legalAndRgReady,
            'pricing_contract_ready' => $pricingReady,
        ];

        $missing = [];

        if (! $officialSourceReady || ! $seatedDrawReady) {
            $missing[] = 'public product and draw contract';
        }

        if (! $inventoryProviderReady) {
            $missing[] = 'selection inventory and validation contract';
        }

        if (! $legalAndRgReady) {
            $missing[] = 'responsible-gaming admission gate';
        }

        if (! $realMoneyEnabled || ! $purchaseEnabled) {
            $missing[] = 'wallet reservation and debit endpoint';
        }

        if (! $pricingReady || ! $purchaseEnabled) {
            $missing[] = 'ticket issuance, ledger and idempotency endpoint';
        }

        $enabled = $missing === [] && ! in_array(false, $gates, true);

        return [
            'status' => $enabled ? self::STATUS_CONFIGURED : self::STATUS_NOT_CONFIGURED,
            'enabled' => $enabled,
            'reason' => $enabled
                ? 'GLO L6 ticket reservation and purchase capability is active under configured governance gates.'
                : 'No GLO L6 public ticket-purchase API, wallet debit path, or ticket inventory contract is configured for this deployment.',
            'missing' => $missing,
            'gates' => $gates,
            'pricing' => $pricingSnapshot?->toArray(),
        ];
    }

    /**
     * Assert that GLO L6 purchase capability is fully configured and enabled, or fail closed.
     *
     * @throws GloL6PurchaseException
     */
    public function assertPurchasable(?Draw $draw = null, ?int $series = null): GloL6PricingSnapshotData
    {
        $capability = $this->capability($draw, $series);

        if ($capability['enabled'] !== true || $capability['status'] !== self::STATUS_CONFIGURED) {
            throw GloL6PurchaseException::notConfigured($capability);
        }

        $snapshot = $this->resolvePricingSnapshotSafely();
        if (! $snapshot instanceof GloL6PricingSnapshotData) {
            throw GloL6PurchaseException::notConfigured($capability);
        }

        return $snapshot;
    }

    private function isOfficialResultSourceReady(bool $isProduction): bool
    {
        $requireOfficial = (bool) config('glo.l6.purchase.require_official_result_source', true);

        if (! $requireOfficial && ! $isProduction) {
            return true;
        }

        $mode = strtolower(trim((string) config('glo.official_source.mode', 'fixture')));
        $baseUrl = trim((string) config('glo.official_source.base_url', ''));
        $latestPath = trim((string) config('glo.official_source.latest_lottery_path', ''));
        $resultPath = trim((string) config('glo.official_source.lottery_result_path', ''));
        $fallThroughToFixture = (bool) config('glo.sources.fall_through_to_fixture', false)
            || (bool) config('glo.result_sources.fall_through_to_fixture', false);

        if ($mode !== 'official' || $fallThroughToFixture) {
            return false;
        }

        return str_starts_with($baseUrl, 'https://')
            && filter_var($baseUrl, FILTER_VALIDATE_URL) !== false
            && str_starts_with($latestPath, '/')
            && str_starts_with($resultPath, '/');
    }

    private function isInventoryProviderReady(bool $isProduction): bool
    {
        $mode = strtolower(trim((string) config('glo.l6.purchase.inventory_provider_mode', 'not_configured')));

        if ($mode === 'custodian_inventory') {
            $providerRef = trim((string) config('glo.l6.purchase.custodian_provider_ref', ''));

            return $providerRef !== '';
        }

        // Deterministic seeded inventory is allowed ONLY outside production.
        if ($mode === 'sandbox_seeded' && ! $isProduction) {
            return true;
        }

        return false;
    }

    private function isSeatedDrawInventoryReady(?Draw $draw, ?int $series): bool
    {
        $requireSeated = (bool) config('glo.l6.purchase.require_seated_inventory', true);
        if (! $requireSeated) {
            return true;
        }

        try {
            if (! Schema::hasTable('draws') || ! Schema::hasTable('glo_l6_sales')) {
                return false;
            }

            if ($draw instanceof Draw) {
                $status = $draw->status instanceof DrawStatus
                    ? $draw->status
                    : DrawStatus::tryFrom((string) $draw->status);

                if ($status !== DrawStatus::Open || ! $draw->isOpen()) {
                    return false;
                }

                $saleQuery = GloL6Sale::query()
                    ->where('draw_id', (int) $draw->getKey())
                    ->where('product', 'l6')
                    ->where('units_sold', '>', 0);

                if ($series !== null) {
                    $saleQuery->where('series_number', $series);
                }

                return $saleQuery->exists();
            }

            return GloL6Sale::query()
                ->where('product', 'l6')
                ->where('units_sold', '>', 0)
                ->whereHas('draw', static function ($query): void {
                    $query->where('status', DrawStatus::Open->value);
                })
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function isLegalAndResponsibleGamingReady(): bool
    {
        if (! class_exists(ResponsibleGamingEnforcementService::class) || ! class_exists(AgeVerificationService::class)) {
            return false;
        }

        if (! (bool) config('glo.l6.purchase.require_legal_publications', true)) {
            return true;
        }

        try {
            if (! Schema::hasTable('legal_document_publications') || ! Schema::hasTable('legal_documents')) {
                return false;
            }

            $publishedTypes = LegalDocumentPublication::query()
                ->whereIn('document_type', ['terms', 'privacy'])
                ->whereHas('document', static function ($query): void {
                    $query->where('status', 'published');
                })
                ->distinct()
                ->pluck('document_type')
                ->all();

            return in_array('terms', $publishedTypes, true)
                && in_array('privacy', $publishedTypes, true);
        } catch (Throwable) {
            return false;
        }
    }

    private function resolvePricingSnapshotSafely(): ?GloL6PricingSnapshotData
    {
        try {
            $snapshot = GloL6PricingSnapshotData::fromConfig();

            if (bccomp($snapshot->unitPrice, '80.00', 2) !== 0) {
                return null;
            }

            return $snapshot;
        } catch (Throwable) {
            return null;
        }
    }
}
