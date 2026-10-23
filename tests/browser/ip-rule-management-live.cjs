"use strict";
// Real HTTP + real credentials/cookies/CSRF + real application/db. No route mocking or actingAs.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { execFileSync } = require("node:child_process");
let chromium;
try {
    ({ chromium } = require("playwright"));
} catch {
    ({ chromium } = require("@playwright/test"));
}
const repo = path.resolve(__dirname, "../..");
const fixture = process.env.IP_RULE_BROWSER_ROOT;
const base = process.env.IP_RULE_BROWSER_URL || "http://127.0.0.1:8091";
assert.match(base, /^http:\/\/127\.0\.0\.1:\d+$/);
assert.equal(process.env.IP_RULE_BROWSER_TEST_ONLY, "1");
assert.ok(
    fixture && /^ip-rule-live-[a-f0-9]{16}$/.test(path.basename(fixture)),
);
assert.equal(
    fs.realpathSync(fixture),
    path.join(
        fs.realpathSync(path.join(repo, "storage/framework/testing")),
        path.basename(fixture),
    ),
);
const meta = JSON.parse(
    fs.readFileSync(path.join(fixture, "fixture.json"), "utf8"),
);
assert.equal(meta.ready, true);
const fixtureCommand = (mode) =>
    execFileSync(
        process.env.PHP_BINARY || "php",
        ["tests/browser/Support/ip-rule-live.php", mode],
        {
            cwd: repo,
            env: {
                ...process.env,
                IP_RULE_BROWSER_ROOT: fixture,
                IP_RULE_BROWSER_TEST_ONLY: "1",
            },
            encoding: "utf8",
        },
    );
const inspect = () => JSON.parse(fixtureCommand("inspect"));
const csrfFrom = (html) => {
    const match = html.match(
        /<meta\b(?=[^>]*name=["']csrf-token["'])[^>]*content=["']([^"']+)/i,
    );
    assert.ok(
        match,
        "Real login response must include the session CSRF token.",
    );
    return match[1];
};
const rulesUrl = base + "/admin/ip-access/rules";
let passed = 0;
const pass = (name) => {
    passed++;
    console.log("PASS: " + name);
};
async function login(context, actor, testBadPassword = false) {
    const response = await context.request.get(base + "/admin/login");
    assert.equal(response.status(), 200);
    const token = csrfFrom(await response.text());
    if (testBadPassword) {
        const bad = await context.request.post(base + "/login", {
            form: {
                login: actor.email,
                password: "DeliberatelyWrong!9",
                _token: token,
            },
            headers: { Accept: "application/json" },
            maxRedirects: 0,
        });
        assert.equal(bad.status(), 422);
        pass("real credential endpoint rejects an incorrect password");
    }
    const result = await context.request.post(base + "/login", {
        form: { login: actor.email, password: meta.password, _token: token },
        headers: { Accept: "application/json" },
        maxRedirects: 0,
    });
    assert.ok(
        [302, 303].includes(result.status()),
        "Real credential POST should authenticate then redirect.",
    );
    assert.ok(
        (await context.cookies()).some(
            (cookie) =>
                cookie.name === "ip_rule_browser_session" && cookie.httpOnly,
        ),
    );
}
(async () => {
    const initial = inspect();
    assert.deepEqual(
        initial.rules,
        [],
        "Prepare a NEW fixture before each run; do not reuse mutated fixtures.",
    );
    assert.deepEqual(initial.audits, []);
    const browser = await chromium.launch({ headless: true });
    let auditOffline = false;
    try {
        const context = await browser.newContext();
        const guest = await context.request.get(rulesUrl + "/manage", {
            headers: { Accept: "application/json" },
            maxRedirects: 0,
        });
        assert.equal(guest.status(), 401);
        pass("guest cannot load the real management endpoint");
        await login(context, meta.admin, true);
        const page = await context.newPage();
        const pageErrors = [];
        page.on("pageerror", (error) => pageErrors.push(error.message));
        const response = await page.goto(rulesUrl + "/manage");
        assert.equal(response.status(), 200);
        const idle = () =>
            page.waitForFunction(
                () =>
                    document
                        .getElementById("ip-rule-screen")
                        .getAttribute("aria-busy") === "false",
            );
        await idle();
        assert.equal(
            await page.locator("#database-state").textContent(),
            "OFF",
        );
        assert.match(
            await page.locator("#rule-rows").innerText(),
            /No rules on this page/,
        );
        pass("real login cookie opens the screen and empty database inventory");
        const token = await page
            .locator('meta[name="csrf-token"]')
            .getAttribute("content");
        const missingCsrf = await context.request.post(rulesUrl, {
            data: { ip_range: "192.0.2.1", reason_code: "TEST" },
            headers: { Accept: "application/json" },
        });
        assert.equal(missingCsrf.status(), 419);
        assert.equal(inspect().rules.length, 0);
        pass(
            "real web CSRF middleware rejects a cookie-authenticated mutation without token",
        );
        await page.locator("#ip-range").fill("::ffff:192.0.2.0/120");
        await page.locator("#create-reason").fill("LIVE_BROWSER_TEST");
        const createdResponse = page.waitForResponse(
            (r) => r.url() === rulesUrl && r.request().method() === "POST",
        );
        await page.locator("#create-button").click();
        const created = await createdResponse;
        assert.equal(created.status(), 201);
        const ruleId = (await created.json()).data.id;
        await idle();
        let state = inspect();
        assert.equal(state.rules.length, 1);
        assert.equal(state.rules[0].ip_range, "192.0.2.0/24");
        assert.equal(state.rules[0].created_by, meta.admin.id);
        assert.equal(state.audits.length, 1);
        assert.equal(state.audits[0].action, "create");
        assert.equal(state.audits[0].user_id, meta.admin.id);
        assert.equal(state.mutex_version, 1);
        assert.equal(state.database_policy_enabled, false);
        pass(
            "browser form creates a normalized real row, correct actor audit and capacity token",
        );
        const createKey = created.request().headers()["idempotency-key"];
        assert.match(createKey, /^[a-f0-9]{32}$/);
        const createRetry = await context.request.post(rulesUrl, {
            data: {
                ip_range: "::ffff:192.0.2.0/120",
                reason_code: "LIVE_BROWSER_TEST",
                starts_at_epoch: null,
                expires_at_epoch: null,
            },
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": token,
                "Idempotency-Key": createKey,
            },
        });
        assert.equal(createRetry.status(), 200);
        assert.equal(createRetry.headers()["idempotency-replayed"], "true");
        assert.equal((await createRetry.json()).data.id, ruleId);
        assert.equal(inspect().rules.length, 1);
        assert.equal(inspect().audits.length, 1);
        assert.equal(inspect().mutex_version, 1);
        pass(
            "real keyed create retry reuses the rule without another audit or reservation",
        );
        fixtureCommand("audit-offline");
        auditOffline = true;
        await page.locator("#ip-range").fill("192.0.2.3");
        await page.locator("#create-reason").fill("OUTAGE_TEST");
        const failedResponse = page.waitForResponse(
            (r) => r.url() === rulesUrl && r.request().method() === "POST",
        );
        await page.locator("#create-button").click();
        const failed = await failedResponse;
        assert.equal(failed.status(), 500);
        assert.doesNotMatch(await failed.text(), /SQLSTATE|fixture\.sqlite/);
        await idle();
        assert.match(
            await page.locator("#feedback").innerText(),
            /Creation outcome may be unknown/,
        );
        state = inspect();
        assert.equal(state.rules.length, 1);
        assert.equal(state.mutex_version, 1);
        assert.equal(state.audits, null);
        fixtureCommand("audit-online");
        auditOffline = false;
        assert.equal(inspect().audits.length, 1);
        pass(
            "real audit outage rolls back creation and mutex; UI warns instead of auto-retrying",
        );
        await page.locator("#refresh").click();
        await idle();
        await page.locator("#revoke-reason").fill("LIVE_REVOKE");
        page.once("dialog", (dialog) => dialog.dismiss());
        await page.locator("#rule-rows button").click();
        assert.equal(inspect().rules[0].revoked_at_epoch, null);
        pass("cancelled browser confirmation performs no revoke");
        page.once("dialog", (dialog) => dialog.accept());
        const revokeResponse = page.waitForResponse(
            (r) =>
                r.url().endsWith("/revoke") && r.request().method() === "POST",
        );
        await page.locator("#rule-rows button").click();
        assert.equal((await revokeResponse).status(), 200);
        await idle();
        state = inspect();
        assert.ok(state.rules[0].revoked_at_epoch > 0);
        assert.equal(state.audits.length, 2);
        assert.equal(state.audits[1].action, "update");
        assert.equal(
            await page.locator("#rule-rows button").isDisabled(),
            true,
        );
        const revokedAt = state.rules[0].revoked_at_epoch;
        pass(
            "confirmed revoke persists a real timestamp and audit and disables the row action",
        );
        const retry = await context.request.post(
            rulesUrl + "/" + ruleId + "/revoke",
            {
                data: { reason_code: "RETRY" },
                headers: { Accept: "application/json", "X-CSRF-TOKEN": token },
            },
        );
        assert.equal(retry.status(), 200);
        state = inspect();
        assert.equal(state.rules[0].revoked_at_epoch, revokedAt);
        assert.equal(state.audits.length, 2);
        pass(
            "real sequential revoke retry preserves original timestamp and audit count",
        );
        const playerContext = await browser.newContext();
        await login(playerContext, meta.player);
        const denied = await playerContext.request.get(rulesUrl, {
            headers: { Accept: "application/json" },
        });
        assert.equal(denied.status(), 403);
        await playerContext.close();
        pass("separately authenticated player is refused inventory access");
        fixtureCommand("suspend-operator");
        await page.locator("#refresh").click();
        await idle();
        assert.match(
            await page.locator("#feedback").innerText(),
            /Access denied/,
        );
        assert.equal(await page.locator("#create-button").isDisabled(), true);
        const suspended = await context.request.post(rulesUrl, {
            data: { ip_range: "192.0.2.9", reason_code: "DENIED" },
            headers: { Accept: "application/json", "X-CSRF-TOKEN": token },
        });
        assert.equal(suspended.status(), 403);
        assert.equal(inspect().rules.length, 1);
        pass(
            "mid-session suspension is enforced by real requests and disables browser mutations",
        );
        const logout = await context.request.post(base + "/logout", {
            form: { _token: token },
            headers: { Accept: "application/json" },
            maxRedirects: 0,
        });
        assert.ok([302, 303].includes(logout.status()));
        const afterLogout = await context.request.get(rulesUrl, {
            headers: { Accept: "application/json" },
            maxRedirects: 0,
        });
        assert.equal(afterLogout.status(), 401);
        assert.deepEqual(pageErrors, []);
        pass(
            "real logout removes access; no uncaught screen JavaScript errors",
        );
        console.log(
            `OK (${passed} live HTTP/browser checks; no mocked transport; CAPTCHA and honeypot disabled in disposable fixture)`,
        );
        await context.close();
    } finally {
        try {
            if (auditOffline) fixtureCommand("audit-online");
        } finally {
            await browser.close();
        }
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
