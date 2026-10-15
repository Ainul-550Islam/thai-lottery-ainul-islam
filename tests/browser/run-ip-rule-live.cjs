"use strict";
// One-shot runner owns its fixture, child server and cleanup. Never starts production workers.
const fs = require("node:fs");
const path = require("node:path");
const crypto = require("node:crypto");
const { spawn, execFileSync } = require("node:child_process");
const repo = path.resolve(__dirname, "../..");
const parent = path.join(repo, "storage/framework/testing");
fs.mkdirSync(parent, { recursive: true, mode: 0o700 });
const root = path.join(
    fs.realpathSync(parent),
    "ip-rule-live-" + crypto.randomBytes(8).toString("hex"),
);
fs.mkdirSync(root, { mode: 0o700 });
const env = {
    ...process.env,
    IP_RULE_BROWSER_ROOT: root,
    IP_RULE_BROWSER_TEST_ONLY: "1",
    IP_RULE_BROWSER_URL: "http://127.0.0.1:8091",
};
const php = process.env.PHP_BINARY || "php";
let server,
    serverExit,
    browserChild,
    browserExit,
    cleaned = false;
async function cleanup() {
    if (cleaned) return;
    cleaned = true;
    if (
        browserChild &&
        browserChild.exitCode === null &&
        browserChild.signalCode === null
    ) {
        browserChild.kill("SIGTERM");
        let timer;
        await Promise.race([
            browserExit,
            new Promise((resolve) => {
                timer = setTimeout(resolve, 3000);
            }),
        ]);
        clearTimeout(timer);
        if (
            browserChild.exitCode === null &&
            browserChild.signalCode === null
        ) {
            browserChild.kill("SIGKILL");
        }
    }
    if (browserExit) await browserExit;
    if (server && server.exitCode === null && server.signalCode === null) {
        server.kill("SIGTERM");
        let timer;
        await Promise.race([
            serverExit,
            new Promise((resolve) => {
                timer = setTimeout(resolve, 3000);
            }),
        ]);
        clearTimeout(timer);
        if (server.exitCode === null && server.signalCode === null) {
            server.kill("SIGKILL");
            await serverExit;
        }
    }
    if (serverExit) await serverExit;
    if (fs.existsSync(root)) fs.rmSync(root, { recursive: true });
    console.log(
        "CLEANUP: disposable server stopped and fixture credentials/database/storage removed.",
    );
}
for (const signal of ["SIGINT", "SIGTERM"]) {
    process.once(signal, () => {
        cleanup().finally(() => process.exit(signal === "SIGINT" ? 130 : 143));
    });
}
(async () => {
    try {
        process.stdout.write(
            execFileSync(
                php,
                ["tests/Browser/Support/ip-rule-live.php", "prepare"],
                { cwd: repo, env, encoding: "utf8" },
            ),
        );
        server = spawn(
            php,
            [
                "-S",
                "0.0.0.0:8091",
                "-t",
                "public",
                "tests/Browser/Support/ip-rule-live.php",
            ],
            { cwd: repo, env, stdio: ["ignore", "pipe", "pipe"] },
        );
        serverExit = new Promise((resolve) => server.once("close", resolve));
        await new Promise((resolve, reject) => {
            let startup = "";
            const timer = setTimeout(
                () =>
                    reject(
                        new Error("Disposable HTTP server startup timed out."),
                    ),
                10000,
            );
            server.once("error", (error) => {
                clearTimeout(timer);
                reject(error);
            });
            server.once("exit", () => {
                clearTimeout(timer);
                reject(
                    new Error(
                        "Disposable server exited; ensure port 8091 is free.",
                    ),
                );
            });
            server.stdout.on("data", (data) =>
                fs.appendFileSync(path.join(root, "server.log"), data),
            );
            server.stderr.on("data", (data) => {
                fs.appendFileSync(path.join(root, "server.log"), data);
                startup = (startup + data.toString()).slice(-8192);
                if (/Development Server.*started/.test(startup)) {
                    clearTimeout(timer);
                    resolve();
                }
            });
        });
        const child = (browserChild = spawn(
            process.execPath,
            ["tests/Browser/ip-rule-management-live.cjs"],
            { cwd: repo, env, stdio: "inherit" },
        ));
        browserExit = new Promise((resolve) => child.once("close", resolve));
        const code = await new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                child.kill("SIGKILL");
                reject(new Error("Live browser checks exceeded 90 seconds."));
            }, 90000);
            child.once("error", (error) => {
                clearTimeout(timer);
                reject(error);
            });
            child.once("exit", (code) => {
                clearTimeout(timer);
                resolve(code);
            });
        });
        if (code !== 0)
            throw new Error(
                "Live browser checks failed; see assertions above.",
            );
    } finally {
        await cleanup();
    }
})().catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
});
