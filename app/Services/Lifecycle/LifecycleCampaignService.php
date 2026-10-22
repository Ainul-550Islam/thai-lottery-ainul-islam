<?php

declare(strict_types=1);

namespace App\Services\Lifecycle;

use App\DTOs\Notification\NotificationMessageData;
use App\Enums\DepositStatus;
use App\Enums\LifecycleCampaign;
use App\Enums\NotificationChannel;
use App\Enums\SelfExclusionStatus;
use App\Models\AccountGradeSnapshot;
use App\Models\LifecycleCampaignSend;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Services\Notification\NotificationDispatchService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lifecycle email campaigns (D4).
 *
 * A 2026 audit found five transactional notifications and no lifecycle
 * messaging at all. This service closes that gap.
 *
 * THREE RULES EVERY METHOD HERE OBEYS
 * -----------------------------------
 *
 * 1. A SELF-EXCLUDED PLAYER IS NEVER CONTACTED. This is not a preference and
 *    not a suppression list entry — it is checked separately and first, and
 *    it is not configurable. Sending a win-back email to someone who has
 *    excluded themselves to protect their own finances is a responsible-
 *    gaming failure, and regulators treat it as one. A preference can be
 *    turned back on by the player; this cannot be overridden by anything in
 *    this file.
 *
 * 2. ONE CAMPAIGN REACHES A PLAYER ONCE. Enforced by the unique index on
 *    lifecycle_campaign_sends (user_id, campaign), not by a flag in memory.
 *    A scheduler that runs twice must not send twice.
 *
 * 3. A RUN IS BOUNDED. `max_sends_per_run` caps the blast radius of a bad
 *    eligibility query, and every send goes through the existing dispatch
 *    service so suppression, preferences and quiet hours are honoured exactly
 *    as they are for transactional mail.
 *
 * WHY THIS IS NOT A MAIL-CHIMP INTEGRATION
 * The player data that decides eligibility — balances, bets, exclusions —
 * is money data. Exporting it to a third-party marketing platform to do this
 * segmentation is a data-protection decision far larger than the feature.
 * Running the query where the data already lives keeps the segmentation
 * inside the same boundary as the rest of the product.
 */
final class LifecycleCampaignService
{
    public function __construct(
        private readonly NotificationDispatchService $dispatch,
    ) {}

    /**
     * Run one campaign.
     *
     * @return array<string, mixed>
     */
    public function run(LifecycleCampaign $campaign, ?bool $dryRun = null, ?int $limit = null): array
    {
        if (! $this->enabled()) {
            return $this->report($campaign, disabled: true);
        }

        $settings = $this->settings($campaign);

        if (! (bool) ($settings['enabled'] ?? false)) {
            return $this->report($campaign, campaignDisabled: true);
        }

        $dryRun = $dryRun ?? (bool) ($settings['dry_run'] ?? true);
        $cap = $limit ?? (int) config('lifecycle.max_sends_per_run', 200);
        $cap = max(0, $cap);

        $candidates = $this->eligibleUserIds($campaign);

        // Cheapest filters first: a database-level exclusion of everyone the
        // campaign has already reached, before a single model is hydrated.
        $unsent = LifecycleCampaignSend::filterUnsent($campaign, $candidates);

        $report = $this->report($campaign, dryRun: $dryRun);
        $report['eligible'] = count($candidates);
        $report['already_sent'] = count($candidates) - count($unsent);

        $sent = 0;

        foreach ($unsent as $userId) {
            if ($sent >= $cap) {
                $report['cap_reached'] = true;
                break;
            }

            $outcome = $this->attempt($campaign, $userId, $dryRun);
            $report[$outcome] = ($report[$outcome] ?? 0) + 1;

            if ($outcome === 'sent') {
                $sent++;
            }
        }

        $report['sent'] = $sent;

        Log::info('lifecycle.campaign.run', $report);

        return $report;
    }

    /**
     * The standing query: who does this campaign apply to right now?
     *
     * Returns ids only, deliberately. The caller may need tens of thousands of
     * them, and hydrating models to make an eligibility decision that turns
     * out to be "already sent" is wasted work on the most expensive possible
     * scale.
     *
     * @return list<int>
     */
    public function eligibleUserIds(LifecycleCampaign $campaign): array
    {
        $settings = $this->settings($campaign);
        $now = now();

        $ids = match ($campaign) {
            // Registered recently, nothing wagered.
            LifecycleCampaign::Welcome => User::query()
                ->where('created_at', '<=', $now->copy()->subHours((int) ($settings['delay_hours'] ?? 6)))
                ->where('created_at', '>=', $now->copy()->subHours((int) ($settings['max_age_hours'] ?? 72)))
                ->whereDoesntHave('bets')
                ->pluck('id')
                ->all(),

            // Registered, never funded. The biggest drop in the funnel.
            LifecycleCampaign::DepositNudge => User::query()
                ->where('created_at', '<=', $now->copy()->subHours((int) ($settings['delay_hours'] ?? 48)))
                ->where('created_at', '>=', $now->copy()->subHours((int) ($settings['max_age_hours'] ?? 336)))
                ->whereDoesntHave('deposits', function ($query): void {
                    $query->whereIn('status', $this->successfulDepositStates());
                })
                ->pluck('id')
                ->all(),

            // Active recently, so a reminder is relevant rather than noise.
            LifecycleCampaign::DrawReminder => User::query()
                ->whereHas('bets', function ($query) use ($settings): void {
                    $query->where('created_at', '>=', now()->subDays((int) ($settings['lookback_days'] ?? 60)));
                })
                ->pluck('id')
                ->all(),

            // Bet at least once, then stopped.
            LifecycleCampaign::Winback => User::query()
                ->whereHas('bets', function ($query) use ($settings): void {
                    $query->where('created_at', '<=', now()->subDays((int) ($settings['dormant_days'] ?? 30)))
                        ->where('created_at', '>=', now()->subDays((int) ($settings['max_dormant_days'] ?? 365)));
                })
                ->whereDoesntHave('bets', function ($query) use ($settings): void {
                    $query->where('created_at', '>', now()->subDays((int) ($settings['dormant_days'] ?? 30)));
                })
                ->pluck('id')
                ->all(),

            // Grade actually changed, not merely recalculated at the same tier.
            LifecycleCampaign::GradePromotion => AccountGradeSnapshot::query()
                ->where('calculated_at', '>=', now()->subDays((int) ($settings['within_days'] ?? 7)))
                ->whereColumn('grade_key', '!=', 'previous_grade_key')
                ->pluck('user_id')
                ->all(),
        };

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Attempt one send. Returns a short machine outcome for the run report.
     */
    private function attempt(LifecycleCampaign $campaign, int $userId, bool $dryRun): string
    {
        // In dry run we report intent without claiming the slot: the operator
        // wants to know who WOULD be contacted, and a dry run must not consume
        // the one send a player is allowed.
        if ($dryRun) {
            return 'would_send';
        }

        // Claim the slot BEFORE sending. If the process dies between here and
        // the send, the player is simply not contacted — the safe direction.
        // Claiming after sending risks the opposite: a crash after the send and
        // before the claim leaves the pair open, and the next run sends again.
        $claim = LifecycleCampaignSend::claim($userId, $campaign);

        if ($claim === null) {
            return 'already_claimed';
        }

        try {
            $user = User::query()->find($userId);
            if (! $user instanceof User) {
                return $this->abandon($claim, 'no_user');
            }

            $blocked = $this->blockReasonFor($user);
            if ($blocked !== null) {
                return $this->abandon($claim, $blocked);
            }

            $result = $this->dispatch->pronounce($userId, $this->messageFor($campaign, $user));

            if (($result['suppressed'] ?? false) === true) {
                // Suppressed by the player's own preference or by a quiet
                // window. The slot is still consumed: they were eligible, and
                // contacting them later when the window lifts is a different
                // campaign, not a retry of this one.
                return $this->abandon($claim, 'suppressed');
            }

            $claim->forceFill(['sent_at' => now()])->save();

            return 'sent';
        } catch (Throwable $e) {
            Log::warning('lifecycle.campaign.send_failed', [
                'campaign' => $campaign->value,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->abandon($claim, 'error');
        }
    }

    /**
     * The responsible-gaming gate.
     *
     * Returns a machine reason, or null when the player may be contacted.
     *
     * This is intentionally NOT routed through the notification preference
     * system. A preference is a choice the player made and can unmake; a
     * self-exclusion is a protective measure, and the operator does not get a
     * config flag to ignore it.
     */
    private function blockReasonFor(User $user): ?string
    {
        $excluded = SelfExclusion::query()
            ->where('user_id', $user->getKey())
            ->where('status', SelfExclusionStatus::Active->value)
            ->get()
            ->contains(fn (SelfExclusion $row): bool => $row->currentlyGates());

        if ($excluded) {
            return 'self_excluded';
        }

        return null;
    }

    private function messageFor(LifecycleCampaign $campaign, User $user): NotificationMessageData
    {
        $eventType = $campaign->eventType();
        [$subject, $body] = $this->copyFor($campaign, $user);

        return NotificationMessageData::fromInput([
            'user_id' => $user->getKey(),
            'event_type' => $eventType,
            'channel' => NotificationChannel::Email,
            'locale' => $this->localeFor($user),
            'subject' => $subject,
            'body' => $body,
            // No template: these are managed by code, not by an operator
            // editing rows in notification_templates.
            'template_id' => null,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function copyFor(LifecycleCampaign $campaign, User $user): array
    {
        $name = trim((string) ($user->name ?? ''));
        $greeting = $name !== '' ? 'Hi '.$name.',' : 'Hi,';

        return match ($campaign) {
            LifecycleCampaign::Welcome => [
                'Welcome to ThaiLotto',
                $greeting."\n\nYour account is ready. When you are, have a look at the "
                .'draws that are open and how they work — there is no rush and nothing '
                ."expires.\n\nIf you ever want to set a limit on what you spend, you can "
                .'do that in your account settings at any time.',
            ],
            LifecycleCampaign::DepositNudge => [
                'Your first deposit, whenever you are ready',
                $greeting."\n\nYou have an account with us but have not added funds yet, "
                ."so nothing has been entered into a draw.\n\nDeposits are entirely "
                .'optional, and the limits you can set in your account apply from the '
                .'first one.',
            ],
            LifecycleCampaign::DrawReminder => [
                'A draw closes soon',
                $greeting."\n\nThere is a draw closing in the next day or so. If you were "
                ."planning to enter, the cut-off is earlier than the draw itself.\n\n"
                .'You are seeing this because you entered a draw recently.',
            ],
            LifecycleCampaign::Winback => [
                'We have not seen you in a while',
                $greeting."\n\nIt has been a while since you entered a draw. Nothing has "
                ."changed on your account and there is nothing you need to do.\n\nIf you "
                .'would rather not hear from us, you can turn these emails off in your '
                .'notification settings.',
            ],
            LifecycleCampaign::GradePromotion => [
                'Your account grade has moved up',
                $greeting."\n\nYour account grade has changed, which affects the discount "
                .'applied to your bets. You can see what it is now in the account grade '
                ."section.\n\nA higher grade is not an invitation to stake more.",
            ],
        };
    }

    private function localeFor(User $user): string
    {
        $locale = $user->locale ?? null;

        return is_string($locale) && $locale !== '' ? strtolower(substr($locale, 0, 8)) : 'en';
    }

    private function abandon(LifecycleCampaignSend $claim, string $reason): string
    {
        $claim->forceFill(['skip_reason' => $reason])->save();

        return $reason;
    }

    /**
     * @return list<string>
     */
    private function successfulDepositStates(): array
    {
        return collect(DepositStatus::cases())
            ->filter(fn (DepositStatus $status): bool => $status->isSuccessful())
            ->map(fn (DepositStatus $status): string => $status->value)
            ->values()
            ->all();
    }

    private function enabled(): bool
    {
        return (bool) config('lifecycle.enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(LifecycleCampaign $campaign): array
    {
        $settings = config('lifecycle.campaigns.'.$campaign->value, []);

        return is_array($settings) ? $settings : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(
        LifecycleCampaign $campaign,
        bool $dryRun = false,
        bool $disabled = false,
        bool $campaignDisabled = false,
    ): array {
        return [
            'campaign' => $campaign->value,
            'dry_run' => $dryRun,
            'disabled' => $disabled,
            'campaign_disabled' => $campaignDisabled,
            'eligible' => 0,
            'already_sent' => 0,
            'sent' => 0,
            'would_send' => 0,
            'self_excluded' => 0,
            'suppressed' => 0,
            'no_user' => 0,
            'error' => 0,
            'already_claimed' => 0,
            'cap_reached' => false,
        ];
    }
}
