#!/usr/bin/env node
/**
 * assert-vite-manifest.mjs — the build must produce every asset the app expects.
 *
 * WHY NODE AND NOT AN INLINE `node -e` IN ci.yml
 * ----------------------------------------------
 * The base commit checked the manifest with a ~20-line inline script embedded
 * in YAML. Two problems with that shape: it could not be run locally without
 * copy-pasting out of the workflow, and YAML line-continuations around its
 * quotes were the kind of thing that breaks during an unrelated edit. A real
 * file can be linted, tested and run by a developer before pushing.
 *
 * WHAT IT CHECKS
 *   1. public/build/manifest.json exists and parses as an object.
 *   2. Every entry point declared in vite.config.js produced an output file
 *      that exists on disk.
 *   3. No source entry point is an empty file (an empty CSS file "builds"
 *      successfully and produces an unstyled site).
 *   4. Every `imports`/`css` reference inside the manifest resolves on disk,
 *      so a chunk that is referenced but missing is caught here rather than by
 *      a 404 in the browser.
 *   5. With --require-build, a missing manifest is fatal (production); without
 *      it, a missing manifest only warns (developer running the check early).
 *
 * USAGE
 *   node scripts/ci/assert-vite-manifest.mjs [--require-build] [--json]
 */

import { readFileSync, existsSync, statSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import process from 'node:process';

const args = new Set(process.argv.slice(2));
const requireBuild = args.has('--require-build');
const asJson = args.has('--json');

const root = resolve(dirname(new URL(import.meta.url).pathname), '..', '..');
const manifestPath = resolve(root, 'public/build/manifest.json');
const viteConfigPath = resolve(root, 'vite.config.js');

/** @type {string[]} */
const errors = [];
/** @type {string[]} */
const notes = [];

function fail(message) {
  errors.push(message);
}

if (!existsSync(manifestPath)) {
  if (requireBuild) {
    fail('public/build/manifest.json does not exist. `npm run build` must run during the release.');
  } else {
    notes.push('manifest missing (allowed outside --require-build)');
  }
} else {
  let manifest;

  try {
    manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  } catch (error) {
    fail(`public/build/manifest.json is not valid JSON: ${error.message}`);
    manifest = null;
  }

  if (manifest) {
    const names = Object.keys(manifest);

    if (names.length === 0) {
      fail('public/build/manifest.json is empty.');
    } else {
      notes.push(`${names.length} manifest entries`);
    }

    // 2 + 4: every file referenced by the manifest exists.
    const referenced = new Set();

    for (const [name, entry] of Object.entries(manifest)) {
      if (!entry || typeof entry !== 'object') {
        fail(`manifest entry "${name}" is not an object.`);
        continue;
      }

      if (typeof entry.file === 'string') {
        referenced.add(entry.file);
      } else {
        fail(`manifest entry "${name}" has no "file".`);
      }

      for (const key of ['css', 'assets']) {
        for (const file of entry[key] ?? []) {
          referenced.add(file);
        }
      }

      for (const imported of entry.imports ?? []) {
        if (!manifest[imported]) {
          fail(`manifest entry "${name}" imports "${imported}", which is not in the manifest.`);
        }
      }
    }

    for (const file of referenced) {
      const path = resolve(root, 'public/build', file);

      if (!existsSync(path)) {
        fail(`manifest references "${file}" but public/build/${file} does not exist.`);
      } else if (statSync(path).size === 0 && /\.(css|js|mjs)$/.test(file)) {
        fail(`public/build/${file} is empty.`);
      }
    }
  }
}

// 2 + 3: every declared entry point built, and is not empty.
if (!existsSync(viteConfigPath)) {
  notes.push('vite.config.js missing; entry-point checks skipped');
} else {
  const config = readFileSync(viteConfigPath, 'utf8');
  const matches = [...config.matchAll(/['"`](resources\/(?:js|css)\/[^'"`\s]+)['"`]/g)].map((m) => m[1]);
  const entries = [...new Set(matches)];

  if (entries.length === 0) {
    fail('vite.config.js declares no resources/js or resources/css entry points.');
  }

  for (const entry of entries) {
    const sourcePath = resolve(root, entry);

    if (!existsSync(sourcePath)) {
      fail(`vite entry "${entry}" does not exist on disk.`);
      continue;
    }

    if (statSync(sourcePath).size === 0) {
      fail(`vite entry "${entry}" is an empty file.`);
    }

    if (existsSync(manifestPath)) {
      try {
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

        if (!manifest[entry]) {
          fail(`vite entry "${entry}" was not built into the manifest.`);
        }
      } catch {
        // Already reported above.
      }
    }
  }

  notes.push(`${entries.length} declared entry points checked`);
}

if (asJson) {
  process.stdout.write(`${JSON.stringify({ ok: errors.length === 0, errors, notes }, null, 2)}\n`);
} else {
  for (const note of notes) {
    process.stdout.write(`  ok  ${note}\n`);
  }

  for (const error of errors) {
    process.stdout.write(`::error::${error}\n`);
  }
}

process.exit(errors.length === 0 ? 0 : 1);
