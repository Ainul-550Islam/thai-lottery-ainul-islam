#!/usr/bin/env node
// Audit the complete locked npm graph, including dev/optional build tools. No allowlist or advisory suppression.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { spawnSync } from 'node:child_process';

const object = value => value !== null && typeof value === 'object' && !Array.isArray(value);

export function validateRuntime(nodeVersion, npmVersion) {
    if (!/^24\.\d+\.\d+$/.test(nodeVersion)) throw new Error('Use the reviewed Node24 release from .nvmrc.');
    if (!/^11\.\d+\.\d+$/.test(npmVersion)) throw new Error('Use the reviewed npm11 toolchain from packageManager.');
}

export function validateLock(manifest, lock) {
    if (!object(manifest) || !object(lock) || lock.lockfileVersion !== 3 || !object(lock.packages?.[''])) {
        throw new Error('A version3 package lock with a root package is required.');
    }
    for (const group of ['dependencies', 'devDependencies']) {
        const desired = manifest[group] ?? {};
        const actual = lock.packages[''][group] ?? {};
        if (!object(desired) || !object(actual) || JSON.stringify(Object.entries(desired).sort()) !== JSON.stringify(Object.entries(actual).sort())) {
            throw new Error('Manifest and locked root dependencies disagree. Run the reviewed locked install.');
        }
    }
    for (const scope of [manifest, lock.packages['']]) {
        if (scope.engines?.node !== '>=24 <25' || scope.engines?.npm !== '>=11 <12') throw new Error('Node/npm engine policy is missing.');
    }
    if (manifest.packageManager !== 'npm@11.21.1') throw new Error('Review and pin the npm package-manager release.');
    if (manifest.devDependencies?.tailwindcss !== '4.3.3' || manifest.devDependencies?.['@tailwindcss/postcss'] !== '4.3.3') {
        throw new Error('The reviewed paired Tailwind/PostCSS releases are required.');
    }
    let count = 0;
    for (const [location, pkg] of Object.entries(lock.packages)) {
        if (location === '') continue;
        count++;
        const artifact = candidate => typeof candidate?.resolved === 'string'
            && candidate.resolved.startsWith('https://registry.npmjs.org/')
            && typeof candidate.integrity === 'string' && /^sha512-[A-Za-z0-9+/]+=*$/.test(candidate.integrity);
        const name = location.split('node_modules/').at(-1);
        const boundary = location.lastIndexOf('/node_modules/');
        const parent = boundary > 0 ? lock.packages[location.slice(0, boundary)] : null;
        // npm records bundled WASM dependencies without individual SRI fields: their
        // bytes are covered by the containing, integrity-locked npm tarball.
        const bundled = pkg?.inBundle === true && pkg.resolved === undefined && pkg.integrity === undefined
            && artifact(parent) && Array.isArray(parent.bundleDependencies) && parent.bundleDependencies.includes(name);
        if (!location.startsWith('node_modules/') || !object(pkg) || !/^\d+\.\d+\.\d+(?:[-+].*)?$/.test(pkg.version ?? '')
            || (!artifact(pkg) && !bundled)) throw new Error('A dependency is not an integrity-locked npm registry artifact.');
        // braces has no reviewed patched registry release. Do not reintroduce its legacy compiler chain.
        if (name === 'braces') throw new Error('The unpatched braces dependency must not be restored.');
        if (name === 'tailwindcss' && pkg.version !== '4.3.3') throw new Error('An unreviewed Tailwind compiler is locked.');
        if (name === '@tailwindcss/postcss' && pkg.version !== '4.3.3') throw new Error('An unreviewed Tailwind PostCSS adapter is locked.');
    }
    if (count === 0 || !lock.packages['node_modules/tailwindcss'] || !lock.packages['node_modules/@tailwindcss/postcss']) {
        throw new Error('The complete compiler dependency graph is missing.');
    }
    return count;
}

export function validateAudit(report, exitStatus) {
    if (exitStatus !== 0 || !object(report) || Object.hasOwn(report, 'error') || report.auditReportVersion !== 2
        || !object(report.vulnerabilities) || Object.keys(report.vulnerabilities).length !== 0
        || !object(report.metadata?.vulnerabilities) || !Number.isInteger(report.metadata?.dependencies?.total)
        || report.metadata.dependencies.total <= 0) throw new Error('The complete npm audit failed, is unavailable, or has an unsupported response.');
    for (const severity of ['info', 'low', 'moderate', 'high', 'critical', 'total']) {
        if (report.metadata.vulnerabilities[severity] !== 0) throw new Error('No known advisory severity may be suppressed.');
    }
    return report.metadata.dependencies.total;
}

export function runGate(root, execute = spawnSync, nodeVersion = process.versions.node) {
    const npm = process.env.npm_execpath;
    const command = npm ? process.execPath : 'npm';
    const prefix = npm ? [npm] : [];
    const options = { cwd: root, encoding: 'utf8', timeout: 120_000, maxBuffer: 10 * 1024 * 1024, shell: false };
    const version = execute(command, [...prefix, '--version'], options);
    if (version.error || version.status !== 0) throw new Error('npm could not be verified.');
    validateRuntime(nodeVersion, version.stdout.trim());
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
    const lock = JSON.parse(fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8'));
    const lockedPackages = validateLock(manifest, lock);
    const audit = execute(command, [...prefix, 'audit', '--include=dev', '--include=optional', '--audit-level=low', '--json'], options);
    if (audit.error || audit.signal) throw new Error('npm audit could not complete; refusing a green result.');
    const report = JSON.parse(audit.stdout);
    const auditedPackages = validateAudit(report, audit.status);
    return { passed: true, scope: 'Known advisories in the complete npm lock graph, not a whole-platform security certification.',
        node: nodeVersion, npm: version.stdout.trim(), lockedPackages, auditedPackages,
        vulnerabilities: report.metadata.vulnerabilities };
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
    try {
        const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
        console.log(JSON.stringify(runGate(root), null, 2));
    } catch (error) {
        console.error(JSON.stringify({ passed: false, reason: error.message }));
        process.exitCode = 1;
    }
}
