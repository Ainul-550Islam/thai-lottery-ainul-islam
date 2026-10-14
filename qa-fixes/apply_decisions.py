"""Applies the owner-delegated decisions. Idempotent: each edit is guarded."""
import re, sys
root = '/home/user/thai-lottery-ainul-islam/'

def load(p): return open(root + p).read()
def save(p, s): open(root + p, 'w').write(s)

def one(s, old, new, label):
    n = s.count(old)
    if n != 1:
        sys.exit('MISMATCH %s: %d' % (label, n))
    return s.replace(old, new)

def add_use(s, line, after_prefix):
    if line in s:
        return s
    idx = s.index(after_prefix)
    end = s.index('\n', idx) + 1
    return s[:end] + line + '\n' + s[end:]

# ---- 1. BulkBetService::placeBulkBet wrapper
p = 'app/Services/Betting/BulkBetService.php'
s = load(p)
if 'function placeBulkBet' not in s:
    method = '''    /**
     * Places one slip of selections for a draw. Every item is executed through
     * purchase(), so legality, wallet debits and per-item idempotency apply unchanged.
     *
     * @param  array<int, \\App\\DTOs\\Betting\\BulkBetSelectionData>  $selections
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
            if ($item['bet_id'] !== null) {
                $betIds[] = (int) $item['bet_id'];
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
    s = one(s, '    public function purchase(int $userId, int $drawId, array $selections, string $clientKey): array\n',
           method + '    public function purchase(int $userId, int $drawId, array $selections, string $clientKey): array\n',
           'bulk purchase anchor')
    save(p, s)

# ---- 2. SelfExclusionService::isSelfExcluded wrapper (Compliance service)
p = 'app/Services/Compliance/SelfExclusionService.php'
s = load(p)
if 'function isSelfExcluded' not in s:
    s = one(s, '    public function hasActiveExclusion(int $userId): bool\n',
      '    /** Whether the user has a self-exclusion in force right now. */\n'
      '    public function isSelfExcluded(int $userId): bool\n'
      '    {\n'
      '        return $this->hasActiveExclusion($userId);\n'
      '    }\n\n'
      '    public function hasActiveExclusion(int $userId): bool\n', 'self-exclusion anchor')
    save(p, s)

# ---- 3. Tests: exclusion goes through request() then activate()
pat = re.compile(r"(\$\w+)->exclude\((new SelfExclusionData\(.*?\))\);", re.S)
for p in ['tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php',
          'tests/Feature/FinalWholeSystemNoSkipTest.php']:
    s = load(p)
    if '->exclude(' in s:
        s, n = pat.subn(lambda m: '$pendingExclusion = %s->request(%s);\n        %s->activate($pendingExclusion);' % (m.group(1), m.group(2), m.group(1)), s)
        if n != 1:
            sys.exit('exclusion replacements in %s: %d' % (p, n))
        save(p, s)

# ---- 4. P0P1P2 p0_03: THB deposit through Stripe, bKash removed from this test
p = 'tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php'
s = load(p)
start_m = s.index('    public function test_p0_03_')
end_m = s.index('    public function test_p0_04_', start_m) if 'function test_p0_04_' in s else None
section = s[start_m:end_m]
if 'bKash is disabled by default' in section:
    a = section.index('        // bKash is disabled by default')
    b = section.index('        /** @var ProductionPaymentExecutionHubService $paymentHub */')
    block = (
"        // The deposit is THB, so it uses the THB-capable card gateway (stripe).\n"
"        // bKash is BDT-only in config and cannot take a THB deposit. The Stripe API\n"
"        // is faked, so no network call and no real key is used. Bank transfer is\n"
"        // enabled with placeholder settlement values for the withdrawal leg.\n"
"        config([\n"
"            'payment.gateways.stripe.enabled' => true,\n"
"            'payment.gateways.stripe.secret' => 'sk_test_placeholder',\n"
"            'payment.gateways.bank_transfer.enabled' => true,\n"
"            'payment.gateways.bank_transfer.settlement' => [\n"
"                'bank_name' => 'TEST BANK',\n"
"                'account_number' => '0000000000',\n"
"                'account_name' => 'TEST OPERATOR',\n"
"                'instructions' => 'Test fixture only',\n"
"            ],\n"
"        ]);\n"
"        Http::fake([\n"
"            'api.stripe.com/*' => Http::response([\n"
"                'id' => 'cs_test_0001',\n"
"                'url' => 'https://checkout.stripe.test/c/cs_test_0001',\n"
"                'payment_intent' => null,\n"
"            ], 200),\n"
"        ]);\n\n")
    section = section[:a] + block + section[b:]
    section = section.replace("'channel' => 'bkash',", "'channel' => 'stripe',")
    section = section.replace('IDEM-DEP-BKASH-001', 'IDEM-DEP-STRIPE-001').replace('BKASH-TRX-882711', 'STRIPE-TRX-882711')
    s = s[:start_m] + section + s[end_m:] if end_m else s[:start_m] + section
    save(p, s)

# ---- 5. FinalWhole test 05: rewrite against the existing calculator contract
p = 'tests/Feature/FinalWholeSystemNoSkipTest.php'
s = load(p)
if 'calculateForSales' not in s:
    start = s.index('    public function test_05_')
    end = s.index('    public function test_06_', start)
    new05 = '''    public function test_05_proportional_prize_calculation_and_settlement(): void
    {
        /** @var GloL6ProportionalPrizeCalculator $calc */
        $calc = $this->app->make(GloL6ProportionalPrizeCalculator::class);

        // The 60% prize-pool share is declared configuration, not a method argument.
        $this->assertSame('60.00', (string) config('glo.l6.prize_pool_allocation_percent'));

        // Full sell-out: the proportional pot equals the full pot for every tier.
        $tiers = $calc->calculateForSales('10000000.00', 1000000);
        $this->assertNotEmpty($tiers);
        foreach ($tiers as $tier) {
            $this->assertSame($tier['full_pot'], $tier['proportional_pot']);
        }

        // Official stamp-duty rule: ceil(gross / 200) THB. First prize 6,000,000 THB => 30,000 THB.
        /** @var GloStampDutyCalculator $duty */
        $duty = $this->app->make(GloStampDutyCalculator::class);
        $this->assertEqualsWithDelta(30000.0, (float) $duty->dutyFor('6000000.00'), 0.001);
    }

'''
    s = s[:start] + new05 + s[end:]
    s = add_use(s, 'use App\\Services\\Lottery\\GloStampDutyCalculator;', 'use App\\Services\\Lottery\\GloL6ProportionalPrizeCalculator;')
    save(p, s)

print('DECISIONS APPLIED')
