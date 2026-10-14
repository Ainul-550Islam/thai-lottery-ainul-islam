"""Round 7b: test-side fixes for FinalWholeSystemNoSkipTest and P0P1P2ComprehensiveEnterpriseSuiteTest.

Every change maps a stale test call onto the API the code actually has. No service
code changes, no gate changes. Each step checks for its own marker and skips when
already applied. Each replacement asserts that its anchor exists exactly once.

    python3 /home/user/qa-fixes/apply_round7b.py

Not covered here: P0P1P2 test_p0_03 (payment hub + withdrawal). It still uses the old
string completeDeposit() call and a direct Withdrawal::create. It needs the full rewrite
described in QA_REPORT Update 17, which is a separate step.
"""
import re

ROOT = '/home/user/thai-lottery-ainul-islam/'
P0 = ROOT + 'tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php'
FW = ROOT + 'tests/Feature/FinalWholeSystemNoSkipTest.php'

KYC_HELPER = '''
    /**
     * Derive KYC from a verified national-ID document. KYC is not a stored
     * attribute: User::kycStatus() reads the documents.
     */
    private function verifiedKyc(User $user): void
    {
        KycDocument::query()->create([
            'user_id' => $user->id,
            'document_type' => KycDocumentType::NATIONAL_ID,
            'file_path' => 'kyc_private/'.$user->id.'/verified.enc',
            'mime_type' => 'image/png',
            'file_size' => 2048,
            'status' => KycStatus::VERIFIED,
            'submitted_at' => Carbon::now(),
            'verified_at' => Carbon::now(),
        ]);
    }
'''


def replace_once(s, old, new, label):
    n = s.count(old)
    assert n == 1, f'{label}: anchor found {n} times'
    return s.replace(old, new, 1)


def remove_method(s, method_start, next_start, label):
    """Remove a method and its docblock: from the docblock above method_start to next_start."""
    a = s.index(method_start)
    doc = s.rfind('    /**', 0, a)
    cut_from = doc if doc != -1 and s[doc:a].count('*/') == 1 and s[doc:a].rstrip().endswith('*/') else a
    b = s.index(next_start, a)
    assert b > a, label
    return s[:cut_from] + s[b:]


def apply_p0():
    s = open(P0).read()
    if 'private function verifiedKyc' in s:
        print('P0P1P2: already applied')
        return

    # Real KYC instead of a mass-assigned attribute the model does not accept.
    s = replace_once(s,
        "        $player = User::factory()->create([\n"
        "            'email' => 'glo_player@example.com',\n"
        "            'kyc_status' => KycStatus::VERIFIED,\n"
        "            'date_of_birth' => Carbon::now()->subYears(25)->format('Y-m-d'),\n"
        "        ]);\n",
        "        $player = User::factory()->create([\n"
        "            'email' => 'glo_player@example.com',\n"
        "            'date_of_birth' => Carbon::now()->subYears(25)->format('Y-m-d'),\n"
        "        ]);\n"
        "        $this->verifiedKyc($player);\n",
        'p0_01 player')

    # p0_01 price: the ticket keeps price in metadata (legacy mutator). Read what is persisted.
    s = replace_once(s,
        "        $this->assertSame('80.00', (string) $ticket->price);\n",
        "        $this->assertSame('80.00', (string) ($ticket->metadata['price'] ?? ''));\n",
        'p0_01 price')

    # p0_02 player
    s = replace_once(s,
        "        $player = User::factory()->create([\n"
        "            'kyc_status' => KycStatus::VERIFIED,\n"
        "            'date_of_birth' => '1995-05-15',\n"
        "        ]);\n",
        "        $player = User::factory()->create([\n"
        "            'date_of_birth' => '1995-05-15',\n"
        "        ]);\n"
        "        $this->verifiedKyc($player);\n",
        'p0_02 player')

    # p0_06 moved to BulkBetPlacementTest.php
    s = remove_method(s,
        'public function test_p0_06_financial_idempotency_protection',
        'public function test_p0_07_cross_currency_wallet_isolation',
        'p0_06 remove')

    # p0_08: real self-exclusion flow (request, then activate). The service has no exclude() or isSelfExcluded().
    s = replace_once(s,
        "        $this->assertFalse($exclusion->isSelfExcluded($user->id));\n\n"
        "        $exclusion->exclude(new SelfExclusionData(\n"
        "            userId: $user->id,\n"
        "            durationDays: 30,\n"
        "            reason: 'Taking break'\n"
        "        ));\n\n"
        "        $this->assertTrue($exclusion->isSelfExcluded($user->id));\n",
        "        $this->assertFalse($exclusion->hasActiveExclusion($user->id));\n\n"
        "        $requested = $exclusion->request(new SelfExclusionData(\n"
        "            userId: $user->id,\n"
        "            durationDays: 30,\n"
        "            reasonCode: 'self_requested',\n"
        "        ));\n"
        "        $exclusion->activate($requested);\n\n"
        "        $this->assertTrue($exclusion->hasActiveExclusion($user->id));\n",
        'p0_08 exclusion')

    # p1_14: KYC status is derived from the document, not updated as an attribute.
    s = replace_once(s,
        "        $user = User::factory()->create(['kyc_status' => KycStatus::UNVERIFIED]);\n\n"
        "        $doc = KycDocument::query()->create([",
        "        $user = User::factory()->create();\n\n"
        "        $doc = KycDocument::query()->create([",
        'p1_14 user')
    s = replace_once(s,
        "        $doc->update(['status' => KycStatus::VERIFIED, 'verified_at' => Carbon::now()]);\n"
        "        $user->update(['kyc_status' => KycStatus::VERIFIED]);\n\n"
        "        $this->assertSame(KycStatus::VERIFIED, $user->fresh()->kyc_status);\n",
        "        $doc->update(['status' => KycStatus::VERIFIED, 'verified_at' => Carbon::now()]);\n\n"
        "        $this->assertTrue($user->fresh()->kycStatus()->isVerified());\n",
        'p1_14 verify')

    # p1_20 player
    s = replace_once(s,
        "        $player = User::factory()->create(['kyc_status' => KycStatus::VERIFIED]);\n",
        "        $player = User::factory()->create();\n        $this->verifiedKyc($player);\n",
        'p1_20 player')

    # p2_22 agent needs a THB wallet to receive commission.
    s = replace_once(s,
        "        $agentUser = User::factory()->create();\n        $agent = Agent::query()->create([\n"
        "            'user_id' => $agentUser->id,\n            'agent_code' => 'AGT-REC-001',\n",
        "        $agentUser = User::factory()->create();\n"
        "        app(WalletService::class)->getOrCreateWallet($agentUser, Currency::THB);\n"
        "        $agent = Agent::query()->create([\n"
        "            'user_id' => $agentUser->id,\n            'agent_code' => 'AGT-REC-001',\n",
        'p2_22 agent wallet')

    # Withdrawal status enum case names.
    s = s.replace('WithdrawalStatus::PENDING', 'WithdrawalStatus::Pending')
    s = s.replace('WithdrawalStatus::COMPLETED', 'WithdrawalStatus::Completed')

    # Helper goes before the final closing brace of the class.
    idx = s.rstrip().rfind('}')
    s = s[:idx].rstrip() + '\n' + KYC_HELPER + '}\n'

    open(P0, 'w').write(s)
    print('P0P1P2: applied')


CALC_TEST = '''    public function test_05_proportional_prize_calculation_and_settlement(): void
    {
        /** @var GloL6ProportionalPrizeCalculator $calc */
        $calc = $this->app->make(GloL6ProportionalPrizeCalculator::class);

        // The 60% allocation is configured, not hard-coded.
        $this->assertSame('60.00', (string) config('glo.prize_pool_allocation_percent'));

        // Full sell-out: the proportional first-prize pot equals the full pot (6,000,000).
        $full = $calc->calculateForSales('80000000.00', 1000000);
        $this->assertSame('6000000.00', $full['first']['full_pot']);
        $this->assertSame($full['first']['full_pot'], $full['first']['proportional_pot']);

        // Half sell-out: the proportional first-prize pot is half of the full pot.
        $half = $calc->calculateForSales('40000000.00', 500000);
        $this->assertSame('3000000.00', $half['first']['proportional_pot']);

        // Official stamp-duty rule for a 6,000,000 THB first prize.
        $this->assertSame('30000.00', app(\\App\\Services\\Lottery\\GloStampDutyCalculator::class)->dutyFor('6000000.00'));
    }

'''


def apply_fw():
    s = open(FW).read()
    if 'function verifiedKyc' in s or 'calculateForSales' in s:
        print('FinalWhole: already applied')
        return

    # test 04 moved to BulkBetPlacementTest.php
    s = remove_method(s,
        'public function test_04_bulk_bet_placement_with_idempotency_and_satang_math',
        'public function test_05_proportional_prize_calculation_and_settlement',
        'fw 04 remove')

    # test 05: real calculator API
    a = s.index('    public function test_05_proportional_prize_calculation_and_settlement')
    b = s.index('    public function test_06_withdrawal_request_and_disbursement_lifecycle')
    s = s[:a] + CALC_TEST + s[b:]

    # test 06: reserve() takes the user id (or a WalletReservationData), not the wallet
    s = replace_once(s,
        "$resService->reserve($wallet, '1000.00', 'withdrawal', 'WD-TEST-001');",
        "$resService->reserve($user->id, '1000.00', Currency::THB->value, 'withdrawal', 300);",
        'fw 06 reserve')
    s = s.replace('WithdrawalStatus::PENDING', 'WithdrawalStatus::Pending')
    s = s.replace('WithdrawalStatus::COMPLETED', 'WithdrawalStatus::Completed')

    # test 07: the agent needs a THB wallet to receive commission
    s = replace_once(s,
        "        $agentUser = User::factory()->create();\n        $agent = Agent::query()->create([\n            'user_id' => $agentUser->id,\n            'agent_code' => 'AGT-' . bin2hex(random_bytes(3)),",
        "        $agentUser = User::factory()->create();\n"
        "        app(WalletService::class)->getOrCreateWallet($agentUser, Currency::THB);\n"
        "        $agent = Agent::query()->create([\n            'user_id' => $agentUser->id,\n            'agent_code' => 'AGT-' . bin2hex(random_bytes(3)),",
        'fw 07 agent wallet')

    # test 08: real self-exclusion flow
    s = replace_once(s,
        "        $this->assertFalse($exclusionService->isSelfExcluded($user->id));\n\n"
        "        $exclusionService->exclude(new SelfExclusionData(\n"
        "            userId: $user->id,\n"
        "            durationDays: 30,\n"
        "            reason: 'Take a break'\n"
        "        ));\n\n"
        "        $this->assertTrue($exclusionService->isSelfExcluded($user->id));\n",
        "        $this->assertFalse($exclusionService->hasActiveExclusion($user->id));\n\n"
        "        $requested = $exclusionService->request(new SelfExclusionData(\n"
        "            userId: $user->id,\n"
        "            durationDays: 30,\n"
        "            reasonCode: 'self_requested',\n"
        "        ));\n"
        "        $exclusionService->activate($requested);\n\n"
        "        $this->assertTrue($exclusionService->hasActiveExclusion($user->id));\n",
        'fw 08 exclusion')

    idx = s.rstrip().rfind('}')
    s = s[:idx].rstrip() + '\n' + KYC_HELPER + '}\n'
    open(FW, 'w').write(s)
    print('FinalWhole: applied')


def apply_followups():
    """Idempotent follow-ups found by running the suites. Each replacement is skipped once applied."""
    for path in (P0, FW):
        s = open(path).read()
        before = s
        # The allocation config lives under glo.l6.
        s = s.replace("config('glo.prize_pool_allocation_percent')", "config('glo.l6.prize_pool_allocation_percent')")
        # Commission status is an enum; compare its value.
        s = s.replace("(string) $comm->status)", "$comm->status->value)")
        s = s.replace("(string) $agent->status)", "$agent->status->value)")
        if s != before:
            open(path, 'w').write(s)
            print(path.rsplit('/', 1)[-1] + ': follow-ups applied')
        else:
            print(path.rsplit('/', 1)[-1] + ': follow-ups already applied')
    # requested_at is not mass-assignable on Withdrawal; the column default applies.
    s = open(FW).read()
    old = "            'requested_at' => Carbon::now(),\n        ]);\n\n        $wallet->refresh();"
    if "'requested_at' => Carbon::now()," in s and s.count("'requested_at' => Carbon::now(),") == 1:
        s = s.replace("            'requested_at' => Carbon::now(),\n", "", 1)
        open(FW, 'w').write(s)
        print('FinalWhole: requested_at removed from withdrawal fixture')

    # Withdrawal must be approved by a reviewer other than the requester before disbursement.
    s = open(FW).read()
    if 'WithdrawalApprovalService::class)->approve' not in s:
        old = "        $wallet->refresh();\n        $this->assertSame('3000.00', (string) $wallet->balance);\n"
        assert s.count(old) == 1, 'fw 06 pre-approval anchor'
        new = ("        $reviewer = User::factory()->create();\n"
               "        app(\\App\\Services\\Finance\\WithdrawalApprovalService::class)->approve($withdrawal, $reviewer->id, 'Bank details checked');\n"
               "        $withdrawal->refresh();\n\n" + old)
        s = s.replace(old, new, 1)
        open(FW, 'w').write(s)
        print('FinalWhole: withdrawal approval step added')


FW06 = r"""    public function test_06_withdrawal_request_and_disbursement_lifecycle(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $walletService->credit($wallet, '3000.00', 'initial', 'phase-test-'.bin2hex(random_bytes(8)), 'Initial balance');

        // Test-only lane: bank transfer is disabled by default and fails closed without
        // settlement details. Production defaults are unchanged; this override applies to this test only.
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'Test Settlement Bank',
                'account_number' => '0000000000',
                'account_name' => 'Test Operator',
            ],
        ]);

        // Real request path: request() creates the withdrawal, and approve() by a
        // reviewer other than the requester moves the funds into locked_balance once.
        $withdrawal = app(\App\Services\Finance\WithdrawalService::class)->request(
            wallet: $wallet->refresh(),
            amount: \App\Services\Finance\Money::of('1000.00', Currency::THB),
            method: \App\Enums\PaymentMethod::BankTransfer,
            options: ['payout_details' => ['bank_name' => 'SCB', 'account_number' => '1234567890', 'account_name' => 'Player']],
        );

        $reviewer = User::factory()->create();
        app(\App\Services\Finance\WithdrawalApprovalService::class)->approve($withdrawal, $reviewer->id, 'Payout details checked');

        $wallet->refresh();
        $this->assertSame('3000.00', (string) $wallet->balance);
        $this->assertSame('1000.00', (string) $wallet->locked_balance);
        $this->assertSame('2000.00', (string) $wallet->getAvailableBalance());

        /** @var WithdrawalDisbursementService $disbService */
        $disbService = $this->app->make(WithdrawalDisbursementService::class);
        $disbService->disburse($withdrawal);

        // disburse() hands the payout to the provider: the withdrawal stays Processing
        // until the provider confirms it.
        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Processing->value, $withdrawal->status->value);

        // The provider confirms through the webhook path, which completes the payout and debits the wallet.
        $payload = new \App\DTOs\Payment\WebhookPayload(
            gateway: 'bank_transfer',
            eventId: 'evt_bt_wd_'.bin2hex(random_bytes(4)),
            eventType: \App\Enums\WebhookEventType::WithdrawalSuccess,
            providerReference: 'BT-DISB-'.bin2hex(random_bytes(4)),
            internalReference: $withdrawal->reference_number,
            amount: '1000.00',
            currency: Currency::THB,
            isSuccess: true,
        );

        $result = app(\App\Services\Payment\PaymentWebhookService::class)->processWebhookPayload($payload);
        $this->assertTrue($result->success);

        $withdrawal->refresh();
        $wallet->refresh();

        $this->assertSame(WithdrawalStatus::Completed->value, $withdrawal->status->value);
        $this->assertSame('2000.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
    }

"""


def apply_fw06():
    s = open(FW).read()
    if 'WithdrawalSuccess' in s:
        print('FinalWhole 06: already rewritten')
        return
    a = s.index('    public function test_06_withdrawal_request_and_disbursement_lifecycle')
    b = s.index('    public function test_07_agent_referral_and_settlement_journal')
    s = s[:a] + FW06 + s[b:]
    open(FW, 'w').write(s)
    print('FinalWhole 06: rewritten through WithdrawalService::request (test-only bank-transfer lane)')


P03 = r"""    public function test_p0_03_payment_hub_deposit_and_withdrawal_execution(): void
    {
        // Test-only lanes: Stripe (deposit) and bank transfer (withdrawal) are disabled by default
        // and fail closed without credentials. These overrides apply to this test only.
        // The Stripe checkout call is faked, so no network is used.
        config([
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.secret' => 'sk_test_mock_p03',
            'payment.gateways.stripe.webhook_secret' => 'whsec_mock_p03',
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'Test Settlement Bank',
                'account_number' => '0000000000',
                'account_name' => 'Test Operator',
            ],
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'api.stripe.com/*' => \Illuminate\Support\Facades\Http::response([
                'id' => 'cs_test_p03',
                'url' => 'https://checkout.stripe.com/pay/cs_test_p03',
            ], 200),
        ]);

        $user = User::factory()->create();

        // The hub resolves an existing wallet for the currency before it initiates a deposit.
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);

        /** @var ProductionPaymentExecutionHubService $paymentHub */
        $paymentHub = $this->app->make(ProductionPaymentExecutionHubService::class);

        // Inbound deposit: initiated through the hub, which returns a checkout redirect.
        $depositInit = $paymentHub->initiateDeposit($user, [
            'amount' => '2500.00',
            'channel' => 'stripe',
            'currency' => 'THB',
        ], 'IDEM-DEP-STRIPE-P03-0001');

        $this->assertNotEmpty($depositInit['redirect_url']);
        $this->assertSame('pending', $depositInit['status']);

        // The provider confirms the deposit through the webhook path, which credits the wallet.
        $depositWebhook = new \App\DTOs\Payment\WebhookPayload(
            gateway: 'stripe',
            eventId: 'evt_dep_p03_0001',
            eventType: \App\Enums\WebhookEventType::DepositSuccess,
            providerReference: 'pi_p03_0001',
            internalReference: (string) $depositInit['reference_id'],
            amount: '2500.00',
            currency: Currency::THB,
            isSuccess: true,
        );
        $depositResult = app(\App\Services\Payment\PaymentWebhookService::class)->processWebhookPayload($depositWebhook);
        $this->assertTrue($depositResult->success);

        $this->assertSame('2500.00', (string) $wallet->refresh()->balance);

        // Outbound withdrawal: request, approval by a different reviewer, disbursement, then provider confirmation.
        $withdrawal = app(\App\Services\Finance\WithdrawalService::class)->request(
            wallet: $wallet,
            amount: \App\Services\Finance\Money::of('1000.00', Currency::THB),
            method: \App\Enums\PaymentMethod::BankTransfer,
            options: ['payout_details' => ['bank_name' => 'SCB', 'account_number' => '1234567890', 'account_name' => 'Player']],
        );

        $reviewer = User::factory()->create();
        app(\App\Services\Finance\WithdrawalApprovalService::class)->approve($withdrawal, $reviewer->id, 'Payout details checked');

        app(\App\Services\Payment\WithdrawalDisbursementService::class)->disburse($withdrawal);
        $withdrawal->refresh();
        $this->assertSame(WithdrawalStatus::Processing->value, $withdrawal->status->value);

        $withdrawalWebhook = new \App\DTOs\Payment\WebhookPayload(
            gateway: 'bank_transfer',
            eventId: 'evt_wd_p03_0001',
            eventType: \App\Enums\WebhookEventType::WithdrawalSuccess,
            providerReference: 'BT-P03-0001',
            internalReference: $withdrawal->reference_number,
            amount: '1000.00',
            currency: Currency::THB,
            isSuccess: true,
        );
        $withdrawalResult = app(\App\Services\Payment\PaymentWebhookService::class)->processWebhookPayload($withdrawalWebhook);
        $this->assertTrue($withdrawalResult->success);

        $withdrawal->refresh();
        $wallet->refresh();

        $this->assertSame(WithdrawalStatus::Completed->value, $withdrawal->status->value);
        $this->assertSame('1500.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
    }

"""


def apply_p03():
    s = open(P0).read()
    if "'channel' => 'stripe'" in s:
        print('P0P1P2 p0_03: already rewritten')
        return
    a = s.index('    public function test_p0_03_payment_hub_deposit_and_withdrawal_execution')
    b = s.index('    public function test_p0_04_wallet_canonical_double_entry_and_single_source')
    s = s[:a] + P03 + s[b:]
    open(P0, 'w').write(s)
    print('P0P1P2 p0_03: rewritten through the hub, webhooks and WithdrawalService')


if __name__ == '__main__':
    apply_p0()
    apply_fw()
    apply_followups()
    apply_fw06()
    apply_p03()
