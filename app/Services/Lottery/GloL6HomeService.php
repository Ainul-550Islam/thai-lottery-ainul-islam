<?php

declare(strict_types=1);

namespace App\Services\Lottery;

/**
 * Dedicated GLO L6 home composition.
 *
 * This service reuses the canonical public GLO home, result, next-draw, prize
 * and live-draw services. It deliberately does not call the legacy
 * GloResultsPageController or copy its hardcoded payload. L6 purchase remains
 * fail-closed because the public purchase contract is not verified.
 */
final class GloL6HomeService
{
    public function __construct(
        private readonly GloPublicHomeService $publicHome,
        private readonly GloL6PurchaseCapabilityService $purchaseCapability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pageData(): array
    {
        $product = $this->product();

        return [
            'product' => $product,
            'current_result' => $this->safe(
                fn (): array => $this->publicHome->currentResultCard(),
                $this->unavailableResult(),
            ),
            'next_draw' => $this->safe(
                fn (): array => $this->publicHome->nextDrawCard(),
                [
                    'status' => 'UNAVAILABLE',
                    'draw_number' => null,
                    'scheduled_at_display' => null,
                    'scheduled_at_iso' => null,
                    'timezone' => null,
                    'source' => 'none',
                    'message' => 'Next draw unavailable.',
                ],
            ),
            'live_draw' => $this->safe(
                fn (): array => $this->publicHome->liveCard(),
                [
                    'status' => 'UNAVAILABLE',
                    'live_status' => 'unavailable',
                    'replay_status' => 'unavailable',
                    'source_state' => 'UNAVAILABLE',
                    'provider' => null,
                    'embed_url' => null,
                    'message' => 'Live draw unavailable.',
                ],
            ),
            'prize_highlight' => $this->safe(
                fn (): array => $this->publicHome->prizeCard(),
                [
                    'status' => 'UNAVAILABLE',
                    'tier' => null,
                    'label' => null,
                    'amount' => null,
                    'draw_number' => null,
                    'draw_date' => null,
                    'source_state' => 'UNAVAILABLE',
                    'fixture_sample' => false,
                    'message' => 'Prize summary unavailable.',
                ],
            ),
            'purchase' => $this->purchaseCapability->capability(),
            'checker_url' => route('ticket-check'),
            'results_api_url' => route('api.v1.glo.results.current'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(): array
    {
        try {
            $summary = $this->publicHome->productSummary();
            $products = is_array($summary['products'] ?? null) ? $summary['products'] : [];

            foreach ($products as $candidate) {
                if (is_array($candidate) && strtoupper((string) ($candidate['code'] ?? '')) === 'L6') {
                    return [
                        'status' => 'CONFIGURED',
                        'code' => 'L6',
                        'name' => (string) ($candidate['name'] ?? ''),
                        'ticket_price' => (string) ($candidate['ticket_price'] ?? ''),
                        'currency' => (string) ($candidate['currency'] ?? ''),
                        'digits' => (int) ($candidate['digits'] ?? 0),
                        'headline_prize' => $candidate['headline_prize'] ?? null,
                        'notes' => (string) ($candidate['notes'] ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'status' => 'NOT_CONFIGURED',
            'code' => 'L6',
            'name' => null,
            'ticket_price' => null,
            'currency' => null,
            'digits' => null,
            'headline_prize' => null,
            'notes' => null,
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $builder
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function safe(callable $builder, array $fallback): array
    {
        try {
            $payload = $builder();

            return is_array($payload) ? $payload : $fallback;
        } catch (\Throwable $e) {
            report($e);

            return $fallback;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableResult(): array
    {
        return [
            'status' => 'UNAVAILABLE',
            'has_result' => false,
            'draw_number' => null,
            'draw_date' => null,
            'first_prize' => null,
            'second_prize' => [],
            'third_prize' => [],
            'front_three' => [],
            'last_three' => [],
            'last_two' => [],
            'adjacent_first' => [],
            'source_state' => 'UNAVAILABLE',
            'result_version' => null,
            'message' => 'Result unavailable.',
        ];
    }
}
