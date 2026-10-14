"""Round 3: real webhook pipeline for the THB deposit, supported market codes.
Idempotent: each edit is skipped when its target text is already gone."""
root = '/home/user/thai-lottery-ainul-islam/'


def load(p):
    return open(root + p).read()


def save(p, s):
    open(root + p, 'w').write(s)


def add_use(s, line):
    """Insert a top-level `use` line before the first `use App\\` line, if missing."""
    if line in s:
        return s
    i = s.index('\nuse App\\')
    return s[:i + 1] + line + '\n' + s[i + 1:]


# 1. Deposit confirmation through the persisted-webhook pipeline.
p = 'tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php'
s = load(p)
old = ("        $completedTx = $paymentHub->completeDeposit('IDEM-DEP-STRIPE-001', 'STRIPE-TRX-882711');\n"
       "        $this->assertSame(PaymentStatus::COMPLETED, $completedTx->status);\n")
if old in s:
    new = (
        "        // Confirmation arrives as a gateway webhook. Credit goes through the real\n"
        "        // webhook pipeline, never a direct wallet write.\n"
        "        $webhookResult = $this->app->make(PaymentWebhookService::class)->processWebhookPayload(new WebhookPayload(\n"
        "            gateway: 'stripe',\n"
        "            eventId: 'evt_p003_stripe_0001',\n"
        "            eventType: WebhookEventType::DepositSuccess,\n"
        "            providerReference: 'cs_test_0001',\n"
        "            internalReference: $depositInit['reference_id'],\n"
        "            amount: '2500.00',\n"
        "            currency: Currency::THB,\n"
        "            isSuccess: true,\n"
        "            rawData: ['id' => 'cs_test_0001', 'status' => 'complete'],\n"
        "        ));\n"
        "        $this->assertTrue($webhookResult->success);\n"
        "        $this->assertSame('credited', $webhookResult->actionTaken);\n")
    s = s.replace(old, new, 1)
    s = add_use(s, 'use App\\DTOs\\Payment\\WebhookPayload;')
    s = add_use(s, 'use App\\Enums\\WebhookEventType;')
    s = add_use(s, 'use App\\Services\\Payment\\PaymentWebhookService;')
    save(p, s)

# 2. Supported market codes in the bulk-bet fixtures.
for p in ['tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php',
          'tests/Feature/FinalWholeSystemNoSkipTest.php']:
    s = load(p)
    s = s.replace("market: 'two_digit_top'", "market: '2d_top'").replace("market: 'three_digit_top'", "market: '3d_direct'")
    save(p, s)

print('ROUND3 APPLIED')
