<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\DTOs\Prize\PrizeClaimData;
use App\DTOs\Prize\PrizeClaimResultData;
use App\Enums\BetStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizeClaimStatus;
use App\Exceptions\PrizeClaimException;
use App\Models\Bet;
use App\Models\Payout;
use Illuminate\Support\Carbon;

final class PrizeClaimValidationService
{
    public const RULE_BET_EXISTS = 'bet_exists';

    public const RULE_WINNING_BET = 'winning_bet';

    public const RULE_OWNERSHIP = 'ownership';

    public const RULE_PAYOUT_OBLIGATION = 'payout_obligation';

    public const RULE_CLAIM_WINDOW = 'claim_window';

    public const RULE_DUPLICATE_CLAIM = 'duplicate_claim';

    public const RULE_TICKET_VERIFICATION = 'ticket_verification';

    public function __construct(
        private readonly ClaimWindowService $windowService,
    ) {
    }

    public function validate(PrizeClaimData $data): PrizeClaimResultData
    {
        $passed = [];
        $failed = [];

        $bet = Bet::query()->find($data->betId);

        if (! $bet instanceof Bet) {
            $failed[] = self::RULE_BET_EXISTS;

            return $this->ineligible($data, $failed, $passed, sprintf('Bet #%d does not exist.', $data->betId));
        }

        $passed[] = self::RULE_BET_EXISTS;

        if ($bet->status !== BetStatus::Won || bccomp((string) $bet->actual_payout, '0.00', 2) <= 0) {
            $failed[] = self::RULE_WINNING_BET;

            return $this->ineligible(
                $data,
                $failed,
                $passed,
                sprintf('Bet #%d is not a winning bet.', $data->betId),
            );
        }

        $passed[] = self::RULE_WINNING_BET;

        if ((int) $bet->user_id !== $data->claimantUserId) {
            $failed[] = self::RULE_OWNERSHIP;

            return $this->ineligible(
                $data,
                $failed,
                $passed,
                sprintf('User #%d is not the registered owner of bet #%d.', $data->claimantUserId, $data->betId),
            );
        }

        $guard = app(PrizeClaimGuard::class);
        $payout = $guard->resolve($bet);

        if (! $payout instanceof Payout) {
            $failed[] = self::RULE_PAYOUT_OBLIGATION;

            return $this->ineligible(
                $data,
                $failed,
                $passed,
                sprintf('No canonical payout obligation exists for bet #%d.', $data->betId),
            );
        }

        try {
            $guard->assertClaimant($payout, $data->claimantUserId);
        } catch (PrizeClaimException) {
            $failed[] = self::RULE_OWNERSHIP;

            return $this->ineligible($data, $failed, $passed, 'Claimant is not active or does not match the payout owner.');
        }

        $passed[] = self::RULE_OWNERSHIP;
        $passed[] = self::RULE_PAYOUT_OBLIGATION;

        $closesAt = $this->windowService->closesAtFor($payout, $bet);

        if (! $this->windowService->allowsClaim($payout)) {
            $failed[] = self::RULE_CLAIM_WINDOW;

            return $this->ineligible(
                $data,
                $failed,
                $passed,
                sprintf('The claim window for bet #%d is closed.', $data->betId),
                $closesAt,
            );
        }

        $passed[] = self::RULE_CLAIM_WINDOW;

        if ($payout->status === PayoutStatus::Completed) {
            $failed[] = self::RULE_DUPLICATE_CLAIM;

            return $this->ineligible($data, $failed, $passed, sprintf('The prize for bet #%d has already been paid.', $data->betId), $closesAt);
        }

        $claim = is_array($payout->metadata) ? ($payout->metadata['claim'] ?? null) : null;
        if (is_array($claim) && array_key_exists('status', $claim)) {
            $existingClaimant = isset($claim['claimant_user_id']) ? (int) $claim['claimant_user_id'] : null;
            $existingStatus = isset($claim['status']) ? PrizeClaimStatus::tryFrom((string) $claim['status']) : null;

            if ($existingClaimant !== $data->claimantUserId || $existingStatus === null || ! $existingStatus->isActive()) {
                $failed[] = self::RULE_DUPLICATE_CLAIM;

                return $this->ineligible(
                    $data,
                    $failed,
                    $passed,
                    sprintf('The prize for bet #%d cannot be claimed in its current state.', $data->betId),
                    $closesAt,
                );
            }
        }

        $passed[] = self::RULE_DUPLICATE_CLAIM;

        if ($data->verificationCode !== null || $data->ticketNumber !== null) {
            $failed[] = self::RULE_TICKET_VERIFICATION;

            return $this->ineligible(
                $data,
                $failed,
                $passed,
                'Paper-ticket possession secrets are not accepted on the digital prize-claim lane.',
                $closesAt,
            );
        }

        $passed[] = self::RULE_TICKET_VERIFICATION;

        return new PrizeClaimResultData(
            eligible: true,
            nextStatus: $guard->submissionStatus((string) $bet->actual_payout),
            amount: (string) $bet->actual_payout,
            currency: $payout->currency->value,
            windowClosesAt: $closesAt?->toIso8601String(),
            failedRules: [],
            passedRules: $passed,
            reason: null,
            context: [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
            ],
        );
    }

    public function mayProceed(PrizeClaimData $data): bool
    {
        return $this->validate($data)->mayProceed();
    }

    /**
     * @param  list<string>  $failed
     * @param  list<string>  $passed
     */
    private function ineligible(
        PrizeClaimData $data,
        array $failed,
        array $passed,
        string $reason,
        ?Carbon $windowClosesAt = null,
    ): PrizeClaimResultData {
        return new PrizeClaimResultData(
            eligible: false,
            nextStatus: PrizeClaimStatus::Rejected,
            amount: '0.00',
            currency: 'THB',
            windowClosesAt: $windowClosesAt?->toIso8601String(),
            failedRules: $failed,
            passedRules: $passed,
            reason: $reason,
            context: [
                'bet_id' => $data->betId,
                'claimant_user_id' => $data->claimantUserId,
            ],
        );
    }
}
