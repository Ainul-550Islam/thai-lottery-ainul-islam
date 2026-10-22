"use strict";
// Actual Chromium + compiled Blade fixture. Network endpoints are mocked, not a live backend journey.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("playwright");
const root = path.resolve(__dirname, "../..");
const fixture = process.env.IP_RULE_UI_FIXTURE_PATH;
if (!fixture)
    throw new Error(
        "Set IP_RULE_UI_FIXTURE_PATH to HTML emitted by IpAccessRuleScreenTest.",
    );
const html = fs.readFileSync(fixture, "utf8");
const now = 1791806400;
const row = (id = 9, extra = {}) => ({
    id,
    ip_range: "192.0.2.0/24",
    reason_code: "OPERATOR_BLOCK",
    validation_status: "VALID",
    lifecycle: "ACTIVE",
    starts_at_epoch: now,
    expires_at_epoch: null,
    revoked_at_epoch: null,
    created_by: 1,
    ...extra,
});
const tests = [];
const test = (name, run) => tests.push({ name, run });
let browser;
async function setup(overrides = {}) {
    const state = {
        items: [row()],
        posts: [],
        reads: [],
        status: 201,
        confirm: true,
        ...overrides,
    };
    const context = await browser.newContext({
        viewport: { width: 1360, height: 1000 },
        javaScriptEnabled: overrides.js !== false,
    });
    const page = await context.newPage();
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    page.on("dialog", (dialog) =>
        state.confirm ? dialog.accept() : dialog.dismiss(),
    );
    await page.route("https://ui.test/**", async (route) => {
        const request = route.request(),
            url = new URL(request.url());
        if (url.pathname.endsWith("/manage"))
            return route.fulfill({ contentType: "text/html", body: html });
        if (url.pathname === "/js/ip-rule-management.js")
            return route.fulfill({
                contentType: "text/javascript",
                body: fs.readFileSync(
                    path.join(root, "public/js/ip-rule-management.js"),
                    "utf8",
                ),
            });
        if (url.pathname === "/css/ip-rule-management.css")
            return route.fulfill({
                contentType: "text/css",
                body: fs.readFileSync(
                    path.join(root, "public/css/ip-rule-management.css"),
                    "utf8",
                ),
            });
        if (request.method() === "POST") {
            state.posts.push({
                url: url.pathname,
                payload: request.postDataJSON(),
                headers: request.headers(),
            });
            if (state.delay)
                await new Promise((resolve) =>
                    setTimeout(resolve, state.delay),
                );
            if (state.abort) return route.abort("failed");
            if (state.status >= 400)
                return route.fulfill({
                    status: state.status,
                    headers: { "Retry-After": "60" },
                    json: {
                        success: false,
                        error: {
                            details: {
                                fields: {
                                    ip_rule_capacity: [
                                        "Reservation capacity is full.",
                                    ],
                                },
                            },
                        },
                    },
                });
            if (url.pathname.endsWith("/revoke"))
                state.items[0].lifecycle = "REVOKED";
            if (state.refreshFailure) state.readFailure = true;
            return route.fulfill({
                status: url.pathname.endsWith("/revoke") ? 200 : 201,
                headers: state.omitAcknowledgement
                    ? {}
                    : { "Idempotency-Replayed": "false" },
                json: { success: true, data: row(10) },
            });
        }
        if (url.pathname === "/admin/ip-access/rules") {
            state.reads.push(url.search);
            if (state.readFailure)
                return route.fulfill({
                    status: 500,
                    json: { success: false, error: {} },
                });
            const older = url.searchParams.has("before_id");
            return route.fulfill({
                json: {
                    success: true,
                    data: {
                        items: older ? [row(8)] : state.items,
                        has_more: Boolean(state.pages && !older),
                        next_before_id: state.pages && !older ? 9 : null,
                        observed_at_epoch: now,
                    },
                },
            });
        }
        return route.fulfill({ status: 404, body: "" });
    });
    await page.goto("https://ui.test/admin/ip-access/rules/manage");
    const idle = () =>
        page.waitForFunction(
            () =>
                document
                    .getElementById("ip-rule-screen")
                    .getAttribute("aria-busy") === "false",
        );
    if (overrides.js !== false) await idle();
    const fill = async () => {
        await page.locator("#ip-range").fill("192.0.2.3");
        await page.locator("#create-reason").fill("OPERATOR_BLOCK");
    };
    return {
        page,
        state,
        idle,
        fill,
        errors,
        close: async () => {
            assert.deepEqual(errors, []);
            await context.close();
        },
    };
}
test("inventory renders actual fields and does not treat ACTIVE as blocking", async () => {
    const t = await setup();
    try {
        assert.match(
            await t.page.locator("#rule-rows").innerText(),
            /192\.0\.2\.0\/24/,
        );
        assert.equal(
            await t.page.locator("#database-state").textContent(),
            "OFF",
        );
        assert.match(
            await t.page.locator("#page-summary").innerText(),
            /^1 row on this page/,
        );
        assert.equal(await t.page.locator("#next").isDisabled(), true);
    } finally {
        await t.close();
    }
});
test("cursor navigation uses before_id and supports previous page", async () => {
    const t = await setup({ pages: true });
    try {
        await t.page.locator("#next").click();
        await t.idle();
        assert.match(t.state.reads.at(-1), /before_id=9/);
        assert.match(await t.page.locator("#rule-rows").innerText(), /#8/);
        await t.page.locator("#previous").click();
        await t.idle();
        assert.doesNotMatch(t.state.reads.at(-1), /before_id/);
        assert.equal(await t.page.locator("#previous").isDisabled(), true);
    } finally {
        await t.close();
    }
});
test("create sends session CSRF and whitelisted payload, then refreshes", async () => {
    const t = await setup();
    try {
        await t.fill();
        await t.page.locator("#create-button").click();
        await t.idle();
        assert.equal(t.state.posts.length, 1);
        assert.deepEqual(t.state.posts[0].payload, {
            ip_range: "192.0.2.3",
            reason_code: "OPERATOR_BLOCK",
            starts_at_epoch: null,
            expires_at_epoch: null,
        });
        assert.equal(
            t.state.posts[0].headers["x-csrf-token"],
            await t.page
                .locator('meta[name="csrf-token"]')
                .getAttribute("content"),
        );
        assert.equal(await t.page.locator("#ip-range").inputValue(), "");
        assert.match(
            await t.page.locator("#feedback").innerText(),
            /Rule saved/,
        );
    } finally {
        await t.close();
    }
});
test("in-flight submit latch prevents duplicate automatic creates", async () => {
    const t = await setup({ delay: 250 });
    try {
        await t.fill();
        await t.page.evaluate(() => {
            const form = document.getElementById("create-form");
            form.dispatchEvent(new Event("submit", { cancelable: true }));
            form.dispatchEvent(new Event("submit", { cancelable: true }));
        });
        await t.idle();
        assert.equal(t.state.posts.length, 1);
    } finally {
        await t.close();
    }
});
test("revoke needs confirmation, cancel sends nothing, confirm preserves reason", async () => {
    const t = await setup({ confirm: false });
    try {
        await t.page.locator("#revoke-reason").fill("OPERATOR_REVOKE");
        await t.page.locator("#rule-rows button").click();
        assert.equal(t.state.posts.length, 0);
        t.state.confirm = true;
        await t.page.locator("#rule-rows button").click();
        await t.idle();
        assert.equal(t.state.posts[0].url, "/admin/ip-access/rules/9/revoke");
        assert.deepEqual(t.state.posts[0].payload, {
            reason_code: "OPERATOR_REVOKE",
        });
        assert.equal(
            await t.page.locator("#rule-rows button").isDisabled(),
            true,
        );
    } finally {
        await t.close();
    }
});
test("invalid, revoked and unsafe-integer IDs cannot be revoked from controls", async () => {
    const t = await setup({
        items: [
            row(1, { validation_status: "INVALID", lifecycle: "UNKNOWN" }),
            row(2, { lifecycle: "REVOKED" }),
            row(Number.MAX_SAFE_INTEGER + 1),
        ],
    });
    try {
        assert.deepEqual(
            await t.page
                .locator("#rule-rows button")
                .evaluateAll((buttons) => buttons.map((b) => b.disabled)),
            [true, true, true],
        );
    } finally {
        await t.close();
    }
});
test("inventory strings are text, never HTML execution", async () => {
    const t = await setup({
        items: [
            row(1, {
                ip_range: '<img src=x onerror="window.__xss=1">',
                reason_code: "<script>window.__xss=1</script>",
            }),
        ],
    });
    try {
        assert.equal(
            await t.page.locator("#rule-rows img, #rule-rows script").count(),
            0,
        );
        assert.equal(await t.page.evaluate(() => window.__xss), undefined);
        assert.match(await t.page.locator("#rule-rows").innerText(), /<img/);
    } finally {
        await t.close();
    }
});
test("422 capacity error preserves form without auto retry", async () => {
    const t = await setup({ status: 422 });
    try {
        await t.fill();
        await t.page.locator("#create-button").click();
        await t.idle();
        assert.match(
            await t.page.locator("#feedback").innerText(),
            /Reservation capacity is full/,
        );
        assert.equal(
            await t.page.locator("#ip-range").inputValue(),
            "192.0.2.3",
        );
        assert.equal(t.state.posts.length, 1);
    } finally {
        await t.close();
    }
});
test("403, 419 and 429 produce actionable errors without retries", async () => {
    for (const [status, expected] of [
        [403, /Access denied/],
        [419, /Session token expired/],
        [429, /Retry after 60 seconds/],
    ]) {
        const t = await setup({ status });
        try {
            await t.fill();
            await t.page.locator("#create-button").click();
            await t.idle();
            assert.match(
                await t.page.locator("#feedback").innerText(),
                expected,
            );
            assert.equal(t.state.posts.length, 1);
        } finally {
            await t.close();
        }
    }
});
test("ambiguous outcomes retain a same-page retry key", async () => {
    for (const overrides of [{ abort: true }, { status: 500 }]) {
        const t = await setup(overrides);
        try {
            await t.fill();
            await t.page.locator("#create-button").click();
            await t.idle();
            assert.match(
                await t.page.locator("#feedback").innerText(),
                /Creation outcome may be unknown/,
            );
            assert.match(
                await t.page.locator("#feedback").innerText(),
                /Reloading loses the key/,
            );
            assert.equal(t.state.posts.length, 1);
        } finally {
            await t.close();
        }
    }
});
test("confirmed save plus failed refresh is not reported as failed creation", async () => {
    const t = await setup({ refreshFailure: true });
    try {
        await t.fill();
        await t.page.locator("#create-button").click();
        await t.idle();
        assert.match(
            await t.page.locator("#feedback").innerText(),
            /Rule saved, but inventory refresh failed/,
        );
        assert.equal(t.state.posts.length, 1);
        assert.equal(await t.page.locator("#create-button").isDisabled(), true);
    } finally {
        await t.close();
    }
});
test("failed inventory is not an empty-success message; mobile has no page overflow", async () => {
    const t = await setup({ readFailure: true });
    try {
        assert.equal(
            await t.page.locator("#feedback").getAttribute("data-error"),
            "true",
        );
        assert.doesNotMatch(
            await t.page.locator("#rule-rows").innerText(),
            /No rules on this page/,
        );
        assert.equal(await t.page.locator("#create-button").isDisabled(), true);
        assert.equal(await t.page.locator("#refresh").isDisabled(), false);
        await t.page.setViewportSize({ width: 390, height: 844 });
        assert.equal(
            await t.page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
            true,
        );
    } finally {
        await t.close();
    }
});
test("without JavaScript all mutation controls remain disabled", async () => {
    const t = await setup({ js: false });
    try {
        assert.equal(await t.page.locator("#create-button").isDisabled(), true);
        assert.equal(await t.page.locator("#revoke-reason").isDisabled(), true);
        assert.match(
            await t.page.locator("noscript").innerText(),
            /JavaScript is required/,
        );
        assert.equal(t.state.posts.length, 0);
        assert.equal(t.state.reads.length, 0);
    } finally {
        await t.close();
    }
});
test("same-page retry after uncertainty preserves key and payload", async () => {
    const t = await setup({ status: 500 });
    try {
        await t.fill();
        await t.page.locator("#create-button").click();
        await t.idle();
        t.state.status = 201;
        await t.page.locator("#refresh").click();
        await t.idle();
        await t.page.locator("#create-button").click();
        await t.idle();
        assert.equal(t.state.posts.length, 2);
        const key = t.state.posts[0].headers["idempotency-key"];
        assert.match(key, /^[a-f0-9]{32}$/);
        assert.equal(key, t.state.posts[1].headers["idempotency-key"]);
        assert.deepEqual(t.state.posts[0].payload, t.state.posts[1].payload);
    } finally {
        await t.close();
    }
});
test("changed fields after uncertainty need confirmation and a fresh key", async () => {
    const t = await setup({ status: 500, confirm: false });
    try {
        await t.fill();
        await t.page.locator("#create-button").click();
        await t.idle();
        await t.page.locator("#ip-range").fill("192.0.2.4");
        await t.page.locator("#create-button").click();
        await t.idle();
        assert.equal(t.state.posts.length, 1);
        t.state.confirm = true;
        t.state.status = 201;
        await t.page.locator("#create-button").click();
        await t.idle();
        assert.equal(t.state.posts.length, 2);
        assert.notEqual(
            t.state.posts[0].headers["idempotency-key"],
            t.state.posts[1].headers["idempotency-key"],
        );
    } finally {
        await t.close();
    }
});
test("missing server retry acknowledgement disables create rather than assuming protection", async () => {
    const t = await setup({ omitAcknowledgement: true });
    try {
        await t.fill();
        await t.page.locator("#create-button").click();
        await t.idle();
        assert.match(
            await t.page.locator("#feedback").innerText(),
            /did not acknowledge/,
        );
        assert.equal(await t.page.locator("#create-button").isDisabled(), true);
        assert.equal(t.state.posts.length, 1);
    } finally {
        await t.close();
    }
});
(async () => {
    const executablePath =
        process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ||
        (fs.existsSync("/usr/bin/chromium") ? "/usr/bin/chromium" : undefined);
    browser = await chromium.launch({
        headless: true,
        ...(executablePath ? { executablePath } : {}),
        args: ["--no-sandbox", "--disable-dev-shm-usage"],
    });
    try {
        for (const { name, run } of tests) {
            await run();
            console.log("PASS: " + name);
        }
        console.log(
            `OK (${tests.length} browser scenarios; real Chromium, mocked API transport)`,
        );
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
