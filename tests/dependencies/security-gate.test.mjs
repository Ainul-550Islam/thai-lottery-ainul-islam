import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { validateRuntime, validateLock, validateAudit, runGate } from '../../scripts/dependency_security_gate.mjs';

const root = path.resolve(import.meta.dirname, '../..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'package.json')));
const lock = JSON.parse(fs.readFileSync(path.join(root, 'package-lock.json')));
const copy = value => structuredClone(value);
const clean = () => ({ auditReportVersion: 2, vulnerabilities: {}, metadata: {
    vulnerabilities: { info: 0, low: 0, moderate: 0, high: 0, critical: 0, total: 0 }, dependencies: { total: 149 },
} });

test('the complete reviewed lock and runtime are accepted', () => {
    assert.ok(validateLock(manifest, lock) > 90);
    validateRuntime('24.22.0', '11.21.1');
    assert.equal(validateAudit(clean(), 0), 149);
});
for (const severity of ['info', 'low', 'moderate', 'high', 'critical', 'total']) {
    test(`nonzero ${severity} is refused even if npm exits0`, () => {
        const report = clean(); report.metadata.vulnerabilities[severity] = 1;
        assert.throws(() => validateAudit(report, 0));
    });
    test(`missing ${severity} count is not a clean audit`, () => {
        const report = clean(); delete report.metadata.vulnerabilities[severity];
        assert.throws(() => validateAudit(report, 0));
    });
}
for (const [name, change] of [
    ['an uncounted advisory', r => { r.vulnerabilities.braces = { severity: 'high' }; }],
    ['an audit API error', r => { r.error = { code: 'ENOTFOUND' }; }],
    ['a future unsupported schema', r => { r.auditReportVersion = 3; }],
    ['a string zero', r => { r.metadata.vulnerabilities.high = '0'; }],
    ['an empty graph', r => { r.metadata.dependencies.total = 0; }],
    ['a missing graph', r => { delete r.metadata.dependencies; }],
    ['an array of vulnerabilities', r => { r.vulnerabilities = []; }],
]) test(`refuses ${name}`, () => { const r = clean(); change(r); assert.throws(() => validateAudit(r, 0)); });
for (const status of [null, 1, 2, 127]) test(`refuses npm status ${status} with a superficially clean report`, () => {
    assert.throws(() => validateAudit(clean(), status));
});
for (const version of ['20.20.2', '22.22.0', '23.0.0', '25.0.0', '24.0.0-preview', 'unknown']) {
    test(`runtime ${version} needs review rather than a silently bypassed engine`, () => assert.throws(() => validateRuntime(version, '11.21.1')));
}
for (const version of ['10.8.2', '12.0.0', '', '11.0.0-preview']) {
    test(`npm ${version} is outside the reviewed major`, () => assert.throws(() => validateRuntime('24.22.0', version)));
}
const artifact = copy(lock.packages['node_modules/tailwindcss']);
for (const [name, change] of [
    ['missing root', l => { delete l.packages['']; }],
    ['old lock format', l => { l.lockfileVersion = 2; }],
    ['root manifest drift', l => { l.packages[''].devDependencies.tailwindcss = '3.4.19'; }],
    ['old root engine', l => { l.packages[''].engines.node = '>=20'; }],
    ['missing compiler', l => { delete l.packages['node_modules/tailwindcss']; }],
    ['restored unpatched braces', l => { l.packages['node_modules/braces'] = { ...artifact, version: '3.0.3' }; }],
    ['unreviewed nested compiler', l => { l.packages['node_modules/x/node_modules/tailwindcss'] = { ...artifact, version: '3.4.19' }; }],
    ['unreviewed adapter', l => { l.packages['node_modules/@tailwindcss/postcss'].version = '4.3.2'; }],
    ['remote nonregistry artifact', l => { l.packages['node_modules/tailwindcss'].resolved = 'https://example.invalid/compiler.tgz'; }],
    ['missing integrity', l => { delete l.packages['node_modules/tailwindcss'].integrity; }],
    ['weak hash algorithm', l => { l.packages['node_modules/tailwindcss'].integrity = 'sha1-fake'; }],
    ['top-level fake bundle', l => { l.packages['node_modules/bypass'] = { version: '1.0.0', inBundle: true }; }],
    ['undeclared bundled artifact', l => { l.packages['node_modules/@tailwindcss/oxide-wasm32-wasi/node_modules/bypass'] = { version: '1.0.0', inBundle: true }; }],
    ['unlocked bundle parent', l => { delete l.packages['node_modules/@tailwindcss/oxide-wasm32-wasi'].integrity; }],
]) test(`lock policy rejects ${name}`, () => { const l = copy(lock); change(l); assert.throws(() => validateLock(manifest, l)); });

test('audit invocation explicitly includes development and optional packages and all severities', () => {
    const calls = [];
    const execute = (command, args, options) => {
        calls.push({ command, args, options });
        return { status: 0, stdout: args.includes('--version') ? '11.21.1\n' : JSON.stringify(clean()) };
    };
    assert.equal(runGate(root, execute, '24.22.0').passed, true);
    assert.deepEqual(calls[1].args.slice(-5), ['audit', '--include=dev', '--include=optional', '--audit-level=low', '--json']);
    assert.equal(calls[1].options.shell, false);
    assert.ok(calls[1].options.timeout > 0);
});
for (const [name, result] of [
    ['network failure', { status: 1, stdout: JSON.stringify({ error: { code: 'ENOTFOUND' } }) }],
    ['malformed JSON', { status: 0, stdout: 'gateway unavailable' }],
    ['timeout', { status: null, signal: 'SIGTERM', stdout: '' }],
    ['spawn failure', { status: null, error: new Error('not found'), stdout: '' }],
]) test(`audit ${name} cannot turn green`, () => {
    const execute = (_command, args) => args.includes('--version') ? { status: 0, stdout: '11.21.1' } : result;
    assert.throws(() => runGate(root, execute, '24.22.0'));
});

test('all five CI Node setups follow the reviewed version file; every npm install lane runs the gate', () => {
    let setups = 0;
    for (const name of ['ci.yml', 'security.yml', 'financial-concurrency.yml']) {
        const workflow = fs.readFileSync(path.join(root, '.github/workflows', name), 'utf8');
        const lines = workflow.split('\n');
        assert.doesNotMatch(workflow, /npm audit --audit-level=high|--omit=dev|npm audit[^\n]*\|\| true/);
        for (let i = 0; i < lines.length; i++) {
            if (lines[i].includes('uses: actions/setup-node@')) {
                setups++;
                assert.match(lines.slice(i, i + 5).join('\n'), /node-version-file: '.nvmrc'/);
            }
            if (lines[i].trim().startsWith('npm ci')) {
                assert.match(lines.slice(i, i + 5).join('\n'), /npm run security:dependencies/);
            }
        }
    }
    assert.equal(setups, 5);
    assert.equal(fs.readFileSync(path.join(root, '.nvmrc'), 'utf8').trim(), '24.22.0');
    assert.match(fs.readFileSync(path.join(root, '.npmrc'), 'utf8'), /^engine-strict=true\s*$/);
});
