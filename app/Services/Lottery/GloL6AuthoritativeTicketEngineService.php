<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\GloClaimStatus;
use App\Enums\GloSourceState;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Exceptions\GloClaimException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\GloL6Sale;
use App\Models\GloPrizeClaim;
use App\Models\GloTicket;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Carbon\Carbon;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;

/**
 * GLO L6 Authoritative Single-Ticket Production Engine.
 *
 * SPECIFICATION & OFFICIAL GLO BUSINESS RULES
 * ===========================================
 * 1. Physical / Digital Series Allocation:
 *    - 1,000,000 tickets per official series ('000000' to '999999').
 *    - Fixed retail price: ฿80.00 THB per single ticket (strictly enforced).
 *    - Full sell-out prize allocation: ฿48,000,000.00 THB across 14,168 prizes.
 *
 * 2. Unsold-Ticket Proportional Prize Reduction:
 *    - When units sold (U_sold) < 1,000,000, every prize tier scales strictly as:
 *      Prize_actual = Base_prize * (U_sold / 1,000,000)
 *    - Exact 12-decimal string scaling via BCMath without floating-point approximation.
 *    - Unsold remainder is retained by the treasury and never re-distributed into fake winners.
 *
 * 3. 14,168 Official GLO Prize Categories (per 1,000,000 series):
 *    - 1st Prize (1 winner): ฿6,000,000.00 THB (6-digit exact match)
 *    - Adjacent 1st Prize (2 winners): ฿100,000.00 THB (+/- 1 from 1st prize)
 *    - 2nd Prize (5 winners): ฿200,000.00 THB
 *    - 3rd Prize (10 winners): ฿80,000.00 THB
 *    - 4th Prize (50 winners): ฿40,000.00 THB
 *    - 5th Prize (100 winners): ฿20,000.00 THB
 *    - First 3 Digits (2,000 winners): ฿4,000.00 THB (prefix 3-digit match)
 *    - Last 3 Digits (2,000 winners): ฿4,000.00 THB (suffix 3-digit match)
 *    - Last 2 Digits (10,000 winners): ฿2,000.00 THB (suffix 2-digit match)
 *    - TOTAL: 14,168 prizes | ฿48,000,000.00 THB
 *
 * 4. Claim Qualification & Legal Verification:
 *    - Age >= 20 years verified server-side from users.date_of_birth.
 *    - Claimant KYC status must be 'verified'.
 *    - Claim window: strictly within 2 years (730 days) from draw date.
 *    - Stamp duty deduction: ceil(gross / 200) * 1.00 THB (0.5% rate) for Government Lottery.
 *
 * 5. Double-Entry General Ledger Settlement:
 *    - Settled directly into player wallet via canonical App\Services\Finance\WalletService.
 *    - Posts debits to Prize Expense Account (5000) and credits Player Liability (2000).
 */
class GloL6AuthoritativeTicketEngineService
{
    public const FULL_SERIES_UNITS = 1000000;

    public const TICKET_PRICE_THB = '80.00';

    public const FULL_SERIES_PRIZE_POOL_THB = '48000000.00';

    public const MINIMUM_CLAIMANT_AGE = 20;

    public const CLAIM_WINDOW_YEARS = 2;

    public const PRIZE_LADDER = [
        'first_prize' => [
            'name' => 'First Prize (รางวัลที่ 1)',
            'count' => 1,
            'base_amount' => '6000000.00',
            'match_type' => 'exact_6',
        ],
        'adjacent_first_prize' => [
            'name' => 'Adjacent First Prize (รางวัลข้างเคียงรางวัลที่ 1)',
            'count' => 2,
            'base_amount' => '100000.00',
            'match_type' => 'adjacent_6',
        ],
        'second_prize' => [
            'name' => 'Second Prize (รางวัลที่ 2)',
            'count' => 5,
            'base_amount' => '200000.00',
            'match_type' => 'exact_6',
        ],
        'third_prize' => [
            'name' => 'Third Prize (รางวัลที่ 3)',
            'count' => 10,
            'base_amount' => '80000.00',
            'match_type' => 'exact_6',
        ],
        'fourth_prize' => [
            'name' => 'Fourth Prize (รางวัลที่ 4)',
            'count' => 50,
            'base_amount' => '40000.00',
            'match_type' => 'exact_6',
        ],
        'fifth_prize' => [
            'name' => 'Fifth Prize (รางวัลที่ 5)',
            'count' => 100,
            'base_amount' => '20000.00',
            'match_type' => 'exact_6',
        ],
        'front_three_digit' => [
            'name' => 'Front 3 Digits (เลขหน้า 3 ตัว)',
            'count' => 2000,
            'base_amount' => '4000.00',
            'match_type' => 'front_3',
        ],
        'back_three_digit' => [
            'name' => 'Back 3 Digits (เลขท้าย 3 ตัว)',
            'count' => 2000,
            'base_amount' => '4000.00',
            'match_type' => 'back_3',
        ],
        'back_two_digit' => [
            'name' => 'Back 2 Digits (เลขท้าย 2 ตัว)',
            'count' => 10000,
            'base_amount' => '2000.00',
            'match_type' => 'back_2',
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly ConfigRepository $config,
        private readonly WalletService $walletService,
        private readonly LedgerPostingService $ledgerService,
        private readonly GloStampDutyCalculator $stampDutyCalculator,
        private readonly GloTicketChecker $ticketChecker,
        private readonly GloTicketFreezeService $freezeService,
    ) {}

    /**
     * Allocate and seat an official GLO L6 series for a scheduled draw.
     *
     * @param  array{units_sold: int, series_number: int, source_ref?: string}  $params
     */
    public function seatL6DrawSeries(Draw $draw, array $params, ?User $actor = null): GloL6Sale
    {
        $drawId = (int) $draw->getKey();
        $unitsSold = (int) ($params['units_sold'] ?? self::FULL_SERIES_UNITS);
        $seriesNumber = (int) ($params['series_number'] ?? 1);

        if ($unitsSold < 0 || $unitsSold > self::FULL_SERIES_UNITS) {
            throw new InvalidArgumentException('Units sold must be between 0 and '.self::FULL_SERIES_UNITS);
        }

        $grossSales = bcmul((string) $unitsSold, self::TICKET_PRICE_THB, 2);
        $soldFraction = $this->calculateSoldFraction($unitsSold, self::FULL_SERIES_UNITS);
        $proportionalPrizePool = bcmul(self::FULL_SERIES_PRIZE_POOL_THB, $soldFraction, 2);

        return $this->db->connection()->transaction(function () use (
            $draw,
            $drawId,
            $unitsSold,
            $seriesNumber,
            $grossSales,
            $soldFraction,
            $proportionalPrizePool,
            $params,
            $actor
        ): GloL6Sale {
            $seatKey = sprintf('glo_l6_seat_%d_series_%d', $drawId, $seriesNumber);

            $existing = GloL6Sale::query()
                ->where('seat_key', $seatKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof GloL6Sale) {
                $existing->update([
                    'units_sold' => $unitsSold,
                    'gross_sales' => $grossSales,
                    'sold_fraction' => $soldFraction,
                    'proportional_prize_pool' => $proportionalPrizePool,
                ]);

                return $existing;
            }

            $seat = GloL6Sale::create([
                'seat_key' => $seatKey,
                'draw_id' => $drawId,
                'product' => 'l6',
                'series_number' => $seriesNumber,
                'units_full' => self::FULL_SERIES_UNITS,
                'units_sold' => $unitsSold,
                'ticket_price' => self::TICKET_PRICE_THB,
                'gross_sales' => $grossSales,
                'sold_fraction' => $soldFraction,
                'full_allocation_pool' => self::FULL_SERIES_PRIZE_POOL_THB,
                'proportional_prize_pool' => $proportionalPrizePool,
                'currency' => Currency::THB,
                'source_reference' => $params['source_ref'] ?? ('GLO-L6-'.$draw->draw_number),
                'provenance' => GloSourceState::OfficialSourceVerified->value,
            ]);

            if ($actor !== null) {
                AuditLog::create([
                    'user_id' => $actor->id,
                    'action' => AuditAction::Create,
                    'risk_level' => RiskLevel::High,
                    'auditable_type' => GloL6Sale::class,
                    'auditable_id' => $seat->id,
                    'description' => 'Seated official GLO L6 draw series allocation',
                    'metadata' => [
                        'draw_id' => $drawId,
                        'series' => $seriesNumber,
                        'units_sold' => $unitsSold,
                        'gross_sales' => $grossSales,
                        'proportional_prize_pool' => $proportionalPrizePool,
                    ],
                ]);
            }

            return $seat;
        });
    }

    /**
     * Purchase and bind digital GLO L6 ticket ownership to a verified player.
     *
     * @param  array{ticket_number: string, set_series: int}  $ticketData
     */
    public function purchaseL6Ticket(User $buyer, Draw $draw, array $ticketData, string $clientKey): GloTicket
    {
        if (! $draw->isOpen()) {
            throw new RuntimeException('Draw is not open for ticket sales.');
        }

        $ticketNumber = trim((string) ($ticketData['ticket_number'] ?? ''));
        if (! preg_match('/^[0-9]{6}$/', $ticketNumber)) {
            throw new InvalidArgumentException('GLO L6 ticket number must be exactly 6 numeric digits (000000-999999).');
        }

        $series = (int) ($ticketData['set_series'] ?? 1);
        $drawId = (int) $draw->getKey();

        return $this->db->connection()->transaction(function () use ($buyer, $draw, $drawId, $ticketNumber, $series, $clientKey): GloTicket {
            // Deduct ticket price from player wallet atomically
            $wallet = $this->walletService->getOrCreateWallet($buyer, Currency::THB);

            $idempotencyKey = sprintf('GLO-L6-BUY-%d-%s-%d-%s', $buyer->id, $ticketNumber, $drawId, $clientKey);

            $existingTicket = GloTicket::query()
                ->where('draw_id', $drawId)
                ->where('product', 'l6')
                ->where('ticket_number', $ticketNumber)
                ->where('set_series', $series)
                ->lockForUpdate()
                ->first();

            if ($existingTicket instanceof GloTicket) {
                if ($existingTicket->owner_user_id === $buyer->id) {
                    return $existingTicket; // Idempotent return
                }
                throw new RuntimeException("GLO L6 ticket number {$ticketNumber} series {$series} is already sold.");
            }

            // Debit 80.00 THB from wallet with double-entry ledger posting
            $this->walletService->debit(
                wallet: $wallet,
                amount: Money::of(self::TICKET_PRICE_THB, Currency::THB),
                type: FinancialTransactionType::BetDebit,
                idempotencyKey: $idempotencyKey,
                options: [
                    'reference_type' => 'glo_l6_ticket_purchase',
                    'reference_id' => $idempotencyKey,
                    'description' => sprintf('Official GLO L6 Ticket #%s (Draw #%s)', $ticketNumber, $draw->draw_number),
                    'ticket_number' => $ticketNumber,
                    'series' => $series,
                ],
            );

            // Generate deterministic digital verification seal
            $verificationHash = hash('sha256', sprintf('GLO|L6|%d|%d|%s|%d|%s', $drawId, $buyer->id, $ticketNumber, $series, $clientKey));

            return GloTicket::create([
                'draw_id' => $drawId,
                'owner_user_id' => $buyer->id,
                'product' => 'l6',
                'ticket_number' => $ticketNumber,
                'set_series' => $series,
                'price' => self::TICKET_PRICE_THB,
                'currency' => Currency::THB,
                'status' => 'active',
                'purchased_at' => Carbon::now(),
                'metadata' => [
                    'buyer_kyc' => $buyer->kycStatus()->value,
                    'client_key' => $clientKey,
                    'price_thb' => self::TICKET_PRICE_THB,
                    'digital_seal_hash' => $verificationHash,
                ],
            ]);
        });
    }

    /**
     * Compute exact proportional prize ladder for a specific draw and ticket category.
     *
     * @return array{gross_prize: string, stamp_duty: string, net_prize: string, proportional_scaling: string}
     */
    public function computeProportionalPrize(Draw $draw, string $category): array
    {
        $ladder = self::PRIZE_LADDER[$category] ?? null;
        if ($ladder === null) {
            throw new InvalidArgumentException("Unknown GLO prize category: {$category}");
        }

        $baseGross = $ladder['base_amount'];
        $drawId = (int) $draw->getKey();

        // Check if an official L6 seated sales record exists for this draw
        $sale = GloL6Sale::query()->where('draw_id', $drawId)->where('product', 'l6')->first();

        if ($sale instanceof GloL6Sale) {
            $fraction = $this->calculateSoldFraction((int) $sale->units_sold, (int) $sale->units_full);
            $actualGross = bcmul($baseGross, $fraction, 2);
            $scaling = $fraction;
        } else {
            $actualGross = $baseGross;
            $scaling = '1.000000000000';
        }

        $duty = $this->stampDutyCalculator->dutyFor($actualGross);
        $net = $this->stampDutyCalculator->netAfterDuty($actualGross);

        return [
            'gross_prize' => $actualGross,
            'stamp_duty' => $duty,
            'net_prize' => $net,
            'proportional_scaling' => $scaling,
        ];
    }

    /**
     * RETIRED - this method used to credit a verified claim straight to a wallet.
     *
     * WHY IT IS REFUSED RATHER THAN FIXED HERE
     * The old body accepted a stored row whose status said "approved", re-checked a
     * handful of local conditions and then called WalletService::credit(). That is a
     * separate money writer, and it is not the same contract as the reviewed claim
     * workflow: it never established winner/result finality, payment-hold ownership,
     * legal-conflict clearance or payment-operator permission. Its "already paid"
     * branch also handed back a row as a replay without proving that THIS writer had
     * reconciled the original financial event.
     *
     * A second writer that can move real money on a weaker proof is a gap in the
     * books however convenient it is, and widening its checks locally would only
     * produce a second, nearly-correct payout path. So the public signature is kept
     * for explicit refusal, with no configuration flag, fallback or silent delegation
     * to reactivate it. The canonical reviewed path is GloPrizeClaimService::pay().
     *
     * The refusal happens before this method reads or changes a claim, a wallet, a
     * financial transaction, a ledger entry or an audit row, so it holds for an
     * Approved, Pending, Eligible, Paid or unpersisted input alike.
     *
     * @throws FinancialException always, with code glo_l6_direct_wallet_settlement_disabled
     */
    public function settleVerifiedClaim(GloPrizeClaim $claim, User $authorizingOperator): GloPrizeClaim
    {
        throw FinancialException::withCode(
            'glo_l6_direct_wallet_settlement_disabled',
            'Direct wallet settlement from this legacy engine is disabled: a prize must be paid through the '
            .'reviewed GLO claim workflow, which proves winner/result finality, holds and payment authority.',
        );
    }

    /**
     * Compute sold fraction string with 12 decimals of precision.
     */
    public function calculateSoldFraction(int $unitsSold, int $unitsFull = self::FULL_SERIES_UNITS): string
    {
        if ($unitsFull <= 0) {
            throw new InvalidArgumentException('Units full must be strictly positive.');
        }
        if ($unitsSold < 0) {
            throw new InvalidArgumentException('Units sold cannot be negative.');
        }
        if ($unitsSold >= $unitsFull) {
            return '1.000000000000';
        }

        return bcdiv((string) $unitsSold, (string) $unitsFull, 12);
    }
}
