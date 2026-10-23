<!doctype html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0B0904">
    <title>Withdrawal evidence reconciliation</title>
    <style @cspNonce>
        :root {
            color-scheme: dark;
            --bg-main: #0B0904;
            --surface: #141007;
            --surface-soft: #1C170B;
            --gold: #D4AF37;
            --gold-soft: #F5E6B8;
            --highlight: #FFF6D6;
            --ink: #FAF6EC;
            --muted: #D4CAB3;
            --dim: #9D937D;
            --border: rgba(212, 175, 55, 0.24);
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font: 15px/1.6 Inter, system-ui, -apple-system, sans-serif;
            margin: 0 auto;
            padding: 1.5rem 1rem 3rem;
            max-width: 1080px;
            color: var(--ink);
            background: radial-gradient(circle at 50% 0%, rgba(212, 175, 55, 0.12), transparent 36rem), var(--bg-main);
        }
        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            background: var(--gold);
            color: #0B0904;
            padding: 0.65rem 1rem;
            border-radius: 0.65rem;
            font-weight: 800;
            text-decoration: none;
            z-index: 100;
        }
        .skip-link:focus { left: 1rem; top: 1rem; }
        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.85rem 1.25rem;
            margin-bottom: 1.25rem;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface);
        }
        main {
            background: linear-gradient(165deg, rgba(28, 22, 10, 0.94), rgba(20, 16, 7, 0.97));
            border: 1px solid var(--border);
            padding: 1.75rem 1.5rem;
            border-radius: 18px;
            box-shadow: 0 24px 54px -20px rgba(0, 0, 0, 0.85);
        }
        h1, h2 { color: var(--highlight); line-height: 1.25; }
        h1 { margin-top: 0; font-size: clamp(1.45rem, 3vw, 1.9rem); font-weight: 900; }
        h2 { margin-top: 2rem; font-size: 1.2rem; font-weight: 800; border-top: 1px solid rgba(212, 175, 55, 0.14); padding-top: 1.35rem; }
        a { color: var(--gold-soft); font-weight: 700; text-underline-offset: 3px; }
        a:hover { color: var(--gold); }
        label { display: block; margin: 1rem 0; font-weight: 700; color: var(--gold-soft); font-size: 0.9rem; }
        input, select, textarea, button {
            font: inherit;
            padding: 0.68rem 0.85rem;
            max-width: 100%;
            min-height: 44px;
            border-radius: 10px;
        }
        input:not([type=checkbox]), textarea, select {
            display: block;
            width: 100%;
            margin-top: 0.35rem;
            background: var(--bg-main);
            border: 1px solid rgba(212, 175, 55, 0.3);
            color: var(--highlight);
        }
        input[type=checkbox] {
            min-height: 18px;
            width: 18px;
            height: 18px;
            vertical-align: middle;
            margin-right: 0.5rem;
            accent-color: var(--gold);
        }
        button {
            background: linear-gradient(135deg, #FFF6D6 0%, #D4AF37 55%, #AA821E 100%);
            color: #0B0904;
            font-weight: 800;
            border: 1px solid rgba(245, 230, 184, 0.45);
            padding: 0.7rem 1.35rem;
            cursor: pointer;
        }
        button:hover { filter: brightness(1.05); }
        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 12px; margin: 1rem 0; }
        table { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
        th, td { text-align: left; padding: 0.75rem 0.9rem; border-bottom: 1px solid rgba(212, 175, 55, 0.14); overflow-wrap: anywhere; }
        th { background: #0B0904; color: var(--gold-soft); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; }
        dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap: 0.75rem 1.25rem; margin: 1rem 0; }
        dt { font-size: 0.76rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--dim); }
        dd { margin: 0.2rem 0 0.65rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--highlight); overflow-wrap: anywhere; }
        .notice {
            padding: 1rem 1.15rem;
            background: rgba(245, 158, 11, 0.12);
            border: 1px solid rgba(245, 158, 11, 0.38);
            border-left: 4px solid var(--gold);
            border-radius: 12px;
            color: #FDE68A;
        }
        .status-ok {
            padding: 0.9rem 1.1rem;
            border-radius: 12px;
            background: rgba(22, 163, 74, 0.16);
            border: 1px solid rgba(34, 197, 94, 0.42);
            color: #86EFAC;
        }
        .errors {
            padding: 0.9rem 1.25rem 0.9rem 2rem;
            border-radius: 12px;
            background: rgba(220, 38, 38, 0.16);
            border: 1px solid rgba(239, 68, 68, 0.45);
            color: #FCA5A5;
        }
        .muted { color: var(--muted); font-size: 0.9rem; }
    </style>
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>
<header role="banner">
    <a href="{{ route('admin.withdrawal-reconciliation.index') }}">Withdrawal reconciliation</a>
    <span class="muted">Dual-control evidence review</span>
</header>
<main id="main-content" tabindex="-1">
    <h1>Provider evidence, independent review</h1>
    <p class="notice">This workflow never sends a transfer. Unknown, timeout, pending or not-found results are not evidence of rejection. Do not clear claims or resend held withdrawals.</p>
    @if(session('status'))<p class="status-ok" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<ul class="errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    @if($item)
        <h2>Case #{{ $item['id'] }} · {{ $item['status'] }}</h2>
        <dl>
            <div><dt>Withdrawal / Payment</dt><dd>{{ $item['withdrawal_id'] }} / {{ $item['payment_id'] }}</dd></div>
            <div><dt>Maker / Checker</dt><dd>{{ $item['maker_id'] }} / {{ $item['checker_id'] ?? 'Not decided' }}</dd></div>
            @foreach($item['facts'] as $key => $value)<div><dt>{{ str_replace('_', ' ', $key) }}</dt><dd>{{ $value }}</dd></div>@endforeach
            <div><dt>Evidence SHA-256 / bytes</dt><dd>{{ $item['evidence_sha256'] }} / {{ $item['evidence_bytes'] }}</dd></div>
            <div><dt>Decision note</dt><dd>{{ $item['decision_note'] ?? 'Not decided' }}</dd></div>
        </dl>
        <p><a href="{{ route('admin.withdrawal-reconciliation.evidence', $item['id']) }}">Download private evidence as attachment</a></p>
        <p class="muted">A hash proves byte integrity, not provider authenticity. Independently obtain and verify final provider status, beneficiary, amounts, currency and references using the approved provider channel. Never execute downloaded content.</p>
        @if($item['status'] === 'pending')
            <p><a href="{{ route('admin.withdrawal-reconciliation.destination', $item['id']) }}">Independent checker: inspect original private payout destination</a></p>
            <p class="muted">This sensitive view requires review permission, a different checker and an unchanged pending case. Unknown destination schemas are refused rather than partly hidden.</p>
            <form method="post" action="{{ route('admin.withdrawal-reconciliation.decide', $item['id']) }}">
                @csrf
                <label>Decision<select name="decision" required><option value="">Select a decision</option><option value="reject">Reject evidence case; keep withdrawal unchanged</option><option value="apply">Apply verified final evidence through finance services</option></select></label>
                <label><input type="checkbox" name="evidence_verified" value="1"> I independently verified the actual artifact and final provider result, including the original request, beneficiary and money facts. I am not the maker or beneficiary.</label>
                <label>Independent review note (15–1000 characters)<textarea name="note" minlength="15" maxlength="1000" required></textarea></label>
                <button type="submit">Record independent decision</button>
            </form>
        @endif
    @else
        <h2>Evidence cases</h2>
        <div class="table-wrap">
            <table><thead><tr><th scope="col">Case</th><th scope="col">Withdrawal</th><th scope="col">Maker</th><th scope="col">State</th></tr></thead><tbody>
            @forelse($cases as $case)<tr><td><a href="{{ route('admin.withdrawal-reconciliation.show', $case) }}">#{{ $case->id }}</a></td><td>{{ $case->withdrawal_id }}</td><td>{{ $case->maker_id }}</td><td>{{ $case->status }}</td></tr>@empty<tr><td colspan="4">No evidence cases.</td></tr>@endforelse
            </tbody></table>
        </div>
        <p>@if($cases->previousPageUrl())<a href="{{ $cases->previousPageUrl() }}">Previous</a>@endif @if($cases->nextPageUrl())<a href="{{ $cases->nextPageUrl() }}">Next</a>@endif</p>
        <h2>Submit final provider evidence</h2>
        <p class="muted">Use the original withdrawal reference. Money must be exact, for example 400.00, with the stored currency. Observation time must be RFC3339 with timezone, no earlier than the dispatch claim. No URL is fetched.</p>
        <form method="post" enctype="multipart/form-data" action="{{ route('admin.withdrawal-reconciliation.store') }}">
            @csrf
            <label>Withdrawal ID<input name="withdrawal_id" type="number" min="1" required></label>
            <label>Original internal reference<input name="internal_reference" maxlength="128" required></label>
            <label>Canonical provider (for example bank_transfer)<input name="provider" maxlength="40" required></label>
            <label>Final provider reference<input name="provider_reference" maxlength="128" required></label>
            <label>Currency code<input name="currency" maxlength="3" required></label>
            <label>Gross amount<input name="gross_amount" inputmode="decimal" required></label>
            <label>Net beneficiary amount<input name="net_amount" inputmode="decimal" required></label>
            <label>Final result<select name="outcome" required><option value="">Select confirmed result</option><option value="completed">Confirmed completed</option><option value="definitively_rejected">Proven final non-acceptance, not timeout/not-found</option></select></label>
            <label>Provider observation time<input name="observed_at" placeholder="2026-10-15T12:00:00+00:00" maxlength="25" required></label>
            <label>Provider evidence (PDF, text or JSON; up to 2 MiB)<input type="file" name="evidence" accept="application/pdf,application/json,text/plain" required></label>
            <button type="submit">Submit for independent review</button>
        </form>
    @endif
</main>
</body>
</html>
