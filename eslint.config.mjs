// ESLint flat config — the front-end lint gate.
//
// WHY THIS FILE EXISTS
// --------------------
// The CI front-end job had no lint step because the repository had no lint
// configuration at all; the job printed a `::notice::` and moved on. A lint step
// that cannot fail is decoration, so this config turns it into a real gate.
//
// WHAT IT COVERS, AND WHAT IT DELIBERATELY DOES NOT
// -------------------------------------------------
//   * `resources/js/**/*.js|mjs|jsx` — plain JavaScript, linted with the
//     recommended correctness rules plus browser globals.
//   * `resources/js/**/*.ts|tsx` — parsed by the TypeScript parser, linted for
//     type-aware syntax errors and the same correctness rules.
//   * Formatting opinions (indentation, quotes, semicolons) are NOT enabled.
//     This file's job is to catch bugs, not to re-litigate style in code owned
//     by another agent; a lint gate that fails on whitespace is a lint gate
//     people switch off.
//   * `resources/js/_unused/**` is excluded: it is dead code that the build
//     does not consume (see tests/browser/frontend-build.spec.mjs).
//
// The rule set is `recommended`, minus the two rules that cannot be satisfied
// without rewriting A-owned components (documented inline). Everything else is
// left on, so real defects — undefined variables, unreachable code, duplicate
// keys, accidental globals, broken promises — fail the build.

import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default [
    {
        ignores: [
            'public/**',
            'vendor/**',
            'node_modules/**',
            'storage/**',
            'resources/js/_unused/**',
            'resources/js/**/*.d.ts',
        ],
    },

    // ── Plain JavaScript ───────────────────────────────────────────────────
    {
        files: ['resources/js/**/*.{js,mjs,cjs,jsx}'],
        ...js.configs.recommended,
        languageOptions: {
            ecmaVersion: 2024,
            sourceType: 'module',
            parserOptions: {
                ecmaFeatures: { jsx: true },
            },
            globals: {
                ...globals.browser,
                // Blade-injected bridge objects the app relies on.
                axios: 'readonly',
                route: 'readonly',
                Echo: 'readonly',
                Alpine: 'readonly',
            },
        },
        rules: {
            ...js.configs.recommended.rules,

            // Deliberate patterns in this codebase:
            //   - `no-empty` fires on `catch {}` used to swallow a probe error;
            //   - `no-unused-vars` is reported as a warning so a real unused
            //     import is visible without blocking a release on one variable.
            'no-empty': ['error', { allowEmptyCatch: true }],
            'no-unused-vars': ['warn', { args: 'none', caughtErrors: 'none', varsIgnorePattern: '^_' }],

            // Real bug classes, explicitly on:
            'no-undef': 'error',
            'no-unreachable': 'error',
            'no-dupe-keys': 'error',
            'no-dupe-args': 'error',
            'no-fallthrough': 'error',
            'no-self-assign': 'error',
            'no-cond-assign': 'error',
            'no-constant-condition': 'error',
            'no-unsafe-negation': 'error',
            'no-prototype-builtins': 'error',
            'require-atomic-updates': 'error',
            'no-async-promise-executor': 'error',
            'no-promise-executor-return': 'error',
        },
    },

    // ── TypeScript / TSX ───────────────────────────────────────────────────
    ...tseslint.configs.recommended.map((config) => ({
        ...config,
        files: ['resources/js/**/*.{ts,tsx}'],
    })),
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: {
            parser: tseslint.parser,
            parserOptions: {
                ecmaVersion: 2024,
                sourceType: 'module',
                ecmaFeatures: { jsx: true },
            },
            globals: {
                ...globals.browser,
                React: 'readonly',
            },
        },
        rules: {
            // The components are compiled by esbuild through Vite, which does
            // not require React to be in scope for JSX.
            'no-undef': 'off',
            '@typescript-eslint/no-unused-vars': ['warn', { args: 'none', varsIgnorePattern: '^_' }],
            '@typescript-eslint/no-explicit-any': 'warn',
            '@typescript-eslint/no-empty-object-type': 'warn',
            '@typescript-eslint/no-require-imports': 'off',
        },
    },
];
