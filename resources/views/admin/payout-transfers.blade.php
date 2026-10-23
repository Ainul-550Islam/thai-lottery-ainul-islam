<!doctype html>
<html lang="en" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0B0904">
<title>External prize transfers</title>
<style @cspNonce>
body{font:15px/1.6 system-ui,-apple-system,sans-serif;background:#0B0904;color:#FFF6D6;max-width:1000px;margin:0 auto;padding:24px 16px}
.skip-link{position:absolute;left:-9999px;top:0;background:#D4AF37;color:#0B0904;padding:8px 14px;font-weight:700;border-radius:8px;z-index:50}
.skip-link:focus{left:16px;top:16px}
header,footer,section,.panel{background:#141007;border:1px solid rgba(212,175,55,.28);border-radius:14px;padding:20px;margin:16px 0}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:460px}
th,td{text-align:left;padding:12px;border-bottom:1px solid rgba(212,175,55,.16)}
th{color:#D4AF37;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
a{color:#F5E6B8;text-decoration:underline}
a:hover,a:focus-visible{color:#D4AF37}
label{display:block;margin:14px 0;color:#EDE2BF;font-weight:600}
input,select,textarea,button{font:inherit;padding:10px 12px;max-width:100%;box-sizing:border-box;border-radius:8px}
input:not([type=checkbox]),select,textarea{display:block;width:100%;margin-top:6px;background:#0B0904;color:#FFF6D6;border:1px solid rgba(212,175,55,.35)}
input[type=checkbox]{width:18px;height:18px;accent-color:#D4AF37;vertical-align:middle;margin-right:8px}
button{min-height:44px;background:linear-gradient(135deg,#F5E6B8,#D4AF37);color:#0B0904;font-weight:800;border:0;cursor:pointer;padding:10px 18px}
:focus-visible{outline:2px solid #D4AF37;outline-offset:2px}
.notice{border:1px solid rgba(212,175,55,.35);border-left:4px solid #D4AF37;padding:16px;background:rgba(212,175,55,.1);border-radius:10px;color:#F5E6B8}
.alert-error{padding:14px;background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.45);border-radius:10px;color:#FCA5A5}
.alert-status{padding:14px;background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.4);border-radius:10px;color:#A7F3D0}
</style>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header role="banner">
<nav aria-label="External transfer navigation"><a href="{{ route('admin.payout-transfers.index') }}">External prize transfers</a></nav>
<h1>Independent transfer review</h1>
</header>
<main id="main-content" tabindex="-1">
<p class="notice">Record-only workflow: opening does not send a bank transfer or issue a cheque. Confirm only independently verified final provider results. Unknown or not-found outcomes remain open. Never reset a claim or resend.</p>
@if(session('status'))<p class="alert-status" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="alert-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($rows)
<section class="panel"><div class="table-wrap"><table><thead><tr><th scope="col">Payout</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Review</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row->reference_number }}</td><td>{{ $row->amount }} {{ $row->currency->value }}</td><td>{{ $row->status->label() }}</td><td><a href="{{ route('admin.payout-transfers.show', $row) }}">Open</a></td></tr>
@empty<tr><td colspan="4">No opened external prize lanes.</td></tr>@endforelse
</tbody></table></div>{{ $rows->links() }}</section>
@endif
@if($item)
<section class="panel"><h2>{{ $item['payout_reference'] }}</h2><p>{{ $item['amount'] }} {{ $item['currency'] }} · {{ $item['status'] }} · {{ $item['lane_status'] ?? 'not opened' }}</p>
<p>The original approved destination is private and every inspection is audited. Verify beneficiary, method, currency, exact amount and actual provider settlement before confirming.</p>
@can('review payout transfers')
<p><a href="{{ route('admin.payout-transfers.destination', $item['id']) }}" rel="noreferrer">Inspect original private destination (JSON)</a></p>
@if(!$item['lane_status'])<form method="post" action="{{ route('admin.payout-transfers.open', $item['id']) }}">@csrf<button type="submit">Open record only — do not send</button></form>@endif

<section><h3>Private final-result archive</h3><p>Upload does not authenticate a provider. A different authorized person must download, inspect and independently verify the selected file within 24 hours before recording a final result. Evidence is retained on legal hold; no public URL or automatic deletion exists.</p>
@can('upload payout evidence')
@if(in_array($item['lane_status'], [null, 'open'], true))
<form method="post" enctype="multipart/form-data" action="{{ route('admin.payout-transfers.evidence.store', $item['id']) }}">@csrf
<label>Private document (PDF, JSON or plain text, maximum 2 MiB)<input type="file" name="evidence" required accept=".pdf,.json,.txt"></label>
<label>Archival rationale, no credentials or personal data<textarea name="note" minlength="15" maxlength="1000" required></textarea></label><button type="submit">Archive private evidence — no payment</button></form>
@endif
@endcan
@can('read payout evidence')
<ul>@forelse(($archives ?? []) as $archive)<li>Archive #{{ $archive['id'] }} · {{ $archive['bytes'] }} bytes · {{ $archive['consumed'] ? 'attached to final result' : 'not consumed' }} · <a href="{{ route('admin.payout-transfers.evidence.download', [$item['id'], $archive['id']]) }}" rel="noreferrer">Download private attachment (audited)</a></li>
@empty<li>No readable archives in this bounded list. Archive evidence before final review.</li>@endforelse</ul>
@endcan
</section>
@if($item['lane_status'] === 'open')
<form method="post" action="{{ route('admin.payout-transfers.decide', $item['id']) }}">@csrf
<label>Exact inspected archive ID<input type="number" min="1" name="evidence_id" required></label>
<label>Final independently verified outcome <select name="outcome" required><option value="">Choose final result</option><option value="completed">Completed externally</option><option value="failed">Definitively refused externally</option></select></label>
<label>Exact provider reference (completed only) <input name="provider_reference" maxlength="255" autocomplete="off"></label>
<label>Review note (15–1000 characters; do not include credentials or personal data)<textarea name="note" minlength="15" maxlength="1000" required></textarea></label>
<label><input type="checkbox" name="evidence_verified" value="1" required> I independently verified the original destination and a final provider result. This is not an unknown or not-found result.</label>
<button type="submit">Record independent final result</button></form>
@endif
@endcan
</section>
@endif
</main>
<footer role="contentinfo">Current active granular staff permissions are checked again in the service. A maker or beneficiary cannot confirm their own external prize transfer. Reference labels and local accounting are not proof of bank delivery.</footer>
</body>
</html>
