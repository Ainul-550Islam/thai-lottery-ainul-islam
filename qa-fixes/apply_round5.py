"""Round 5: move the two bulk-bet tests into a truncation-based class.

Why: BetPurchaseTransactionService refuses a purchase that runs inside an open
transaction (a deliberate invariant). RefreshDatabase wraps every test in one, so
the bulk tests always refuse. ApiPurchaseTestCase uses DatabaseTruncation for this
reason. The test bodies are moved unchanged except for their fixtures.
Idempotent: skipped once the new file exists and the methods are gone from the suites."""
import os
import re

root = '/home/user/thai-lottery-ainul-islam/'
P0 = root + 'tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php'
FW = root + 'tests/Feature/FinalWholeSystemNoSkipTest.php'
NEW = root + 'tests/Feature/Betting/BulkBetPlacementTest.php'


def extract(s, start_marker, end_marker):
    a = s.index(start_marker)
    b = s.index(end_marker, a)
    return s[a:b], s[:a] + s[b:]


def use_lines(s):
    return [l for l in s.split('\n') if l.startswith('use ') and l.endswith(';')]


if os.path.exists(NEW):
    print('ROUND5 ALREADY APPLIED')
    raise SystemExit(0)

p0 = open(P0).read()
fw = open(FW).read()

m_p0, p0_rest = extract(p0, '    public function test_p0_06_', '    public function test_p0_07_')
m_fw, fw_rest = extract(fw, '    public function test_04_', '    public function test_05_')

uses = []
for line in use_lines(p0) + use_lines(fw):
    if line == 'use Illuminate\\Support\\Carbon;':
        continue  # same class as Carbon\\Carbon; keep one import
    if line not in uses:
        uses.append(line)
uses.append('use Tests\\Feature\\Api\\V1\\ApiPurchaseTestCase;')

body = (
    '<?php\n\n'
    'declare(strict_types=1);\n\n'
    'namespace Tests\\Feature\\Betting;\n\n'
    + '\n'.join(sorted(set(uses))) + '\n\n'
    '/**\n'
    ' * Bulk slip placement through BulkBetService::placeBulkBet().\n'
    ' *\n'
    ' * Lives apart from the suites because purchases must own their transaction\n'
    ' * (BetPurchaseTransactionService invariant). DatabaseTruncation, inherited from\n'
    ' * ApiPurchaseTestCase, keeps them outside a wrapping RefreshDatabase transaction.\n'
    ' */\n'
    'final class BulkBetPlacementTest extends ApiPurchaseTestCase\n'
    '{\n'
    + m_p0.rstrip() + '\n\n'
    + m_fw.rstrip() + '\n'
    '}\n'
)

os.makedirs(os.path.dirname(NEW), exist_ok=True)
open(NEW, 'w').write(body)
open(P0, 'w').write(p0_rest)
open(FW, 'w').write(fw_rest)
print('ROUND5 APPLIED')
