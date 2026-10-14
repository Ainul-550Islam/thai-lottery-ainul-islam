"""Round 6: full fixture for the GLO prize-claim approval test.

approve() re-runs assertPaymentConditions(): ticket exists, draw exists, a recorded result
exists, the ticket is a winner under the official checker, settlement is final, no freeze or
payment hold is active, the claimant is 20+ with verified identity, the claim is inside the
window, and the stamp duty and net match the official rule. The fixture satisfies each gate
with real records. No gate is relaxed.
Idempotent: skipped once the new fixture is present."""
root = '/home/user/thai-lottery-ainul-islam/'
P = root + 'tests/Feature/RealMoneyBusinessReadinessTest.php'

s = open(P).read()
if 'GLO-CLAIM-RM-001' in s:
    print('ROUND6 ALREADY APPLIED')
    raise SystemExit(0)

start_marker = '    public function test_glo_prize_claim_lifecycle_and_approval(): void\n'
end_marker = '    /**\n     * Test Multi-Currency Wallet Reservation and Consumption.'
a = s.index(start_marker)
b = s.index(end_marker, a)

new_method = '''    public function test_glo_prize_claim_lifecycle_and_approval(): void
    {
        // Claimant: verified identity (KYC is derived from documents) and aged 30.
        $user = User::factory()->create([
            'date_of_birth' => Carbon::now()->subYears(30)->format('Y-m-d'),
        ]);
        KycDocument::query()->create([
            'user_id' => $user->id,
            'document_type' => KycDocumentType::NATIONAL_ID,
            'file_path' => 'kyc_private/'.$user->id.'/verified.enc',
            'mime_type' => 'application/pdf',
            'file_size' => 102400,
            'status' => KycStatus::VERIFIED,
            'verified_at' => Carbon::now(),
        ]);

        // A completed L6 draw with an official first prize of 123456.
        $draw = Draw::query()->create([
            'draw_number' => 'GLO-CLAIM-RM-001',
            'scheduled_at' => Carbon::now()->subDay(),
            'status' => DrawStatus::Completed,
            'currency' => Currency::THB,
        ]);
        DrawResult::query()->create([
            'draw_id' => $draw->id,
            'winning_number' => '123456',
            'first_prize' => '123456',
            'published_at' => Carbon::now()->subHours(2),
        ]);

        // The claimant's ticket carries the winning number.
        $ticket = GloTicket::query()->create([
            'draw_id' => $draw->id,
            'owner_user_id' => $user->id,
            'product' => 'l6',
            'ticket_number' => '123456',
            'set_series' => 1,
            'price' => '80.00',
            'currency' => Currency::THB,
            'status' => 'active',
            'purchased_at' => Carbon::now()->subDays(2),
        ]);

        $claimService = app(GloPrizeClaimService::class);

        $claim = GloPrizeClaim::query()->create([
            'claim_reference' => 'CLM-RM-LIFECYCLE-001',
            'ticket_id' => $ticket->id,
            'draw_id' => $draw->id,
            'product' => 'l6',
            'prize_category' => 'first',
            'ticket_number' => '123456',
            'gross_prize' => '6000000.00',
            'stamp_duty' => '30000.00',
            'net_prize' => '5970000.00',
            'claimant_user_id' => $user->id,
            'identity_reference' => 'kyc:'.$user->id,
            'age_verification_result' => 'verified',
            'verified_age_years' => 30,
            'original_ticket_evidenced' => true,
            'identity_document_evidenced' => true,
            'claim_channel' => GloClaimChannel::Branch->value,
            'status' => GloClaimStatus::Pending->value,
            'payment_status' => 'pending',
            'fingerprint' => hash('sha256', 'clm-rm-lifecycle-001'),
            'submitted_at' => Carbon::now(),
        ]);

        $this->assertSame(GloClaimStatus::Pending, $claim->fresh()->status);

        // Approve claim. approve() is the real entry point and accepts a
        // Pending or Eligible claim after re-checking every payment condition.
        $approver = User::factory()->create();
        $approved = $claimService->approve($claim, $approver);
        $this->assertSame(GloClaimStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
    }

'''
s = s[:a] + new_method + s[b:]

def add_use(s, line):
    if line in s:
        return s
    i = s.index('\nuse App\\')
    return s[:i + 1] + line + '\n' + s[i + 1:]

for line in ['use App\\Enums\\KycDocumentType;',
             'use App\\Enums\\KycStatus;',
             'use App\\Models\\DrawResult;',
             'use App\\Models\\GloTicket;',
             'use App\\Models\\KycDocument;']:
    s = add_use(s, line)

open(P, 'w').write(s)
print('ROUND6 APPLIED')
