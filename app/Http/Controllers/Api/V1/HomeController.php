<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Home\HomePageDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Home API endpoint.
 *
 * Provides structured JSON for dynamic widgets without exposing any internal credentials,
 * player data, or raw payment provider tokens.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly HomePageDataService $homeData,
    ) {}

    /**
     * GET /api/v1/public/home
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        unset($request);

        $data = $this->homeData->pageData();

        // Sanitise public API output: ensure no CSRF or internal session data is leaked.
        unset($data['csrf']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'hero' => $data['hero'] ?? [],
                'next_draw' => $data['next_draw'] ?? [],
                'countdown' => $data['countdown'] ?? [],
                'current_result' => $data['current_result'] ?? [],
                'lotteries' => $data['lottery_feed']['products'] ?? [],
                'latest_results' => $data['lane_results']['lanes'] ?? [],
                'prizes' => $data['prize_highlight'] ?? [],
                'bonuses' => $data['bonuses']['campaigns'] ?? [],
                'payment_methods' => $data['payment_methods']['methods'] ?? [],
                'app_links' => $data['app_links'] ?? [],
                'stats' => $data['stats'] ?? [],
                'trust' => $data['trust'] ?? [],
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'timezone' => 'Asia/Bangkok',
                'version' => 'v1',
            ],
        ]);
    }
}
