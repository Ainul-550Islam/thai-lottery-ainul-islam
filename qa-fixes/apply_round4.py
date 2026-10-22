"""Round 4: number-limit fixtures for bulk bets, request-path withdrawal in p0_03,
and the imports those need. Idempotent: each edit is skipped once applied."""
import sys

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


def limit_row(draw_var, bet_type, number):
    return (
        "        NumberLimit::query()->create([\n"
        "            'draw_id' => $%s->id,\n"
        "            'bet_type' => '%s',\n"
        "            'number' => '%s',\n"
        "            'max_amount' => '100000.00',\n"
        "            'maximum_payout_exposure' => '10000000.00',\n"
        "        ]);\n") % (draw_var, bet_type, number)


# 1. Number-limit rows for the bulk-bet fixtures (number_limits is per draw).
p = 'tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php'
s = load(p)
anchor = "        /** @var BulkBetService $bulkService */\n"
if 'NumberLimit::query()->create' not in s:
    assert s.count(anchor) == 1, 'p0_06 anchor'
    s = s.replace(anchor,
        "        // Each bet number needs a risk limit row on this draw.\n"
        + limit_row('draw', '2d', '19') + "\n" + anchor, 1)
    s = add_use(s, 'use App\\Models\\NumberLimit;')
    s = add_use(s, 'use App\\Enums\\PaymentMethod;')
    s = add_use(s, 'use App\\Services\\Finance\\Money;')
    s = add_use(s, 'use App\\Services\\Finance\\WithdrawalService;')
    s = add_use(s, 'use App\\Services\\Finance\\WithdrawalApprovalService;')
    s = add_use(s, 'use App\\Services\\Finance\\WithdrawalCompletionService;')

    # 2. p0_03 withdrawal leg goes through the real request path.
    start_marker = "        // Outbound Withdrawal Disbursement\n"
    end_marker = "        $paymentHub->disburseWithdrawal($withdrawal, $operator);\n"
    a = s.index(start_marker)
    b = s.index(end_marker, a) + len(end_marker)
    new_block = (
        "        // Outbound withdrawal: created through the real request path, approved\n"
        "        // (which reserves the funds), then disbursed and completed by the canonical services.\n"
        "        /** @var WithdrawalService $withdrawalService */\n"
        "        $withdrawalService = $this->app->make(WithdrawalService::class);\n"
        "        $withdrawal = $withdrawalService->request(\n"
        "            $wallet,\n"
        "            Money::of('1000.00', Currency::THB),\n"
        "            PaymentMethod::BankTransfer,\n"
        "            'IDEM-WD-HUB-001',\n"
        "            ['destination_details' => ['bank_name' => 'Bangkok Bank', 'account_number' => '9981123344']],\n"
        "        );\n"
        "        $this->app->make(WithdrawalApprovalService::class)->approve($withdrawal, null, 'P0P1P2 hub test approval');\n\n"
        "        $paymentHub->disburseWithdrawal($withdrawal, $operator);\n"
        "        $this->app->make(WithdrawalCompletionService::class)->complete($withdrawal->fresh());\n")
    s = s[:a] + new_block + s[b:]
    save(p, s)

# 3. FinalWhole test 04: number-limit rows for both bet numbers.
p = 'tests/Feature/FinalWholeSystemNoSkipTest.php'
s = load(p)
anchor = "        /** @var BulkBetService $betService */\n"
if 'NumberLimit::query()->create' not in s:
    assert s.count(anchor) == 1, 'fw04 anchor'
    s = s.replace(anchor,
        "        // Each bet number needs a risk limit row on this draw.\n"
        + limit_row('draw', '2d', '42') + limit_row('draw', '3d', '742') + "\n" + anchor, 1)
    s = add_use(s, 'use App\\Models\\NumberLimit;')
    save(p, s)

print('ROUND4 APPLIED')
