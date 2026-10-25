import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

function read(root, relative) {
    return fs.readFileSync(path.join(root, relative), 'utf8');
}

export function verifyRuntimeMatrix(root, { installed = false } = {}) {
    const issues = [];
    const composer = JSON.parse(read(root, 'composer.json'));
    const packageJson = JSON.parse(read(root, 'package.json'));
    const php = String(composer.require?.php || '').match(/8\.\d+/)?.[0] || '';
    const node = read(root, '.nvmrc').trim();
    const npm = String(packageJson.packageManager || '').replace(/^npm@/, '');
    const expected = { php, node, npm };

    if (php !== '8.4') issues.push(`Composer PHP requirement does not resolve to the supported 8.4 minor: ${php}`);
    if (node !== '24.22.0') issues.push(`.nvmrc must pin Node 24.22.0, found ${node}`);
    if (npm !== '11.21.1') issues.push(`packageManager must pin npm 11.21.1, found ${npm}`);
    if (packageJson.engines?.node !== '>=24 <25') issues.push('package.json Node engine range drifted from Node 24.');
    if (packageJson.engines?.npm !== '>=11 <12') issues.push('package.json npm engine range drifted from npm 11.');

    const workflowDirectory = path.join(root, '.github', 'workflows');
    for (const name of fs.readdirSync(workflowDirectory).filter((file) => file.endsWith('.yml') || file.endsWith('.yaml'))) {
        const source = read(root, `.github/workflows/${name}`);
        const phpVersions = [...source.matchAll(/php-version:\s*['"]?([^\s'"]+)/g)].map((match) => match[1]);
        const nodeSetups = [...source.matchAll(/uses:\s*actions\/setup-node@/g)].length;
        const nodeVersions = [...source.matchAll(/node-version:\s*['"]?([^\s'"]+)/g)].map((match) => match[1]);
        const nodeVersionFiles = [...source.matchAll(/node-version-file:\s*['"]?([^\s'"]+)/g)].map((match) => match[1]);
        phpVersions.forEach((version) => {
            if (version !== php) issues.push(`${name} uses PHP ${version}; expected ${php}`);
        });
        nodeVersions.forEach((version) => {
            if (version !== node) issues.push(`${name} uses Node ${version}; expected ${node}`);
        });
        nodeVersionFiles.forEach((file) => {
            if (file !== '.nvmrc') issues.push(`${name} uses Node version file ${file}; expected .nvmrc`);
        });
        if (nodeVersions.length + nodeVersionFiles.length !== nodeSetups) {
            issues.push(`${name} has a Node setup without exactly one version selector.`);
        }
        const npmActivations = [...source.matchAll(/npm install --global npm@([0-9.]+)/g)].map((match) => match[1]);
        if (npmActivations.length !== nodeSetups) issues.push(`${name} does not activate the pinned npm exactly once after every Node setup.`);
        npmActivations.forEach((version) => {
            if (version !== npm) issues.push(`${name} activates npm ${version}; expected ${npm}`);
        });
        if (/npm audit --audit-level=high|--omit=dev|npm audit[^\n]*\|\| true/.test(source)) {
            issues.push(`${name} contains a partial or fail-open npm audit lane.`);
        }
        const lines = source.split('\n');
        lines.forEach((line, index) => {
            const trimmed = line.trim();
            const installsLockedPackages = trimmed === 'npm ci' || /^-\s+run:\s*npm ci\s*$/.test(trimmed);
            if (installsLockedPackages && !lines.slice(index + 1, index + 6).some((candidate) => candidate.includes('npm run security:dependencies'))) {
                issues.push(`${name} has npm ci without the complete dependency security gate.`);
            }
        });
    }

    const canonicalDocker = read(root, 'docker/php/Dockerfile');
    if (!canonicalDocker.includes(`ARG PHP_VERSION=${php}`)) issues.push('docker/php/Dockerfile PHP argument does not match Composer.');
    if (!canonicalDocker.includes(`ARG NODE_VERSION=${node}`) || !canonicalDocker.includes('FROM node:${NODE_VERSION}-alpine AS frontend')) {
        issues.push('docker/php/Dockerfile does not bind the frontend image to the pinned Node version.');
    }
    if (!canonicalDocker.includes(`ARG NPM_VERSION=${npm}`) || !canonicalDocker.includes('npm install --global npm@${NPM_VERSION}')) {
        issues.push('docker/php/Dockerfile does not activate the pinned npm version.');
    }
    const cutover = read(root, 'deployment/CUTOVER-CHECKLIST.md');
    for (const label of [`PHP ${php}`, 'Laravel 12.x', `Node.js ${node}`, `NPM ${npm}`]) {
        if (!cutover.includes(label)) issues.push(`Cutover checklist is missing runtime declaration: ${label}`);
    }

    if (installed) {
        const actualNode = process.version.replace(/^v/, '');
        if (actualNode !== node) issues.push(`Installed Node is ${actualNode}; expected ${node}`);
        try {
            const actualNpm = execFileSync('npm', ['--version'], { encoding: 'utf8' }).trim();
            if (actualNpm !== npm) issues.push(`Installed npm is ${actualNpm}; expected ${npm}`);
        } catch {
            issues.push('Installed npm version could not be determined.');
        }
        try {
            const actualPhp = execFileSync('php', ['-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'], { encoding: 'utf8' }).trim();
            if (actualPhp !== php) issues.push(`Installed PHP is ${actualPhp}; expected ${php}`);
        } catch {
            issues.push('Installed PHP version could not be determined.');
        }
    }

    return { status: issues.length === 0 ? 'PASS' : 'FAIL', expected, issues };
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : null;
if (invokedPath && invokedPath === fileURLToPath(import.meta.url)) {
    const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
    const report = verifyRuntimeMatrix(root, { installed: process.argv.includes('--installed') });
    process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
    process.exitCode = report.status === 'PASS' ? 0 : 1;
}
