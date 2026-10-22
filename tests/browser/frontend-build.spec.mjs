// Actual release CSS on synthetic DOM. This is not a provider or financial integration test.
import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { captureMatrix, readBuildCss } from './frontend-build.helpers.mjs';

function visibleShadows(value) {
    // V4 adds zero-size bookkeeping shadows. Remove ONLY geometrically zero-size
    // shadows; preserve every visible color, offset, blur and spread, including rings.
    const pieces = value.match(/(?:[^,(]+|\([^)]*\))+/g) ?? [];
    return pieces.map(item => item.trim()).filter(item => !/ 0px 0px 0px 0px$/.test(item)).join(', ') || 'none';
}

function normalize(matrix) {
    return Object.fromEntries(Object.entries(matrix).map(([key, properties]) => [key,
        Object.fromEntries(Object.entries(properties).map(([property, value]) => [property,
            property === 'boxShadow' ? visibleShadows(value) : value]))]));
}

test('built utilities preserve measured legacy theme, focus, blur and responsive semantics', async ({ page }, info) => {
    const reference = JSON.parse(fs.readFileSync('tests/browser/fixtures/tailwind-v3-compat.json', 'utf8'));
    const kind = info.project.name.includes('mobile') ? 'mobile' : 'desktop';
    const actual = await captureMatrix(page, readBuildCss());
    expect(normalize(actual)).toEqual(normalize(reference.matrices[kind]));
});

test('every registered entry and manifest import resolves to a nonempty release artifact', () => {
    const root = path.resolve('public/build');
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'manifest.json'), 'utf8'));
    const config = fs.readFileSync('vite.config.js', 'utf8');
    const inputs = [...config.matchAll(/['"](resources\/(?:js|css)\/[^'"]+)['"]/g)].map(match => match[1]);
    expect(new Set(inputs).size).toBeGreaterThan(90);
    for (const input of new Set(inputs)) {
        expect(manifest[input], input).toBeDefined();
        expect(fs.statSync(input).size, input).toBeGreaterThan(0);
    }
    for (const [name, record] of Object.entries(manifest)) {
        for (const file of [record.file, ...(record.css ?? []), ...(record.assets ?? [])]) {
            const target = path.resolve(root, file);
            expect(target.startsWith(root + path.sep), name).toBeTruthy();
            expect(fs.statSync(target).size, file).toBeGreaterThan(0);
        }
        for (const imported of [...(record.imports ?? []), ...(record.dynamicImports ?? [])]) {
            expect(manifest[imported], imported).toBeDefined();
        }
    }
});
