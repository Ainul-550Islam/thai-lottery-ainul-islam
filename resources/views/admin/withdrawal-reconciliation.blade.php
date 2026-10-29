<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Withdrawal evidence reconciliation</title>
    <style>
        body{font:16px system-ui,sans-serif;line-height:1.55;margin:2rem auto;padding:0 1rem;max-width:1000px;color:#17233a;background:#f5f7fb}main,section{background:white;padding:1.5rem;border-radius:12px;margin:1rem 0}a{color:#164db6}label{display:block;margin:1rem 0}input,select,textarea,button{font:inherit;padding:.6rem;max-width:100%;box-sizing:border-box}input:not([type=checkbox]),textarea,select{display:block;width:100%}button{background:#174ea6;color:white;border:0;border-radius:5px;cursor:pointer}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:.7rem;border-bottom:1px solid #ddd;overflow-wrap:anywhere}dd{overflow-wrap:anywhere}.notice{padding:1rem;background:#fff0d5;border-left:4px solid #c68300}.errors{color:#a11124}.muted{color:#526078}
    </style>
</head>
<body>
<header><a href="{{ route('admin.withdrawal-reconciliation.index') }}">Withdrawal reconciliation</a></header>
<main>
    <h1>Provider evidence, independent review</h1>
    <p class="notice">This workflow never sends a transfer. Unknown, timeout, pending or not-found results are not evidence of rejection. Do not clear claims or resend held withdrawals.</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<ul class="errors" role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    @if($item)
        <h2>Case #{{ $item['id'] }} · {{ $item['status'] }}</h2>
        <dl>
            <dt>Withdrawal / Payment</dt><dd>{{ $item['withdrawal_id'] }} / {{ $item['payment_id'] }}</dd>
            <dt>Maker / Checker</dt><dd>{{ $item['maker_id'] }} / {{ $item['checker_id'] ?? 'Not decided' }}</dd>
            @foreach($item['facts'] as $key => $value)<dt>{{ str_replace('_', ' ', $key) }}</dt><dd>{{ $value }}</dd>@endforeach
            <dt>Evidence SHA-256 / bytes</dt><dd>{{ $item['evidence_sha256'] }} / {{ $item['evidence_bytes'] }}</dd>
            <dt>Decision note</dt><dd>{{ $item['decision_note'] ?? 'Not decided' }}</dd>
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
        <table><thead><tr><th>Case</th><th>Withdrawal</th><th>Maker</th><th>State</th></tr></thead><tbody>
        @forelse($cases as $case)<tr><td><a href="{{ route('admin.withdrawal-reconciliation.show', $case) }}">#{{ $case->id }}</a></td><td>{{ $case->withdrawal_id }}</td><td>{{ $case->maker_id }}</td><td>{{ $case->status }}</td></tr>@empty<tr><td colspan="4">No evidence cases.</td></tr>@endforelse
        </tbody></table>
        <p>@if($cases->previousPageUrl())<a href="{{ $cases->previousPageUrl() }}">Previous</a>@endif @if($cases->nextPageUrl())<a href="{{ $cases->nextPageUrl() }}">Next</a>@endif</p>
        <h2>Submit final provider evidence</h2>
        <p>Use the original withdrawal reference. Money must be exact, for example 400.00, with the stored currency. Observation time must be RFC3339 with timezone, no earlier than the dispatch claim. No URL is fetched.</p>
        <form method="post" enctype="multipart/form-data" action="{{ route('admin.withdrawal-reconciliation.store') }}">
            @csrf
            <label>Withdrawal ID<input name="withdrawal_id" type="number" min="1" value="{{ request()->integer('withdrawal_id') ?: '' }}" required></label>
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
