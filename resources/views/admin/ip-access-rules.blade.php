<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>IP rule management</title>
    <link rel="stylesheet" href="/css/ip-rule-management.css?v=1">
    <script src="/js/ip-rule-management.js?v=2" defer></script>
</head>
<body>
<a href="#ip-rule-screen" class="skip-link">Skip to main content</a>
<main id="ip-rule-screen" tabindex="-1"
      data-index-url="{{ route('admin.ip-access.rules.index', [], false) }}"
      data-create-url="{{ route('admin.ip-access.rules.store', [], false) }}"
      data-revoke-url="{{ route('admin.ip-access.rules.revoke', ['ruleId' => '__RULE_ID__'], false) }}">
    <header class="page-header">
        <div><a href="/admin" class="back-link">← Admin dashboard</a><p class="eyebrow">Network access · operator tools</p><h1>IP rule management</h1></div>
        <span class="pill">HTTP management enabled</span>
    </header>
    <section class="notice" aria-label="Policy configuration">
        <strong>Configuration, not a health verdict</strong>
        <p>Global policy: <b id="global-state">{{ $globalState }}</b> · Database policy: <b id="database-state">{{ $databaseState }}</b> · Mode: <b id="policy-mode">{{ $policyMode }}</b></p>
        <p>ACTIVE describes a row's lifecycle, not proof of blocking. Policy currently covers the three authentication POSTs, not the whole site. Saving a rule does not enable enforcement.</p>
    </section>
    <noscript><p class="notice">JavaScript is required for this screen. No rule can be created or revoked from these controls without it.</p></noscript>
    <p id="feedback" role="status" aria-live="polite" tabindex="-1">Loading inventory…</p>
    <div class="workspace">
        <section class="card" aria-labelledby="create-heading">
            <h2 id="create-heading">Create a rule</h2>
            <p class="muted">A rule is a denylist entry. Confirm the target before saving; this screen has no self-lockout prevention or step-up MFA.</p>
            <form id="create-form">
                <fieldset id="create-fields" disabled>
                    <label for="ip-range">IP address or CIDR</label>
                    <input id="ip-range" name="ip_range" required maxlength="64" placeholder="192.0.2.0/24" autocomplete="off" spellcheck="false">
                    <label for="create-reason">Reason code</label>
                    <input id="create-reason" name="reason_code" required maxlength="64" pattern="[A-Z][A-Z0-9_]{0,63}" placeholder="OPERATOR_BLOCK" title="Uppercase letters, digits and underscores; start with a letter." autocomplete="off">
                    <label for="starts-at">Start · UTC epoch seconds</label>
                    <input id="starts-at" name="starts_at_epoch" type="number" min="0" max="253402300799" step="1" placeholder="Blank = server time">
                    <label for="expires-at">Expiry · UTC epoch seconds</label>
                    <input id="expires-at" name="expires_at_epoch" type="number" min="0" max="253402300799" step="1" placeholder="Blank = no expiry">
                    <button id="create-button" type="submit">Create rule</button>
                </fieldset>
            </form>
            <p class="muted small">Capacity: at most {{ $capacityLimit }} unrevoked, unexpired reservations through the guarded writer. Future schedules reserve slots, including disjoint schedules. This is a limit, not a live count.</p>
        </section>
        <section class="card inventory" aria-labelledby="inventory-heading">
            <div class="section-header"><div><h2 id="inventory-heading">Database inventory</h2><p id="page-summary" class="muted">No inventory loaded.</p></div><button id="refresh" type="button" disabled>Refresh first page</button></div>
            <div class="revoke-reason"><label for="revoke-reason">Reason code for revocation</label><input id="revoke-reason" required maxlength="64" pattern="[A-Z][A-Z0-9_]{0,63}" placeholder="OPERATOR_REVOKE" autocomplete="off" disabled></div>
            <div class="table-scroll"><table>
                <caption class="sr-only">Authorized database IP rules, newest IDs first</caption>
                <thead><tr><th scope="col">ID / target</th><th scope="col">Reason / lifecycle</th><th scope="col">UTC lifecycle</th><th scope="col">Creator / action</th></tr></thead>
                <tbody id="rule-rows"><tr><td colspan="4">Loading…</td></tr></tbody>
            </table></div>
            <div class="pagination"><button id="previous" type="button" disabled>Previous page</button><span class="muted">25 rows per page · no total-count claim</span><button id="next" type="button" disabled>Older rules</button></div>
            <p class="muted small">New inserts appear on a first-page refresh. Pages are not a historical snapshot. Invalid rows are shown without corrupt content and cannot be repaired here.</p>
        </section>
    </div>
</main>
</body>
</html>
