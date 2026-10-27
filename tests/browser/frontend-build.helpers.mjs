// Built-CSS fixtures only: no Laravel, provider, authentication or financial requests.
import fs from 'node:fs';
import path from 'node:path';

export const vectors = [
    ['rounded', 'rounded', ['borderRadius']],
    ['shadow', 'shadow', ['boxShadow']],
    ['shadow-sm', 'shadow-sm', ['boxShadow']],
    ['shadow-lg', 'shadow-lg', ['boxShadow']],
    ['blur', 'blur', ['filter']],
    ['backdrop-blur', 'backdrop-blur', ['backdropFilter']],
    ['flex-grow', 'flex-grow', ['flexGrow']],
    ['border', 'border', ['borderTopWidth', 'borderTopStyle', 'borderTopColor']],
    ['brand', 'bg-brand-500 text-white rounded-lg', ['backgroundColor', 'color', 'borderRadius']],
    ['panel', 'bg-slate-900 text-gray-300 border border-gray-700 rounded-lg shadow-lg', ['backgroundColor', 'color', 'borderTopColor', 'borderRadius', 'boxShadow']],
    // Component/utility combinations catch accidental reversal of the legacy import cascade.
    ['component-panel', 'tl-glass-panel bg-slate-900 text-gray-300 border border-gray-700 rounded-lg shadow-lg', ['backgroundColor', 'color', 'borderTopColor', 'borderRadius', 'boxShadow']],
    ['component-input', 'tl-glass-input bg-slate-900 text-gray-300 border-gray-700 rounded-lg', ['backgroundColor', 'color', 'borderTopColor', 'borderRadius']],
    ['component-elevated', 'tl-glass-panel tl-glass-panel--elevated bg-slate-900 rounded-lg shadow-lg', ['backgroundColor', 'borderRadius', 'boxShadow']],
    ['type', 'text-sm font-medium', ['fontSize', 'lineHeight', 'fontWeight']],
    ['focus-outline', 'focus:outline-none', ['outlineColor', 'outlineStyle', 'outlineWidth']],
    ['focus-ring', 'focus:ring-2 focus:ring-emerald-500', ['boxShadow']],
    ['hover-brand', 'hover:bg-brand-600', ['backgroundColor']],
    ['responsive', 'hidden md:flex', ['display']],
];

export function readBuildCss(root = process.cwd()) {
    const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
    const name = manifest['resources/css/app.css']?.file;
    if (!name || !name.endsWith('.css')) throw new Error('The application CSS entry must be built.');
    return fs.readFileSync(path.join(root, 'public/build', name), 'utf8');
}

export async function captureMatrix(page, css) {
    await page.route('http://frontend-build.test/', route => route.fulfill({
        contentType: 'text/html',
        body: '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main>' + vectors.map(([id, classes]) =>
            `<button id="${id}" class="${classes}" style="width:120px;height:48px;margin:16px${id.startsWith('component-') ? ';transition:none!important;animation:none!important' : ''}">Synthetic ${id}</button>`).join('') + '</main></body></html>',
    }));
    await page.goto('http://frontend-build.test/');
    await page.addStyleTag({ content: css });
    const out = {};
    for (const [id, , properties] of vectors) {
        const element = page.locator('#' + id);
        if (id.startsWith('focus-')) await element.focus();
        if (id.startsWith('hover-')) await element.hover();
        out[id] = await element.evaluate((node, names) => {
            const style = getComputedStyle(node);
            return Object.fromEntries(names.map(name => [name, style[name]]));
        }, properties);
    }
    return out;
}
