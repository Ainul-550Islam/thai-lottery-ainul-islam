<?php

declare(strict_types=1);

namespace Tests\Feature\Betting\Concerns;

use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BetCancellationData;
use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\BetCancellationReason;
use App\Enums\Currency;
use App\Enums\WalletType;
use App\Models\AgeVerification;
use App\Models\User;
use App\Services\Betting\BetPurchaseService;
use App\Services\Finance\WalletService;
use App\Services\Legal\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait BuildsOwnedBetPurchase
{
    protected function purchaseFixture(string $market = '3d_direct', string $number = '007', Currency $currency = Currency::THB, bool $bonus = false): array
    {
        config(['lottery.betting.currency' => $currency->value]);
        $user = $this->onboardedPlayer();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, $currency);
        if ($bonus) {
            $wallet->forceFill(['type' => WalletType::Bonus])->save();
        }
        $this->creditFixture($wallet, '100.00', 'Owned cancellation fixture');
        $selection = new BulkBetSelectionData(marketKey: $market, number: $number, stake: '10.00');
        $draw = $this->openBulkDrawForTest([$selection]);
        $result = app(BetPurchaseService::class)->purchase(new BetPurchaseData(userId: $user->id, drawId: $draw->id,
            marketKey: $market, rawNumber: $number, rawStake: '10.00', idempotencyKey: 'owned-cancellation-fixture'));
        $bet = $result->bet;
        $claim = DB::table('bet_purchase_ownerships')->where('bet_id', $bet->id)->sole();

        return compact('user', 'wallet', 'draw', 'result', 'bet', 'claim');
    }

    /**
     * A player who may reach the money endpoints.
     *
     * The purchase services do not care, but the endpoints that wrap them are gated:
     * `adult` refuses an account with no 18+ attestation and `legal.current` refuses
     * one that has not accepted the current terms and privacy notice. A bare factory
     * user therefore answered 403 on every HTTP cancellation assertion, which reads as
     * "cancellation is forbidden" rather than "the fixture never onboarded".
     */
    protected function onboardedPlayer(): User
    {
        $user = User::factory()->create([
            'date_of_birth' => now()->subYears(30)->toDateString(),
        ]);

        return $this->attestPlayer($user);
    }

    /**
     * Make an already-built fixture user pass the endpoint gates.
     *
     * Fixtures that arrive from other suites (commission, agent) create a bare factory
     * user; the money endpoints wrap them in `adult` and `legal.current`, so an HTTP
     * assertion against such a user answers 403/428 before the domain is ever reached.
     */
    protected function attestPlayer(User $user): User
    {
        // Eligibility is recomputed from the date of birth, so an attestation row on an
        // account with no birth date is still not eligible.
        if ($user->date_of_birth === null) {
            $user->forceFill(['date_of_birth' => now()->subYears(30)->toDateString()])->save();
        }

        AgeVerification::query()->create([
            'user_id' => $user->getKey(),
            'status' => 'eligible',
            'minimum_age' => (int) config('legal.minimum_age', 18),
            'method' => 'self_declaration',
            'attested_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'source' => 'test',
        ]);

        $documents = collect(app(ConsentService::class)->currentDocuments())->keyBy('document_type');
        $terms = $documents->get('terms', []);
        $privacy = $documents->get('privacy', []);

        app(ConsentService::class)->acceptCurrentDocuments(
            $user,
            Request::create('/test', 'POST', [
                'terms' => true,
                'privacy_notice' => true,
                'terms_version' => $terms['version'] ?? '',
                'terms_hash' => $terms['content_hash'] ?? '',
                'privacy_version' => $privacy['version'] ?? '',
                'privacy_hash' => $privacy['content_hash'] ?? '',
            ], server: ['REMOTE_ADDR' => '127.0.0.1']),
            'api',
        );

        return $user->refresh();
    }

    protected function cancelData(array $f, ?int $userId = null): BetCancellationData
    {
        return new BetCancellationData($userId ?? $f['user']->id, (string) $f['bet']->id, BetCancellationReason::PlayerRequest);
    }
}
