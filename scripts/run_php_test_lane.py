#!/usr/bin/env python3
"""Partition, execute and census every PHPUnit case without a skipped test.

SQLite-compatible cases run in one job. Native row-lock, live worker and
independent-engine cases run in the separately provisioned MySQL job. Both
jobs write a per-case plan and JUnit reports; reconcile() requires both plans
and both sets of passing reports from the same commit. No test is deleted or
counted as passed merely because it belongs in a different runtime lane.
"""
from __future__ import annotations
import argparse
from collections import Counter, defaultdict
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent
NAMESPACE = {'p': 'https://xml.phpunit.de/testSuite'}
NATIVE_ADDITIONS = {
    'tests/Feature/Agent/CommissionCancellationCurrentReadTest.php',
    'tests/Feature/Agent/CommissionPayoutCurrentReadTest.php',
    'tests/Feature/Agent/PaidCommissionCurrentReadTest.php',
    'tests/Feature/Betting/BetCancellationCurrentReadTest.php',
    'tests/Feature/Payment/FinancialHoldCurrentReadTest.php',
}
SPECIAL = {
    'tests/Feature/Settlement/SettlementWorkerLiveIntegrationTest.php': 'live-worker',
    'tests/Integration/Concurrency/PasswordLifecycleRealEngineTest.php': 'real-engine',
}
NATIVE_METHODS = {
    ('LedgerPostingBalanceProjectionTest', 'test_native_decimal_store_commits_exact_inclusive_signed_boundary'),
    ('LedgerPostingEntryMoneyTest', 'test_native_store_preserves_exact_amount_and_signed_snapshot_column_boundaries'),
    ('LedgerPostingObserverOwnershipTest', 'test_owned_observer_is_cleaned_once_without_committing_repairing_or_disconnecting_caller'),
    ('DurableDepositCheckoutTest', 'test_independent_attempt_write_during_original_replay_has_no_obsolete_user_snapshot_abort'),
    ('PayoutBatchProjectionTest', 'test_server_duplicate_wallet_execution_posts_once'),
    ('PayoutBatchProjectionTest', 'test_server_duplicate_route_claim_enqueues_once'),
    ('PayoutTransferReferenceTest', 'test_server_concurrent_duplicate_confirmation_posts_one_transaction'),
    ('PayoutTransferReferenceTest', 'test_server_distinct_prizes_cannot_share_one_provider_reference'),
    ('SettlementSimulationTest', 'point_20_lets_only_one_of_two_concurrent_settlements_write'),
}
NATIVE_PATTERN = '|'.join(re.escape(c + '::' + m) for c, m in sorted(NATIVE_METHODS))
LANES = ('sqlite', 'server', 'server-methods', 'live-worker', 'real-engine')


def junit_id(case: ET.Element) -> str:
    name = case.get('name', '')
    base, separator, dataset = name.partition(' with data set ')
    identifier = case.get('class', '') + '::' + base
    if separator:
        if dataset.startswith('#'):
            dataset = dataset[1:]
        elif dataset.startswith('"') and dataset.endswith('"'):
            dataset = dataset[1:-1]
        else:
            raise ValueError('Unknown PHPUnit data-set format: ' + name)
        identifier += '#' + dataset
    return identifier


def method_counter(ids: list[str]) -> Counter[str]:
    return Counter(identifier.split('#', 1)[0] for identifier in ids)


def build_plan(root: Path, discovery: Path) -> dict:
    native = {str(e.text) for e in ET.parse(root / 'phpunit.mysql.xml').findall('.//testsuite/file')}
    native.update(NATIVE_ADDITIONS)
    files = defaultdict(lambda: defaultdict(list))
    lanes = defaultdict(list)
    classes = set()
    matched = set()
    for cl in ET.parse(discovery).findall('.//p:tests/p:testClass', NAMESPACE):
        path = str(Path(cl.attrib['file']).resolve().relative_to(root))
        classes.add(path)
        cls = cl.attrib['name'].split('\\')[-1]
        suffix = path.endswith(('ServerTest.php', 'ServerRaceTest.php'))
        base_lane = 'server' if path in native or suffix else SPECIAL.get(path, 'sqlite')
        for method in cl.findall('p:testMethod', NAMESPACE):
            lane = base_lane
            if base_lane == 'sqlite' and (cls, method.attrib['name']) in NATIVE_METHODS:
                matched.add((cls, method.attrib['name']))
                lane = 'server-methods'
            identifier = method.attrib['id']
            lanes[lane].append(identifier)
            files[lane][path].append(identifier)
    missing = NATIVE_METHODS - matched
    if missing:
        raise ValueError('Native method routing no longer matches discovery: ' + repr(missing))
    if native - classes:
        raise ValueError('MySQL suite contains undiscovered files: ' + repr(native - classes))
    all_ids = [identifier for lane in LANES for identifier in lanes[lane]]
    if not all_ids or len(all_ids) != len(set(all_ids)):
        raise ValueError('Discovery is empty or contains duplicate full data-set identifiers')
    commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
    return {
        'version': 1, 'commit': commit, 'total_discovered': len(all_ids),
        'lanes': {lane: sorted(lanes[lane]) for lane in LANES},
        'files': {lane: {path: ids for path, ids in sorted(files[lane].items())} for lane in LANES},
    }


def check_junit(path: Path, expected: list[str]) -> dict:
    cases = ET.parse(path).getroot().findall('.//testcase')
    actual = [junit_id(case) for case in cases]
    bad = [(case.get('class'), case.get('name'), outcome)
           for case in cases for outcome in ('failure', 'error', 'skipped') if case.find(outcome) is not None]
    missing, extra = Counter(expected) - Counter(actual), Counter(actual) - Counter(expected)
    if missing or extra or bad:
        raise ValueError(f'JUnit coverage/outcomes invalid: missing={dict(missing)} extra={dict(extra)} bad={bad}')
    return {'cases': len(cases), 'assertions': sum(int(case.get('assertions', '0')) for case in cases)}


def chunks(files: dict[str, list[str]], limit: int = 125) -> list[list[str]]:
    result, current, count = [], [], 0
    for path, ids in sorted(files.items()):
        if current and count + len(ids) > limit:
            result.append(current)
            current, count = [], 0
        current.append(path)
        count += len(ids)
    if current:
        result.append(current)
    return result


def run_lane(root: Path, output: Path, lane: str, plan_only: bool, supplied_discovery: Path | None) -> int:
    output.mkdir(parents=True, exist_ok=True)
    discovery = supplied_discovery or output / 'discovery.xml'
    env = os.environ.copy()
    env.update({'APP_BASE_PATH': str(root), 'APP_ENV': 'testing', 'LOG_CHANNEL': 'null'})
    if lane == 'sqlite':
        env.update({'DB_CONNECTION': 'sqlite', 'DB_DATABASE': env.get('SQLITE_TEST_DATABASE', str(root / 'database/thai_lottery_test'))})
        for key in ('DB_URL', 'CONCURRENCY_TEST_DSN', 'SETTLEMENT_WORKER_LIVE_TEST'):
            env.pop(key, None)
    else:
        env.update({
            'DB_CONNECTION': 'mysql', 'FINANCIAL_PAYOUT_RACE': '1',
            'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync',
            'APP_COUNTRY': 'TH', 'FINANCE_DEFAULT_CURRENCY': 'THB', 'STRIPE_ENABLED': 'true',
            'STRIPE_KEY': 'pk_test_mock_publishable_key_12345',
            'STRIPE_SECRET': 'sk_test_mock_secret_key_12345',
            'STRIPE_WEBHOOK_SECRET': 'whsec_mock_stripe_webhook_secret_67890',
        })
        for key in ('DB_URL',):
            env.pop(key, None)
    if supplied_discovery is None:
        subprocess.run(['php', 'vendor/bin/phpunit', '--list-tests-xml', str(discovery)], cwd=root, env=env, check=True)
    plan = build_plan(root, discovery)
    (output / 'plan.json').write_text(json.dumps(plan, indent=2) + '\n')
    print('discovered', plan['total_discovered'], {key: len(ids) for key, ids in plan['lanes'].items()}, flush=True)
    if plan_only:
        print('PLAN ONLY: no execution or pass is claimed', flush=True)
        return 0
    if lane == 'mysql':
        socket = Path(env.get('DB_SOCKET', ''))
        if (env.get('DB_DATABASE') != 'thai_lottery_test' or not socket.is_absolute()
                or not socket.exists() or not stat.S_ISSOCK(socket.stat().st_mode)):
            raise ValueError('MySQL lane requires its owned thai_lottery_test and a local test-server socket')
        if (env.get('SETTLEMENT_WORKER_LIVE_TEST') != '1'
                or env.get('CONCURRENCY_TEST_ALLOW_DESTRUCTIVE') != '1'
                or not env.get('CONCURRENCY_TEST_DSN')):
            raise ValueError('MySQL lane requires the live worker and an acknowledged independent-engine fixture')
    (output / 'summary.json').write_text('[]\n')
    if lane == 'sqlite':
        groups = [('sqlite', paths, ['--exclude-filter', NATIVE_PATTERN]) for paths in chunks(plan['files']['sqlite'])]
    else:
        groups = [
            ('server', sorted(plan['files']['server']), []),
            ('server-methods', sorted(plan['files']['server-methods']), ['--filter', NATIVE_PATTERN]),
            ('live-worker', sorted(plan['files']['live-worker']), []),
            ('real-engine', sorted(plan['files']['real-engine']), []),
        ]
    result, bad = [], False
    for index, (sub_lane, paths, filters) in enumerate(groups, 1):
        if not paths:
            raise ValueError('No files assigned to ' + sub_lane)
        expected = [identifier for path in paths for identifier in plan['files'][sub_lane][path]]
        junit = output / f'group-{index:03}.xml'
        log = output / f'group-{index:03}.log'
        junit.unlink(missing_ok=True)  # Never accept an old report as this run's evidence.
        command = ['php', '-d', 'memory_limit=768M', 'vendor/bin/phpunit', '--no-coverage',
                   '--fail-on-skipped', '--fail-on-risky', '--fail-on-incomplete', '--fail-on-empty-test-suite',
                   *filters, '--log-junit', str(junit), *paths]
        started = time.monotonic()
        with log.open('w') as stream:
            try:
                process = subprocess.run(command, cwd=root, env=env, stdout=stream, stderr=subprocess.STDOUT, timeout=1800)
                rc = process.returncode
            except subprocess.TimeoutExpired:
                rc = 124
        row = {'lane': sub_lane, 'files': paths, 'expected': len(expected), 'exit': rc,
               'junit': junit.name, 'log': log.name, 'seconds': round(time.monotonic() - started, 2)}
        try:
            row.update(check_junit(junit, expected))
        except (OSError, ValueError, ET.ParseError) as exc:
            row['census_error'] = str(exc)
            rc = row['exit'] = rc or 1
        result.append(row)
        (output / 'summary.json').write_text(json.dumps(result, indent=2) + '\n')
        print(f'[{index}/{len(groups)}] {sub_lane} expected={len(expected)} actual={row.get("cases", "?")} exit={rc} {row["seconds"]}s', flush=True)
        if rc:
            bad = True
    return int(bad)


def reconcile(root: Path, sqlite: Path, mysql: Path) -> int:
    sqlite_plan = json.loads((sqlite / 'plan.json').read_text())
    mysql_plan = json.loads((mysql / 'plan.json').read_text())
    for plan in (sqlite_plan, mysql_plan):
        ids = [identifier for lane in LANES for identifier in plan['lanes'][lane]]
        if len(ids) != plan['total_discovered'] or len(ids) != len(set(ids)):
            raise ValueError('Plan has missing or duplicated case identities')
        for lane in LANES:
            from_files = [identifier for group in plan['files'][lane].values() for identifier in group]
            if Counter(from_files) != Counter(plan['lanes'][lane]):
                raise ValueError('Plan file inventory differs from case inventory for lane ' + lane)
    if sqlite_plan['commit'] != mysql_plan['commit']:
        raise ValueError('The PHP lanes were run on different commits')
    # The password-race data provider names its dataset after the configured
    # engine; compare method multiplicity across jobs while each job validates
    # exact dataset IDs against its own discovery and JUnit below.
    for lane in LANES:
        if method_counter(sqlite_plan['lanes'][lane]) != method_counter(mysql_plan['lanes'][lane]):
            raise ValueError('Discovery disagrees between jobs for lane ' + lane)
    for output, plan, wanted in [(sqlite, sqlite_plan, {'sqlite'}),
                                 (mysql, mysql_plan, {'server', 'server-methods', 'live-worker', 'real-engine'})]:
        summary = json.loads((output / 'summary.json').read_text())
        actual = Counter()
        for row in summary:
            if row['exit'] != 0 or row['lane'] not in wanted:
                raise ValueError('Failed or wrongly routed group: ' + repr(row))
            expected = [identifier for path in row['files'] for identifier in plan['files'][row['lane']][path]]
            check_junit(output / row['junit'], expected)
            actual.update(expected)
        required = Counter(identifier for lane in wanted for identifier in plan['lanes'][lane])
        if actual != required:
            raise ValueError('Lane case coverage mismatch: missing=' + repr(dict(required - actual)) + ' extra=' + repr(dict(actual - required)))
    print(f"PHP case census passed: {sqlite_plan['total_discovered']} discovered, each assigned case ran exactly once in its required lane; no failures/errors/skips.")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=ROOT)
    parser.add_argument('--lane', choices=('sqlite', 'mysql'))
    parser.add_argument('--output', type=Path)
    parser.add_argument('--discovery', type=Path)
    parser.add_argument('--plan-only', action='store_true')
    parser.add_argument('--reconcile', nargs=2, type=Path, metavar=('SQLITE_DIR', 'MYSQL_DIR'))
    args = parser.parse_args()
    root = args.root.resolve()
    try:
        if args.reconcile:
            return reconcile(root, *args.reconcile)
        if not args.lane or not args.output:
            parser.error('--lane and --output are required when not reconciling')
        return run_lane(root, args.output.resolve(), args.lane, args.plan_only,
                        args.discovery.resolve() if args.discovery else None)
    except (OSError, subprocess.CalledProcessError, ValueError, ET.ParseError) as exc:
        print('PHP test-lane failure:', exc, file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
