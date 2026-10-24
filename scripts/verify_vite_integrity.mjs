import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

function walk(directory, suffix, output = []) {
    if (!fs.existsSync(directory)) return output;
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const absolute = path.join(directory, entry.name);
        if (entry.isDirectory()) walk(absolute, suffix, output);
        else if (entry.name.endsWith(suffix)) output.push(absolute);
    }
    return output;
}

function withoutNonRenderedBlade(source) {
    return source
        .replace(/@verbatim[\s\S]*?@endverbatim/g, '')
        .replace(/@php[\s\S]*?@endphp/g, '')
        .replace(/\{\{--[\s\S]*?--\}\}/g, '')
        .replace(/<!--[\s\S]*?-->/g, '');
}

export function bladeViteDirectives(source) {
    const references = [];
    const directives = [...withoutNonRenderedBlade(source).matchAll(/@vite\s*\(([\s\S]*?)\)/g)];
    for (const directive of directives) {
        const assets = [...directive[1].matchAll(/['"](resources\/[^'"]+)['"]/g)].map((match) => match[1]);
        if (assets.length) references.push({ assets, dynamic: false });
    }
    return references;
}

function withoutJavaScriptComments(source) {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^[ \t]*\/\/[^\n]*$/gm, '');
}

export function viteInputAssets(source) {
    return [...withoutJavaScriptComments(source).matchAll(/['"](resources\/[^'"]+)['"]/g)].map((match) => match[1]);
}

function checkManifest(repositoryRoot, references, issues) {
    const manifestPath = path.join(repositoryRoot, 'public/build/manifest.json');
    if (!fs.existsSync(manifestPath)) {
        issues.push('Built manifest is missing. Run npm ci && npm run build.');
        return { entries: null, emitted: null };
    }
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    const checked = new Set();
    const emitted = new Set();
    const visit = (key) => {
        if (checked.has(key)) return;
        checked.add(key);
        const record = manifest[key];
        if (!record) {
            issues.push(`Built manifest has no record for ${key}.`);
            return;
        }
        const files = [record.file, ...(record.css || [])];
        for (const file of files) {
            if (!file || emitted.has(file)) continue;
            emitted.add(file);
            if (!fs.existsSync(path.join(repositoryRoot, 'public/build', file))) {
                issues.push(`Built asset is missing: public/build/${file}.`);
            }
        }
        for (const imported of [...(record.imports || []), ...(record.dynamicImports || [])]) visit(imported);
    };
    for (const reference of references) visit(reference);
    return { entries: checked.size, emitted: emitted.size };
}

export function verifyViteIntegrity(repositoryRoot = root, { requireBuild = false } = {}) {
    const issues = [];
    const views = walk(path.join(repositoryRoot, 'resources/views'), '.blade.php');
    const references = [];
    let directives = 0;
    for (const view of views) {
        const found = bladeViteDirectives(fs.readFileSync(view, 'utf8'));
        directives += found.length;
        for (const directive of found) {
            for (const asset of directive.assets) references.push(asset);
        }
    }
    const unique = [...new Set(references)].sort();
    for (const reference of unique) {
        if (!fs.existsSync(path.join(repositoryRoot, reference))) issues.push(`Blade references missing source ${reference}.`);
    }

    const config = fs.readFileSync(path.join(repositoryRoot, 'vite.config.js'), 'utf8');
    const inputs = new Set(viteInputAssets(config));
    for (const reference of unique) {
        if (!inputs.has(reference)) issues.push(`Blade reference is not a registered Vite input: ${reference}.`);
    }
    const manifest = requireBuild ? checkManifest(repositoryRoot, unique, issues) : { entries: null, emitted: null };

    return {
        status: issues.length ? 'FAIL' : 'PASS',
        views_scanned: views.length,
        vite_directives: directives,
        unique_blade_references: unique.length,
        registered_inputs: inputs.size,
        manifest_entries: manifest.entries,
        emitted_files_checked: manifest.emitted,
        issues,
    };
}

const invokedPath = process.argv[1] ? path.resolve(process.argv[1]) : null;
if (invokedPath && invokedPath === fileURLToPath(import.meta.url)) {
    const requireBuild = process.argv.includes('--require-build');
    const report = verifyViteIntegrity(root, { requireBuild });
    const output = path.join(root, 'production-readiness/evidence/vite-integrity.json');
    fs.mkdirSync(path.dirname(output), { recursive: true });
    fs.writeFileSync(output, `${JSON.stringify(report, null, 2)}\n`);
    console.log(JSON.stringify(report, null, 2));
    if (report.status !== 'PASS') process.exitCode = 1;
}
