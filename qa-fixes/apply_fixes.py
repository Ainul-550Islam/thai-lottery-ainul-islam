"""Re-applies the P0P1P2 / FinalWholeSystem fixture fixes. Idempotent: skips any
file that already contains the marker for its edit."""
import sys
root = '/home/user/thai-lottery-ainul-islam/'

def load(p):
    return open(root + p).read()

def save(p, s):
    open(root + p, 'w').write(s)

def rep(s, old, new, count=1):
    n = s.count(old)
    if n != count:
        sys.exit('MISMATCH (%d != %d): %r' % (n, count, old[:100]))
    return s.replace(old, new)

# ---- A. GloTicket price accessor
p = 'app/Models/GloTicket.php'
s = load(p)
if 'function getPriceAttribute' not in s:
    s = rep(s, "    public function setCurrencyAttribute(mixed $value): void\n",
      "    public function getPriceAttribute(): mixed\n    {\n        return ((array) $this->metadata)['price'] ?? null;\n    }\n\n"
      "    public function setCurrencyAttribute(mixed $value): void\n")
    save(p, s)

# ---- B. P0P1P2 suite
p = 'tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php'
s = load(p)
if 'function verifiedUser' not in s:
    helpers = (
"    private function addVerifiedKyc(User $user): void\n"
"    {\n"
"        KycDocument::query()->create([\n"
"            'user_id' => $user->id,\n"
"            'document_type' => KycDocumentType::NATIONAL_ID,\n"
"            'file_path' => 'kyc_private/'.$user->id.'/verified.enc',\n"
"            'mime_type' => 'application/pdf',\n"
"            'file_size' => 102400,\n"
"            'status' => KycStatus::VERIFIED,\n"
"            'verified_at' => Carbon::now(),\n"
"        ]);\n"
"    }\n\n"
"    private function verifiedUser(array $attributes = []): User\n"
"    {\n"
"        $user = User::factory()->create($attributes);\n"
"        $this->addVerifiedKyc($user);\n\n"
"        return $user;\n"
"    }\n\n")
    s = rep(s, "    public function test_p0_01_", helpers + "    public function test_p0_01_")
    s = rep(s, "        $player = User::factory()->create([\n            'email' => 'glo_player@example.com',\n            'kyc_status' => KycStatus::VERIFIED,\n",
               "        $player = $this->verifiedUser([\n            'email' => 'glo_player@example.com',\n")
    s = rep(s, "        $player = User::factory()->create([\n            'kyc_status' => KycStatus::VERIFIED,\n",
               "        $player = $this->verifiedUser([\n")
    s = rep(s, "        $user = User::factory()->create(['kyc_status' => KycStatus::UNVERIFIED]);\n",
               "        $user = User::factory()->create();\n")
    s = rep(s, "        $user->update(['kyc_status' => KycStatus::VERIFIED]);\n\n        $this->assertSame(KycStatus::VERIFIED, $user->fresh()->kyc_status);\n",
               "\n        $this->assertSame(KycStatus::VERIFIED, $user->fresh()->kycStatus());\n")
    s = rep(s, "        $player = User::factory()->create(['kyc_status' => KycStatus::VERIFIED]);\n",
               "        $player = $this->verifiedUser();\n")
    s = rep(s, "        $draw = Draw::query()->create([\n            'draw_number' => 'DRAW-REC-001',",
               "        // Commission is credited to the agent's THB wallet, so it must exist first.\n"
               "        app(WalletService::class)->getOrCreateWallet($agentUser, Currency::THB);\n\n"
               "        $draw = Draw::query()->create([\n            'draw_number' => 'DRAW-REC-001',")
    s = rep(s, "        $this->assertSame('paid', (string) $comm->status);",
               "        $this->assertSame(CommissionStatus::Paid, $comm->status);")
    s = rep(s, "use App\\Enums\\AgentStatus;\n", "use App\\Enums\\AgentStatus;\nuse App\\Enums\\CommissionStatus;\n")
    s = rep(s, "    public function test_p0_03_payment_hub_deposit_and_withdrawal_execution(): void\n    {\n        $user = User::factory()->create();\n        $operator = User::factory()->create();\n",
    "    public function test_p0_03_payment_hub_deposit_and_withdrawal_execution(): void\n    {\n        $user = User::factory()->create();\n        $operator = User::factory()->create();\n"
    "        // A deposit needs the player's THB wallet; the hub refuses without one.\n"
    "        app(WalletService::class)->getOrCreateWallet($user, Currency::THB);\n"
    "        // bKash is disabled by default and needs credentials. This test enables it\n"
    "        // with placeholder sandbox values and fakes the bKash HTTP API, so the\n"
    "        // deposit path runs with no network call and no real credentials.\n"
    "        config([\n"
    "            'payment.gateways.bkash.enabled' => true,\n"
    "            'payment.gateways.bkash.app_key' => 'test-app-key',\n"
    "            'payment.gateways.bkash.app_secret' => 'test-app-secret',\n"
    "            'payment.gateways.bkash.username' => 'test-user',\n"
    "            'payment.gateways.bkash.password' => 'test-pass',\n"
    "            'payment.gateways.bkash.base_url' => 'https://sandbox.bkash.test',\n"
    "        ]);\n"
    "        Http::fake([\n"
    "            '*' => Http::response([\n"
    "                'id_token' => 'test-token',\n"
    "                'token_type' => 'Bearer',\n"
    "                'expires_in' => 3600,\n"
    "                'paymentID' => 'PAY-TEST-0001',\n"
    "                'bkashURL' => 'https://sandbox.bkash.test/pay/PAY-TEST-0001',\n"
    "                'statusCode' => '0000',\n"
    "                'statusMessage' => 'Successful',\n"
    "            ], 200),\n"
    "        ]);\n")
    lines = s.split('\n')
    idx = [i for i, l in enumerate(lines) if l.startswith('use Illuminate\\Support\\Facades\\')]
    lines.insert(idx[-1] + 1, 'use Illuminate\\Support\\Facades\\Http;')
    s = '\n'.join(lines)
    save(p, s)

# ---- C. FinalWholeSystemNoSkipTest
p = 'tests/Feature/FinalWholeSystemNoSkipTest.php'
s = load(p)
if 'WithdrawalApprovalService' not in s:
    start = s.index("    public function test_06_")
    end = s.index("    public function test_07_")
    new06 = '''    public function test_06_withdrawal_request_and_disbursement_lifecycle(): void
    {
        $user = User::factory()->create();
        KycDocument::query()->create([
            'user_id' => $user->id,
            'document_type' => KycDocumentType::NATIONAL_ID,
            'file_path' => 'kyc_private/'.$user->id.'/verified.enc',
            'mime_type' => 'application/pdf',
            'file_size' => 102400,
            'status' => KycStatus::VERIFIED,
            'verified_at' => Carbon::now(),
        ]);

        // bank_transfer is disabled by default and fails closed without settlement
        // details. This test enables it with placeholder values (test-only config,
        // no real transfer is made), so the disbursement path can run.
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'TEST BANK',
                'account_number' => '0000000000',
                'account_name' => 'TEST OPERATOR',
                'instructions' => 'Test fixture only',
            ],
        ]);

        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $walletService->credit($wallet, '3000.00', 'initial', 'phase-test-'.bin2hex(random_bytes(8)), 'Initial balance');

        // Withdrawals are created through the real request path.
        /** @var WithdrawalService $withdrawalService */
        $withdrawalService = $this->app->make(WithdrawalService::class);
        $withdrawal = $withdrawalService->request(
            $wallet,
            Money::of('1000.00', Currency::THB),
            PaymentMethod::BankTransfer,
            'IDEM-WD-TEST-001',
            ['destination_details' => ['bank_name' => 'SCB', 'account_number' => '1234567890']],
        );

        // Request only checks sufficiency; approval is what reserves the funds.
        /** @var WithdrawalApprovalService $approvalService */
        $approvalService = $this->app->make(WithdrawalApprovalService::class);
        $approvalService->approve($withdrawal, null, 'FinalWholeSystem test approval');

        $wallet->refresh();
        $this->assertSame('3000.00', (string) $wallet->balance);
        $this->assertSame('1000.00', (string) $wallet->locked_balance);
        $this->assertSame('2000.00', (string) $wallet->getAvailableBalance());

        /** @var WithdrawalDisbursementService $disbService */
        $disbService = $this->app->make(WithdrawalDisbursementService::class);
        $disbService->disburse($withdrawal);

        // Disbursement moves the withdrawal to Processing; completion consumes the
        // reserved funds and debits the wallet.
        /** @var WithdrawalCompletionService $completionService */
        $completionService = $this->app->make(WithdrawalCompletionService::class);
        $completionService->complete($withdrawal->fresh());

        $withdrawal->refresh();
        $wallet->refresh();

        $this->assertSame(WithdrawalStatus::Completed, $withdrawal->status);
        $this->assertSame('2000.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
    }

'''
    s = s[:start] + new06 + s[end:]
    s = rep(s, "use App\\Enums\\PaymentDirection;\n", "use App\\Enums\\PaymentDirection;\nuse App\\Enums\\PaymentMethod;\n")
    s = rep(s, "use App\\Services\\Finance\\FinancialReconciliationService;\n",
      "use App\\Services\\Finance\\FinancialReconciliationService;\nuse App\\Services\\Finance\\Money;\nuse App\\Services\\Finance\\WithdrawalApprovalService;\nuse App\\Services\\Finance\\WithdrawalCompletionService;\nuse App\\Services\\Finance\\WithdrawalService;\n")
    s = rep(s, "use App\\Enums\\AgentStatus;\n", "use App\\Enums\\AgentStatus;\nuse App\\Enums\\CommissionStatus;\n")
    s = rep(s, "        $draw = Draw::query()->create([\n            'draw_number' => 'GLO-20260930-03',",
      "        // Commission is credited to the agent's THB wallet, so it must exist first.\n"
      "        app(WalletService::class)->getOrCreateWallet($agentUser, Currency::THB);\n\n"
      "        $draw = Draw::query()->create([\n            'draw_number' => 'GLO-20260930-03',")
    s = rep(s, "        $this->assertSame('paid', (string) $comm->status);",
      "        $this->assertSame(CommissionStatus::Paid, $comm->status);")
    save(p, s)
print('ALL APPLIED')
