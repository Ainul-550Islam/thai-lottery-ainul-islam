<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\FinancialException;
use App\Models\PromoCouponRedemption;
use App\Models\User;
use App\Services\Agent\PublicReferralLinkService;
use App\Services\Loyalty\LoyaltyPointsService;
use App\Services\Marketing\SocialProofService;
use App\Services\Notification\PushNotificationService;
use App\Services\Promotions\CouponRedemptionService;
use App\Support\ResponsibleGamingGate;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Authenticated Member Rewards, Coupons, Referrals & Push Hub (Audit §D6).
 *
 * Session-scoped exclusively (`$request->user()`): no client-supplied user IDs
 * or wallet IDs are accepted anywhere on this surface.
 */
final class MemberRewardsController extends Controller
{
    public function __construct(
        private readonly CouponRedemptionService $coupons,
        private readonly LoyaltyPointsService $loyalty,
        private readonly PublicReferralLinkService $referrals,
        private readonly PushNotificationService $push,
        private readonly SocialProofService $socialProof,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $selfExcluded = ResponsibleGamingGate::isSelfExcluded($user);

        $recentCouponRedemptions = PromoCouponRedemption::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('member.rewards', [
            'selfExcluded' => $selfExcluded,
            'pointsBalance' => $this->loyalty->currentBalance($user),
            'pointsHistory' => $this->loyalty->recentHistory($user, 15),
            'rewardsCatalogue' => $selfExcluded ? [] : $this->loyalty->availableRewards(),
            'couponHistory' => $recentCouponRedemptions,
            'referralSummary' => $this->referrals->dashboardSummaryFor($user),
            'activePushCount' => $this->push->activeSubscriptionsFor($user)->count(),
            'pushEnabled' => (bool) config('conversion_tooling.push.enabled', false),
            'vapidPublicKey' => (string) config('conversion_tooling.push.fcm.web_vapid_public_key', ''),
        ]);
    }

    public function redeemCoupon(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        try {
            $redemption = $this->coupons->redeem($user, (string) $validated['code'], $request->ip());

            return redirect()
                ->route('member.rewards')
                ->with('status', __('conversion.coupon_redeemed_success', [
                    'amount' => $redemption->granted_amount,
                    'currency' => $redemption->currency,
                ]));
        } catch (FinancialException $e) {
            return redirect()
                ->route('member.rewards')
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    public function redeemLoyalty(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'reward_key' => ['required', 'string', 'max:64'],
        ]);

        try {
            $redemption = $this->loyalty->redeemReward($user, (string) $validated['reward_key']);

            return redirect()
                ->route('member.rewards')
                ->with('status', __('conversion.loyalty_redeemed_success', [
                    'points' => $redemption->points_spent,
                    'amount' => $redemption->bonus_amount,
                    'currency' => $redemption->currency,
                ]));
        } catch (FinancialException $e) {
            return redirect()
                ->route('member.rewards')
                ->with('error', $e->getMessage());
        }
    }

    public function submitTestimonial(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'quote' => ['required', 'string', 'min:20', 'max:280'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        try {
            $this->socialProof->submitTestimonial(
                author: $user,
                rawQuote: (string) $validated['quote'],
                rating: (int) $validated['rating'],
                locale: app()->getLocale(),
            );

            return redirect()
                ->route('member.rewards')
                ->with('status', __('conversion.testimonial_submitted'));
        } catch (DomainException|InvalidArgumentException $e) {
            return redirect()
                ->route('member.rewards')
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    public function registerPushToken(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:4096'],
            'platform' => ['nullable', 'string', 'in:web,android,ios'],
            'provider' => ['nullable', 'string', 'in:fcm,onesignal'],
        ]);

        $sub = $this->push->registerSubscription(
            user: $user,
            rawToken: (string) $validated['token'],
            platform: (string) ($validated['platform'] ?? 'web'),
            provider: isset($validated['provider']) ? (string) $validated['provider'] : null,
            locale: app()->getLocale(),
        );

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'subscription_id' => $sub->getKey(),
                'provider' => $sub->provider,
            ]);
        }

        return redirect()
            ->route('member.rewards')
            ->with('status', __('conversion.push_registered'));
    }

    public function revokePushToken(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:4096'],
        ]);

        $revoked = $this->push->revokeSubscription($user, (string) $validated['token']);

        if ($request->expectsJson()) {
            return response()->json(['ok' => $revoked]);
        }

        return redirect()
            ->route('member.rewards')
            ->with('status', __('conversion.push_revoked'));
    }
}
