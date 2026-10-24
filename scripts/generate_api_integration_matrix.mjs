#!/usr/bin/env node
/**
 * Generate the frontend -> route -> controller -> persistence/provider -> test
 * inventory from the current checkout. No route is called "live" by this
 * static inventory. PASS_REFERENCED_SUITE requires an explicit, successful
 * test-run flag and means only that a directly referenced suite passed.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync, mkdirSync, writeFileSync } from 'node:fs';
import { extname, join, relative, resolve, sep } from 'node:path';
import { extractFetchCalls, normalizePath, routeMatchesUrl } from './lib/frontend-call-scan.mjs';

const root = resolve(new URL('..', import.meta.url).pathname);
const args = process.argv.slice(2);
const option = (name, fallback = null) => {
    const prefix = `--${name}=`;
    const found = args.find((arg) => arg.startsWith(prefix));
    return found ? found.slice(prefix.length) : fallback;
};
const testRunStatus = option('test-run-status', 'not-run');
if (!['passed', 'failed', 'not-run'].includes(testRunStatus)) {
    throw new Error('--test-run-status must be passed, failed, or not-run');
}
const evidenceCommand = option('evidence-command', 'php artisan test');
const outputDirectory = resolve(root, option('output', 'docs/production-readiness'));
const routeJsonPath = option('route-json');

const walk = (directory, predicate = () => true) => {
    const files = [];
    const visit = (current) => {
        for (const entry of readdirSync(current, { withFileTypes: true })) {
            if (['vendor', 'node_modules', '.git', 'public', 'storage', 'deliverables', '_unused'].includes(entry.name)) continue;
            const path = join(current, entry.name);
            if (entry.isDirectory()) visit(path);
            else if (predicate(path)) files.push(path);
        }
    };
    visit(directory);
    return files.sort();
};
const read = (path) => readFileSync(path, 'utf8');
const repoPath = (path) => relative(root, path).split(sep).join('/');
const unique = (values) => [...new Set(values.filter(Boolean))].sort();
const oneLine = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();
const md = (value) => oneLine(value || '—').replaceAll('|', '\\|');

const routeOutput = routeJsonPath
    ? read(resolve(root, routeJsonPath))
    : execFileSync('php', ['artisan', 'route:list', '--json', '--except-vendor'], {
        cwd: root,
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
        env: {
            ...process.env,
            APP_ENV: process.env.APP_ENV || 'testing',
            DB_CONNECTION: process.env.DB_CONNECTION || 'sqlite',
            DB_DATABASE: process.env.DB_DATABASE || join(root, 'database/thai_lottery_test'),
            CACHE_STORE: process.env.CACHE_STORE || 'array',
            SESSION_DRIVER: process.env.SESSION_DRIVER || 'array',
            QUEUE_CONNECTION: process.env.QUEUE_CONNECTION || 'sync',
        },
    });
const routes = JSON.parse(routeOutput).map((route) => ({
    ...route,
    methods: String(route.method || '').split('|').filter(Boolean),
    middleware: String(route.middleware || '').split('\n').filter(Boolean),
}));

const methodMatches = (route, method) => !method || route.methods.includes(method.toUpperCase())
    || (method.toUpperCase() === 'GET' && route.methods.includes('HEAD'));

const callers = [];
const sourceFiles = [
    ...walk(join(root, 'resources'), (path) => ['.js', '.jsx', '.ts', '.tsx', '.vue', '.php'].includes(extname(path))),
];
const addCaller = (file, component, method, target, kind) => {
    const cleanTarget = String(target || '').trim();
    if (!cleanTarget || cleanTarget.startsWith('data:') || cleanTarget.startsWith('mailto:') || cleanTarget.startsWith('tel:')) return;
    callers.push({
        frontend_file: repoPath(file),
        component,
        method: method ? method.toUpperCase() : null,
        target: cleanTarget,
        kind,
    });
};
for (const file of sourceFiles) {
    const text = read(file);
    const component = file.endsWith('.blade.php') ? 'Blade' : 'Frontend module';
    for (const call of extractFetchCalls(text)) {
        addCaller(file, component, call.method, call.target, 'fetch');
    }
    for (const match of text.matchAll(/\baxios\.(get|post|put|patch|delete|head)\(\s*(['"`])([^'"`]+)\2/gisu)) {
        addCaller(file, component, match[1], match[3], 'axios');
    }
    for (const match of text.matchAll(/\b(?:\$\.ajax|ajax)\(\s*\{([\s\S]{0,1000}?)\}\s*\)/gisu)) {
        const block = match[1];
        const target = block.match(/\burl\s*:\s*(['"`])([^'"`]+)\1/u)?.[2];
        const method = block.match(/\b(?:method|type)\s*:\s*['"]([A-Z]+)['"]/iu)?.[1] || 'GET';
        if (target) addCaller(file, component, method, target, 'ajax');
    }
    for (const match of text.matchAll(/\.open\(\s*['"]([A-Z]+)['"]\s*,\s*(['"`])([^'"`]+)\2/gisu)) {
        addCaller(file, component, match[1], match[3], 'xhr');
    }
    for (const match of text.matchAll(/<form\b[^>]*>/gisu)) {
        const tag = match[0];
        const action = tag.match(/\baction\s*=\s*(['"])(.*?)\1/isu)?.[2] || '';
        const nextForm = text.slice(match.index + match[0].length).search(/<form\b|<\/form>/iu);
        const following = nextForm < 0
            ? text.slice(match.index + match[0].length)
            : text.slice(match.index + match[0].length, match.index + match[0].length + nextForm);
        const spoofed = following.match(/@method\(\s*['"]([A-Z]+)['"]\s*\)/iu)?.[1];
        const method = spoofed || tag.match(/\bmethod\s*=\s*(['"])([A-Z]+)\1/iu)?.[2] || 'GET';
        const named = action.match(/route\(\s*['"]([A-Za-z0-9_.-]+)['"]/u)?.[1];
        if (named) addCaller(file, 'Blade form', method, `route:${named}`, 'form-route');
        else if (action && !action.startsWith('#')) addCaller(file, 'Blade form', method, action, 'form-url');
    }
    for (const match of text.matchAll(/\broute\(\s*(['"])([A-Za-z0-9_.-]+)\1/gu)) {
        addCaller(file, component, null, `route:${match[2]}`, 'named-route');
    }
}

const routeByName = new Map(routes.filter((route) => route.name).map((route) => [route.name, route]));
const unmatched = [];
for (const caller of callers) {
    let matches = [];
    if (caller.target.startsWith('route:')) {
        const name = caller.target.slice(6);
        const route = routeByName.get(name);
        if (route && methodMatches(route, caller.method)) matches = [route];
        caller.url = route ? `/${route.uri}` : null;
        caller.route_name = name;
    } else {
        caller.url = caller.target;
        matches = routes.filter((route) => routeMatchesUrl(route.uri, caller.target) && methodMatches(route, caller.method));
        caller.route_name = matches.length === 1 ? matches[0].name || null : null;
    }
    // Ignore absolute third-party links; only application calls belong in this matrix.
    if (/^https?:\/\//iu.test(caller.target) && !caller.target.includes('localhost') && !caller.target.includes('127.0.0.1')) {
        caller.resolution = 'EXTERNAL_URL';
        continue;
    }
    caller.matched_routes = matches.map((route) => route.name || `${route.method} /${route.uri}`);
    caller.resolution = matches.length === 1 ? 'MATCHED' : matches.length > 1 ? 'AMBIGUOUS' : 'UNMATCHED';
    if (matches.length !== 1) unmatched.push(caller);
}

const controllerCache = new Map();
const controllerMetadata = (route) => {
    const fallback = {
        controller_file: route.path || null,
        validation: 'Closure or framework action; inspect route definition',
        services: [],
        persistence: [],
        response: 'Closure/framework response',
        errors: 'See route middleware and action',
        idempotency: 'Not evidenced by static scan',
    };
    const action = String(route.action || '');
    const className = action.split('@')[0];
    if (!className.startsWith('App\\')) return fallback;
    const method = action.includes('@') ? action.split('@')[1] : '__invoke';
    const file = join(root, className.replace(/^App\\/, 'app/').replaceAll('\\', '/')) + '.php';
    if (controllerCache.has(file)) return controllerCache.get(file).get(method) || fallback;
    let text;
    try { text = read(file); } catch { return fallback; }
    const metadata = new Map();
    const functionPattern = /function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)[\s\S]*?\{/gu;
    for (const match of text.matchAll(functionPattern)) {
        const functionName = match[1];
        const openBrace = match.index + match[0].length - 1;
        let depth = 1;
        let cursor = openBrace + 1;
        while (cursor < text.length && depth > 0) {
            if (text[cursor] === '{') depth++;
            else if (text[cursor] === '}') depth--;
            cursor++;
        }
        const body = text.slice(openBrace + 1, cursor - 1);
        const requests = unique([...match[2].matchAll(/(?:^|[,\s])(?:\\?[A-Za-z_][A-Za-z0-9_]*\\)*([A-Za-z_][A-Za-z0-9_]*Request)\s+\$/gu)].map((item) => item[1]));
        const services = unique([...body.matchAll(/\b([A-Z][A-Za-z0-9_]*(?:Service|Repository|Manager|Provider|Gateway|Guard|Policy|Action))::class/gu)].map((item) => item[1]));
        const models = unique([...body.matchAll(/\b([A-Z][A-Za-z0-9_]*)::(?:query|create|find|findOrFail|where|with|withTrashed|firstOrCreate|updateOrCreate)/gu)].map((item) => item[1]));
        const responses = [];
        for (const item of body.matchAll(/\bview\(\s*['"]([^'"]+)['"]/gu)) responses.push(`view:${item[1]}`);
        if (/ApiResponse::success/u.test(body)) responses.push('ApiResponse::success');
        if (/ApiResponse::error/u.test(body)) responses.push('ApiResponse::error');
        if (/response\(\)->json|new\s+JsonResponse|return\s+new\s+[A-Za-z_]*Resource/u.test(body)) responses.push('JSON/resource response');
        if (/redirect\(|->redirect|redirect\s*\(/u.test(body)) responses.push('redirect');
        const statuses = unique([...body.matchAll(/(?:abort(?:_if|_unless)?\(|status\s*:\s*|setStatusCode\()\s*(\d{3})/gu)].map((item) => item[1]));
        const errors = statuses.length ? `Explicit statuses: ${statuses.join(', ')}` : requests.length ? 'Validation/authentication errors via request/middleware' : 'No exhaustive static error inventory';
        metadata.set(functionName, {
            controller_file: repoPath(file),
            validation: requests.length ? requests.join(', ') : 'No action FormRequest type-hint; inspect inline validation',
            services,
            persistence: models.map((model) => `Model:${model}`),
            response: responses.length ? unique(responses).join('; ') : 'Laravel action response; schema not statically proven',
            errors,
            idempotency: /idempoten/iu.test(body) ? 'Idempotency referenced by action' : 'Not evidenced by static action scan',
        });
    }
    controllerCache.set(file, metadata);
    return metadata.get(method) || fallback;
};

const testFiles = walk(join(root, 'tests'), (path) => extname(path) === '.php' && !path.includes(`${sep}Support${sep}`));
const testTexts = testFiles.map((path) => ({ path: repoPath(path), text: read(path) }));
const routeTestReferences = (route) => {
    const refs = [];
    for (const test of testTexts) {
        let referenced = false;
        if (route.name) {
            const escaped = route.name.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&');
            referenced = new RegExp(`route\\(\\s*['"]${escaped}['"]`, 'u').test(test.text);
        }
        if (!referenced) {
            const uri = String(route.uri || '').replace(/^\/+/, '');
            const prefix = uri.split('{')[0].replace(/\/$/u, '');
            if (prefix && !uri.includes('{')) {
                referenced = test.text.includes(`'/${uri}'`) || test.text.includes(`"/${uri}"`) || test.text.includes(`\`/${uri}\``);
            } else if (prefix.length >= 4) {
                referenced = test.text.includes(`'/${prefix}`) || test.text.includes(`"/${prefix}`) || test.text.includes(`\`/${prefix}`);
            }
        }
        if (referenced) refs.push(test.path);
    }
    return refs;
};

const endpoints = routes.map((route) => {
    const metadata = controllerMetadata(route);
    const routeCallers = callers.filter((caller) => caller.matched_routes?.includes(route.name || `${route.method} /${route.uri}`));
    const tests = routeTestReferences(route);
    let observed = 'MISSING_TEST_EVIDENCE';
    if (testRunStatus === 'failed') observed = tests.length ? 'FAIL_REFERENCED_SUITE' : 'FAIL_SUITE_NO_DIRECT_REFERENCE';
    else if (testRunStatus === 'not-run') observed = tests.length ? 'NOT_RUN_TEST_REFERENCE_PRESENT' : 'NOT_RUN_NO_DIRECT_REFERENCE';
    else if (tests.length) observed = 'PASS_REFERENCED_SUITE';
    const callerFiles = unique(routeCallers.map((caller) => caller.frontend_file));
    return {
        feature_page: callerFiles.length ? callerFiles.join(', ') : `/${String(route.uri).split('/')[0] || 'root'}`,
        frontend_files: callerFiles,
        caller_methods: unique(routeCallers.map((caller) => caller.method)),
        http_method: route.method,
        url: `/${route.uri}`,
        route_name: route.name || null,
        controller_action: route.action,
        middleware_auth_role: route.middleware,
        form_request_validation: metadata.validation,
        service_repository: metadata.services,
        database_table_provider: metadata.persistence.length ? metadata.persistence : ['NOT_STATICALLY_RESOLVED'],
        response_schema_status: metadata.response,
        error_states: metadata.errors,
        idempotency_retry: unique([
            metadata.idempotency,
            ...route.middleware.filter((item) => /throttle/iu.test(item)).map((item) => `Throttle:${item}`),
        ]),
        test_files: tests,
        observed_status: observed,
        evidence_command: tests.length ? evidenceCommand : null,
        controller_file: metadata.controller_file,
        caller_count: routeCallers.length,
    };
});

const sortedUnmatched = unmatched.filter((caller) => caller.resolution !== 'EXTERNAL_URL');
const totals = {
    discovered_endpoints: endpoints.length,
    api_endpoints: endpoints.filter((endpoint) => endpoint.url.startsWith('/api/')).length,
    web_endpoints: endpoints.filter((endpoint) => !endpoint.url.startsWith('/api/')).length,
    discovered_frontend_callers: callers.filter((caller) => caller.resolution !== 'EXTERNAL_URL').length,
    matched_frontend_callers: callers.filter((caller) => caller.resolution === 'MATCHED').length,
    broken_or_ambiguous_callers: sortedUnmatched.length,
    endpoints_with_direct_test_reference: endpoints.filter((endpoint) => endpoint.test_files.length).length,
    endpoints_without_direct_test_reference: endpoints.filter((endpoint) => !endpoint.test_files.length).length,
    pass_referenced_suite: endpoints.filter((endpoint) => endpoint.observed_status === 'PASS_REFERENCED_SUITE').length,
    failed: endpoints.filter((endpoint) => endpoint.observed_status.startsWith('FAIL')).length + sortedUnmatched.length,
    blocked_external: 0,
};
const document = {
    schema_version: 1,
    generated_at: new Date().toISOString(),
    generation: {
        command: 'node scripts/generate_api_integration_matrix.mjs',
        route_source: routeJsonPath || 'php artisan route:list --json --except-vendor',
        frontend_roots: ['resources'],
        test_roots: ['tests'],
        test_run_status: testRunStatus,
        evidence_command: evidenceCommand,
        semantics: 'Static wiring inventory. PASS_REFERENCED_SUITE is not live-provider, official-data, browser, or production verification.',
    },
    totals,
    endpoints,
    broken_or_ambiguous_callers: sortedUnmatched,
};
mkdirSync(outputDirectory, { recursive: true });
writeFileSync(join(outputDirectory, 'api-integration-matrix.json'), `${JSON.stringify(document, null, 2)}\n`);

const lines = [
    '# API integration matrix',
    '',
    '> Generated from the actual Laravel route list and current frontend/test sources. Static registration is not live connectivity. `PASS_REFERENCED_SUITE` means the explicitly supplied suite run passed and the file directly references the route; it does not prove exhaustive contract coverage.',
    '',
    '## Computed totals',
    '',
    '| Metric | Total |',
    '|---|---:|',
    ...Object.entries(totals).map(([key, value]) => `| ${key.replaceAll('_', ' ')} | ${value} |`),
    '',
    '## Endpoint contracts',
    '',
    '| Feature/Page | Frontend file/component | HTTP method | URL | Route name | Controller/action | Middleware/auth/role | FormRequest/validation | Service/repository | Database table/provider | Response schema/status | Error states | Idempotency/retry | Feature/integration test | Observed status | Evidence command |',
    '|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|',
    ...endpoints.map((endpoint) => `| ${md(endpoint.feature_page)} | ${md(endpoint.frontend_files.join(', '))} | ${md(endpoint.http_method)} | ${md(endpoint.url)} | ${md(endpoint.route_name)} | ${md(endpoint.controller_action)} | ${md(endpoint.middleware_auth_role.join(', '))} | ${md(endpoint.form_request_validation)} | ${md(endpoint.service_repository.join(', '))} | ${md(endpoint.database_table_provider.join(', '))} | ${md(endpoint.response_schema_status)} | ${md(endpoint.error_states)} | ${md(endpoint.idempotency_retry.join(', '))} | ${md(endpoint.test_files.join(', '))} | ${md(endpoint.observed_status)} | ${md(endpoint.evidence_command)} |`),
    '',
    '## Broken or ambiguous frontend callers',
    '',
    sortedUnmatched.length === 0
        ? 'No unmatched or ambiguous statically extracted application callers.'
        : '| Frontend file | Kind | Method | Target | Resolution |\n|---|---|---|---|---|\n' + sortedUnmatched.map((caller) => `| ${md(caller.frontend_file)} | ${md(caller.kind)} | ${md(caller.method)} | ${md(caller.target)} | ${md(caller.resolution)} |`).join('\n'),
    '',
    '## External gates',
    '',
    'Live payment providers, authorized official lottery data, legal/operator approval, and production infrastructure are not certified by this inventory. See `GO-LIVE-GATES.md`; external blocker totals are deliberately not inferred from route names.',
    '',
];
writeFileSync(join(outputDirectory, 'api-integration-matrix.md'), lines.join('\n'));
console.log(JSON.stringify({ json: repoPath(join(outputDirectory, 'api-integration-matrix.json')), markdown: repoPath(join(outputDirectory, 'api-integration-matrix.md')), totals }, null, 2));
if (sortedUnmatched.length) process.exitCode = 2;
