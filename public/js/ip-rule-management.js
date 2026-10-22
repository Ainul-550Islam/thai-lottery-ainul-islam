(() => {
  "use strict";
  const root = document.getElementById("ip-rule-screen");
  if (!root) return;
  const el = (id) => document.getElementById(id);
  const feedback = el("feedback");
  const rows = el("rule-rows");
  let busy = false,
    inventoryReady = false,
    everLoaded = false,
    current = null,
    next = null,
    history = [];
  let pendingCreate = null;
  let retryProtocolUnavailable = false;
  const positiveId = (id) => Number.isSafeInteger(id) && id > 0;
  function message(text, error = false) {
    feedback.textContent = text;
    feedback.dataset.error = String(error);
  }
  function syncControls() {
    el("create-fields").disabled =
      busy || !inventoryReady || retryProtocolUnavailable;
    el("revoke-reason").disabled = busy || !inventoryReady;
    el("refresh").disabled = busy;
    el("previous").disabled = busy || !inventoryReady || history.length === 0;
    el("next").disabled = busy || !inventoryReady || next === null;
    rows.querySelectorAll("button").forEach((button) => {
      button.disabled =
        busy || !inventoryReady || button.dataset.allowed !== "true";
    });
    root.setAttribute("aria-busy", String(busy));
  }
  async function run(task) {
    if (busy) return;
    busy = true;
    syncControls();
    try {
      await task();
    } catch (error) {
      message(
        error.message || "Request failed. Refresh before retrying.",
        true,
      );
    } finally {
      busy = false;
      syncControls();
    }
  }
  async function api(url, payload, idempotencyKey) {
    const options = {
      credentials: "same-origin",
      headers: { Accept: "application/json" },
      cache: "no-store",
    };
    if (payload !== undefined) {
      options.method = "POST";
      options.headers["Content-Type"] = "application/json";
      options.headers["X-CSRF-TOKEN"] = document.querySelector(
        'meta[name="csrf-token"]',
      ).content;
      if (idempotencyKey) options.headers["Idempotency-Key"] = idempotencyKey;
      options.body = JSON.stringify(payload);
    }
    let response, body;
    try {
      response = await fetch(url, options);
      if (response.redirected) throw new Error("Unexpected redirect");
      body = await response.json();
    } catch (_) {
      const error = new Error(
        "No reliable response. Reload and verify your session and inventory before retrying.",
      );
      error.uncertain = true;
      throw error;
    }
    if (!response.ok || body.success !== true) {
      const messages = {
        401: "Sign in again before continuing.",
        403: "Access denied. Your permissions or account status may have changed.",
        419: "Session token expired. Reload before trying again.",
        422: "Validation failed. Check the submitted fields.",
        429: "Rate limit reached. Wait before trying again.",
        503: "Management is unavailable or disabled.",
      };
      let text =
        messages[response.status] || "The request could not be completed.";
      if (response.status === 422 && body.error?.details?.fields) {
        Object.values(body.error.details.fields).forEach((values) => {
          if (Array.isArray(values))
            values.forEach((value) => {
              if (typeof value === "string") text += "\n" + value;
            });
        });
      }
      if (response.status === 429) {
        const retry = response.headers.get("Retry-After");
        if (retry && /^\d+$/.test(retry))
          text += ` Retry after ${retry} seconds.`;
      }
      const error = new Error(text);
      error.uncertain = response.status >= 500;
      throw error;
    }
    if (
      idempotencyKey &&
      !["true", "false"].includes(response.headers.get("Idempotency-Replayed"))
    ) {
      retryProtocolUnavailable = true;
      const error = new Error(
        "Server did not acknowledge retry protection. Create is disabled on this page; reconcile inventory and deployment before reloading.",
      );
      error.uncertain = true;
      throw error;
    }
    return body.data;
  }
  const instant = (value) => {
    if (value === null) return "—";
    if (!Number.isSafeInteger(value) || value < 0 || value > 253402300799)
      return "UNKNOWN";
    return new Date(value * 1000).toISOString().replace(".000Z", "Z");
  };
  function text(parent, value, className = "") {
    const p = document.createElement("p");
    p.textContent = String(value ?? "—");
    p.className = className;
    parent.append(p);
  }
  function render(items) {
    rows.replaceChildren();
    if (!items.length) {
      const tr = document.createElement("tr"),
        td = document.createElement("td");
      td.colSpan = 4;
      td.textContent = "No rules on this page.";
      tr.append(td);
      rows.append(tr);
      return;
    }
    items.forEach((rule) => {
      const tr = document.createElement("tr");
      const cells = Array.from({ length: 4 }, () =>
        document.createElement("td"),
      );
      cells.forEach((cell) => tr.append(cell));
      text(cells[0], positiveId(rule.id) ? `#${rule.id}` : "Unsupported ID");
      text(cells[0], rule.ip_range, "mono");
      text(cells[1], rule.reason_code);
      text(cells[1], `${rule.validation_status} · ${rule.lifecycle}`, "muted");
      text(cells[2], "Start: " + instant(rule.starts_at_epoch));
      text(cells[2], "Expiry: " + instant(rule.expires_at_epoch));
      text(cells[2], "Revoked: " + instant(rule.revoked_at_epoch));
      text(
        cells[3],
        "Creator ID: " +
          (rule.created_by === null
            ? "—"
            : positiveId(rule.created_by)
              ? rule.created_by
              : "UNSUPPORTED_ID"),
      );
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = "Revoke";
      button.dataset.allowed = String(
        positiveId(rule.id) &&
          rule.validation_status === "VALID" &&
          ["ACTIVE", "SCHEDULED", "EXPIRED"].includes(rule.lifecycle),
      );
      button.addEventListener("click", () => revoke(rule));
      cells[3].append(button);
      rows.append(tr);
    });
  }
  async function load(cursor) {
    inventoryReady = false;
    try {
      const query = new URLSearchParams({ limit: "25" });
      if (cursor !== null) query.set("before_id", String(cursor));
      const data = await api(root.dataset.indexUrl + "?" + query);
      if (
        !data ||
        !Array.isArray(data.items) ||
        typeof data.has_more !== "boolean" ||
        (data.has_more && !positiveId(data.next_before_id))
      )
        throw new Error(
          "Invalid inventory response. Refresh before continuing.",
        );
      render(data.items);
      current = cursor;
      next = data.has_more ? data.next_before_id : null;
      el("page-summary").textContent =
        `${data.items.length} ${data.items.length === 1 ? "row" : "rows"} on this page · observed ${instant(data.observed_at_epoch)}`;
      inventoryReady = true;
      everLoaded = true;
    } finally {
      if (!inventoryReady) {
        el("page-summary").textContent =
          "Inventory unavailable or stale. Refresh to re-enable mutations.";
        if (!everLoaded)
          rows.firstElementChild.firstElementChild.textContent =
            "Inventory unavailable. Refresh to try again.";
      }
    }
  }
  async function reloadAfterSave() {
    try {
      await load(null);
      history = [];
      message(
        "Rule saved. Inventory refreshed; enforcement configuration is unchanged.",
      );
    } catch (_) {
      message(
        "Rule saved, but inventory refresh failed. Refresh before submitting another mutation.",
        true,
      );
    }
  }
  function revoke(rule) {
    const reason = el("revoke-reason");
    if (
      busy ||
      !inventoryReady ||
      !positiveId(rule.id) ||
      !reason.reportValidity()
    )
      return;
    if (
      !window.confirm(
        `Revoke rule #${rule.id} (${rule.ip_range})? This retains the rule and records an audit.`,
      )
    )
      return;
    run(async () => {
      try {
        await api(
          root.dataset.revokeUrl.replace("__RULE_ID__", String(rule.id)),
          { reason_code: reason.value },
        );
      } catch (error) {
        message(
          error.message +
            (error.uncertain
              ? "\nOutcome may be unknown. Refresh inventory before retrying."
              : ""),
          true,
        );
        return;
      }
      await reloadAfterSave();
    });
  }
  el("create-form").addEventListener("submit", (event) => {
    event.preventDefault();
    if (
      !inventoryReady ||
      retryProtocolUnavailable ||
      !el("create-form").reportValidity()
    )
      return;
    run(async () => {
      const epoch = (id) => (el(id).value === "" ? null : Number(el(id).value));
      const payload = {
        ip_range: el("ip-range").value,
        reason_code: el("create-reason").value,
        starts_at_epoch: epoch("starts-at"),
        expires_at_epoch: epoch("expires-at"),
      };
      const signature = JSON.stringify(payload);
      if (!pendingCreate || pendingCreate.signature !== signature) {
        if (
          pendingCreate?.uncertain &&
          !window.confirm(
            "The previous create outcome is unknown. Changed fields start a new operation and may create another rule. Continue only after checking inventory.",
          )
        )
          return;
        if (!globalThis.crypto?.getRandomValues)
          throw new Error(
            "Secure request-key generation is unavailable. Create was not sent.",
          );
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        const key = Array.from(bytes, (value) =>
          value.toString(16).padStart(2, "0"),
        ).join("");
        pendingCreate = { signature, key, uncertain: false };
      }
      try {
        await api(root.dataset.createUrl, payload, pendingCreate.key);
      } catch (error) {
        pendingCreate.uncertain ||= Boolean(error.uncertain);
        message(
          error.message +
            (pendingCreate.uncertain
              ? "\nCreation outcome may be unknown. Keep this page open: retrying unchanged fields reuses the key. Reloading loses the key; reconcile inventory before starting another operation."
              : ""),
          true,
        );
        return;
      }
      pendingCreate = null;
      el("create-form").reset();
      await reloadAfterSave();
    });
  });
  el("refresh").addEventListener("click", () =>
    run(async () => {
      await load(null);
      history = [];
      message("Inventory refreshed.");
    }),
  );
  el("next").addEventListener("click", () =>
    run(async () => {
      if (next === null) return;
      const prior = current;
      await load(next);
      history.push(prior);
      message("Older page loaded.");
    }),
  );
  el("previous").addEventListener("click", () =>
    run(async () => {
      if (!history.length) return;
      await load(history[history.length - 1]);
      history.pop();
      message("Previous page loaded.");
    }),
  );
  run(async () => {
    await load(null);
    message("Inventory loaded. No enforcement activation is implied.");
  });
})();
