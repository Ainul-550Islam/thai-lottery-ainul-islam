"""Round 7: re-applies the claim fixture, the bulk-bet wrapper and the bulk test file.

Idempotent. Each step checks for its own marker and skips when already present.
Run after a sandbox reset, from anywhere:  python3 /home/user/qa-fixes/apply_round7.py
"""
import os
import shutil

ROOT = '/home/user/thai-lottery-ainul-islam/'
TEMPLATES = '/home/user/qa-fixes/templates/'


def step_bulk_test():
    dst = ROOT + 'tests/Feature/Betting/BulkBetPlacementTest.php'
    if os.path.exists(dst):
        print('bulk test: already present')
        return
    shutil.copyfile(TEMPLATES + 'BulkBetPlacementTest.php', dst)
    print('bulk test: restored from template')


def step_bulk_wrapper():
    p = ROOT + 'app/Services/Betting/BulkBetService.php'
    s = open(p).read()
    if 'function placeBulkBet(' in s:
        print('bulk wrapper: already present')
        return
    anchor = '    /**\n     * Purchase a whole slip, per item isolation, report everything.'
    assert s.count(anchor) == 1, 'bulk wrapper anchor not found'
    new = '''    /**
     * Place a whole slip and return the typed placement result.
     *
     * A thin wrapper over purchase(): every item still runs through the same
     * purchase pipeline, so legality, wallet debits and per-item idempotency
     * are unchanged. totalStake comes from the report's total_charged, which
     * counts only new charges, so an identical replay reports 0.00.
     *
     * @param  list<\\App\\DTOs\\Betting\\BulkBetSelectionData>  $selections
     */
    public function placeBulkBet(
        \\App\\Models\\User $user,
        \\App\\Models\\Draw $draw,
        array $selections,
        string $clientKey,
    ): \\App\\DTOs\\Betting\\BulkBetPlacementResult {
        $summary = $this->purchase((int) $user->getKey(), (int) $draw->getKey(), $selections, $clientKey);

        $betIds = [];
        foreach ($summary['items'] as $item) {
            if (is_int($item['bet_id'] ?? null)) {
                $betIds[] = $item['bet_id'];
            }
        }

        $bets = $betIds === []
            ? collect()
            : \\App\\Models\\Bet::query()->whereIn('id', $betIds)->orderBy('id')->get();

        return new \\App\\DTOs\\Betting\\BulkBetPlacementResult(
            bets: $bets,
            totalStake: (string) $summary['total_charged'],
            summary: $summary,
        );
    }

'''
    open(p, 'w').write(s.replace(anchor, new + anchor, 1))
    print('bulk wrapper: applied')


CLAIM_METHOD = '''    /**
     * Test GLO Prize Claim Lifecycle and Approval.
     *
     * Every precondition is produced by the real service that owns it:
     *  - the official result goes through the four-eyes pipeline (ingest by one
     *    operator, confirm by a different one), which is the only path that
     *    publishes a DrawResult and moves the draw to ResultPublished;
     *  - the claim is created by GloPrizeClaimService::submit(), which resolves
     *    the ticket, draw, winner, age, stamp duty and window itself;
     *  - approve() then re-runs every payment gate. No gate is bypassed.
     */
    public function test_glo_prize_claim_lifecycle_and_approval(): void
    {
        $claimant = User::factory()->create();
        $claimant->forceFill(['date_of_birth' => now()->subYears(35)->toDateString()])->save();

        // KYC-verified claimant: a verified national-ID document (same shape the
        // identity service reads when deriving kycStatus()).
        KycDocument::create([
            'user_id' => $claimant->id,
            'document_type' => 'national_id',
            'document_number' => random_int(1000000000000, 9999999999999),
            'file_path' => 'kyc/'.$claimant->id.'.png',
            'original_filename' => 'id.png',
            'mime_type' => 'image/png',
            'file_size' => 2048,
            'status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $claimant->id,
            'metadata' => [],
        ]);

        $draw = Draw::factory()->create([
            'draw_number' => 'GLO-CLAIM-'.strtoupper(\\Illuminate\\Support\\Str::random(8)),
            // ResultPending is the state a GLO result is ingested and published in.
            'status' => DrawStatus::Drawing,
            'scheduled_at' => now()->subDays(20),
        ]);

        $ticketNumber = '123456';
        $ticket = GloTicket::create([
            'draw_id' => $draw->id,
            'product' => 'l6',
            'ticket_number' => $ticketNumber,
            'owner_user_id' => $claimant->id,
            'ticket_reference' => GloTicket::buildReference((int) $draw->id, 'l6', $ticketNumber),
            'metadata' => [],
        ]);

        // Four-eyes publication of the official result. The first prize matches
        // the ticket, so the ticket is a first-prize winner.
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $official = ['first_prize' => $ticketNumber, 'bottom_two' => '58'];

        app(DrawResultIngestionService::class)->ingest((int) $draw->id, $official, 'operator', (int) $maker->id);
        app(DrawResultConfirmationService::class)->confirm(
            (int) $draw->id,
            (int) $checker->id,
            $official,
            'Checked against the official announcement',
        );

        $this->assertSame(DrawStatus::ResultPublished, $draw->fresh()->status);
        $this->assertSame(1, DrawResult::query()->where('draw_id', $draw->id)->count());

        // Submit through the real service. It returns a Pending claim with
        // ticket_id, draw_id, prize amounts and stamp duty all derived.
        $claims = app(GloPrizeClaimService::class);
        $claim = $claims->submit([
            'ticket_id' => $ticket->id,
            'prize_category' => 'first',
            'claim_channel' => GloClaimChannel::Branch->value,
            'original_ticket_evidenced' => true,
            'identity_document_evidenced' => true,
        ], $claimant);

        $this->assertSame(GloClaimStatus::Pending, $claim->status);
        $this->assertSame($ticket->id, (int) $claim->ticket_id);
        $this->assertSame($draw->id, (int) $claim->draw_id);
        $this->assertSame('6000000.00', $claim->gross_prize);
        $this->assertSame('30000.00', $claim->stamp_duty);
        $this->assertSame('5970000.00', $claim->net_prize);

        $claim = $claims->reviewEligible($claim, $checker);
        $this->assertSame(GloClaimStatus::Eligible, $claim->status);

        // approve() re-checks every gate under lock and must succeed for a
        // legitimately owned, verified, published first-prize claim.
        $approver = User::factory()->create();
        $approved = $claims->approve($claim, $approver);

        $this->assertSame(GloClaimStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
    }

'''


def step_claim_fixture():
    p = ROOT + 'tests/Feature/RealMoneyBusinessReadinessTest.php'
    s = open(p).read()
    if 'GLO-CLAIM-' in s:
        print('claim fixture: already present')
        return
    start_marker = '    /**\n     * Test GLO Prize Claim Lifecycle and Approval.'
    end_marker = '    /**\n     * Test Multi-Currency Wallet Reservation'
    a = s.index(start_marker)
    b = s.index(end_marker, a)
    s = s[:a] + CLAIM_METHOD + s[b:]
    for old, new in [
        ('use App\\Models\\GloPrizeClaim;\n',
         'use App\\Models\\GloPrizeClaim;\nuse App\\Models\\GloTicket;\nuse App\\Models\\DrawResult;\nuse App\\Models\\KycDocument;\n'),
        ('use App\\Services\\Betting\\BulkBetService;\n',
         'use App\\Services\\Betting\\BulkBetService;\nuse App\\Services\\Draw\\DrawResultConfirmationService;\nuse App\\Services\\Draw\\DrawResultIngestionService;\n'),
    ]:
        if new not in s:
            assert s.count(old) == 1, 'import anchor missing: ' + old
            s = s.replace(old, new, 1)
    open(p, 'w').write(s)
    print('claim fixture: applied')


if __name__ == '__main__':
    step_bulk_test()
    step_bulk_wrapper()
    step_claim_fixture()
