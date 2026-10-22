<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\UserStatus;
use App\Enums\WalletConversionDirection;
use App\Models\KycDocument;
use App\Models\ResponsibleGamingLimit;
use App\Models\User;
use App\Services\ResponsibleGaming\SelfExclusionService;
use Illuminate\Support\Facades\DB;

trait ConversionMemberEligibilityFixture
{
    use ConversionQuoteSlotFixture;

    protected function memberFixture(): array
    {
        $f = $this->slotFixture();
        $f['user']->forceFill(['phone_verified_at' => now()])->save();

        return $f;
    }

    protected function changeMember(array $f, string $profile): void
    {
        $id = (int) $f['user']->id;
        switch ($profile) {
            case 'suspended':
            case 'exact-replay':
                User::query()->whereKey($id)->update(['status' => UserStatus::Suspended->value]);
                if ($profile === 'suspended') {
                    break;
                }
                // Replay is observation, not a new grant of any of these facts.
                User::query()->whereKey($id)->update(['phone_verified_at' => null]);
                KycDocument::query()->where('user_id', $id)->update(['status' => 'rejected']);
                ResponsibleGamingLimit::query()->create(['user_id' => $id, 'self_excluded_until' => now()->addDay()]);
                break;
            case 'canonical-inserted':
                $service = app(SelfExclusionService::class);
                $row = $service->request(SelfExclusionData::fromInput(['user_id' => $id,
                    'reason_code' => 'PLAYER_REQUEST', 'ends_at' => now()->addDays(7)]));
                $service->activate($row);
                // Prove the canonical gate also survives projection drift.
                ResponsibleGamingLimit::query()->where('user_id', $id)->update(['self_excluded_until' => null]);
                break;
            case 'legacy-inserted':
            case 'cooloff-inserted':
                ResponsibleGamingLimit::query()->create(['user_id' => $id,
                    $profile === 'legacy-inserted' ? 'self_excluded_until' : 'cool_off_until' => now()->addDay()]);
                break;
            case 'kyc-revoked':
                KycDocument::query()->where('user_id', $id)->update(['status' => 'rejected']);
                break;
            case 'kyc-deleted':
                KycDocument::query()->where('user_id', $id)->delete();
                break;
            case 'kyc-expired':
                KycDocument::query()->where('user_id', $id)->update(['expires_at' => now()->subSecond()]);
                break;
            case 'phone-revoked':
                User::query()->whereKey($id)->update(['phone_verified_at' => null]);
                break;
            case 'positive-restored':
                User::query()->whereKey($id)->update(['status' => UserStatus::Active->value]);
                break;
            default:
                throw new \LogicException('Unknown member eligibility fixture profile.');
        }
    }

    protected function memberSnapshot(string $status = 'active'): array
    {
        return ['status' => $status, 'self_excluded_until' => null, 'cool_off_until' => null,
            'canonical_active_count' => 0, 'kyc_status' => 'verified', 'kyc_expires_at' => null, 'phone_verified' => true];
    }

    protected function memberRequests(array $f): array
    {
        return [$this->slotRequest($f, 'member-eligibility-cash-0001'),
            $this->slotRequest($f, 'member-eligibility-win-0001', WalletConversionDirection::WinToCash)];
    }

    protected function memberFinancialRows(): int
    {
        return DB::table('financial_transactions')->count();
    }
}
