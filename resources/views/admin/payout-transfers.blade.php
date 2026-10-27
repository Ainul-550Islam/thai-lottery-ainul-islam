<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>External prize transfers</title>
<style>body{font:16px system-ui;background:#f4f7fb;color:#15283e;max-width:1000px;margin:40px auto;padding:20px}main,section{background:white;border:1px solid #dbe3ef;border-radius:12px;padding:24px;margin:18px 0}a{color:#175f9e}label{display:block;margin:16px 0}input,select,textarea,button{font:inherit;padding:10px;max-width:100%;box-sizing:border-box}textarea{display:block;width:100%}button{background:#163d6a;color:white;border:0;border-radius:6px;cursor:pointer}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #ddd}.notice{border-left:4px solid #b88a24;padding:16px;background:#fff7de}</style></head><body>
<header><a href="{{ route('admin.payout-transfers.index') }}">External prize transfers</a><h1>Independent transfer review</h1></header>
<p class="notice">Record-only workflow: opening does not send a bank transfer or issue a cheque. Confirm only independently verified final provider results. Unknown or not-found outcomes remain open. Never reset a claim or resend.</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($rows)
<main><table><thead><tr><th>Payout</th><th>Amount</th><th>Status</th><th>Review</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td>{{ $row->reference_number }}</td><td>{{ $row->amount }} {{ $row->currency->value }}</td><td>{{ $row->status->label() }}</td><td><a href="{{ route('admin.payout-transfers.show', $row) }}">Open</a></td></tr>
@empty<tr><td colspan="4">No opened external prize lanes.</td></tr>@endforelse
</tbody></table>{{ $rows->links() }}</main>
@endif
@if($item)
<main><h2>{{ $item['payout_reference'] }}</h2><p>{{ $item['amount'] }} {{ $item['currency'] }} · {{ $item['status'] }} · {{ $item['lane_status'] ?? 'not opened' }}</p>
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
</main>
@endif
<footer>Current active granular staff permissions are checked again in the service. A maker or beneficiary cannot confirm their own external prize transfer. Reference labels and local accounting are not proof of bank delivery.</footer>
</body></html>
