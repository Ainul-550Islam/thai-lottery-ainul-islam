<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payout approval intake</title>
<style>body{font:16px system-ui;max-width:1100px;margin:32px auto;padding:20px;background:#f3f6fa;color:#172d44}main,section{background:white;border:1px solid #d6e0eb;padding:24px;border-radius:12px;margin:20px 0}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:12px;border-bottom:1px solid #ddd}a{color:#165b91}label{display:block;margin:14px 0}input,textarea,select,button{font:inherit;padding:9px;box-sizing:border-box;max-width:100%}textarea{display:block;width:100%}button{background:#163f69;color:white;border:0;border-radius:5px;cursor:pointer}.notice{padding:16px;background:#fff2ce;border-left:4px solid #ba8d25}</style></head><body>
<header><a href="{{ route('admin.payout-approvals.index') }}">Payout approval intake</a> · <a href="{{ route('admin.payout-transfers.index') }}">External final-result review</a> · @can('review player prize claims')<a href="{{ route('admin.player-prize-claims.index') }}">Player assertion review</a>@endcan<h1>Maker–checker payout workflow</h1></header>
<p class="notice">Request and approval never pay a prize. Amount, currency and beneficiary identity come from the persisted obligation. Batch routing credits only approved wallet prizes; bank/cheque routing opens records only. Unknown outcomes stay held.</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($rows)
<main><h2>Recorded obligations</h2><form method="post" action="{{ route('admin.payout-approvals.create-batch') }}">@csrf
<table><thead><tr><th>Select</th><th>Payout</th><th>Amount</th><th>Status</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td><input type="checkbox" name="payout_ids[]" value="{{ $row->id }}" aria-label="Select payout {{ $row->id }}"></td><td><a href="{{ route('admin.payout-approvals.show', $row) }}">{{ $row->reference_number }}</a></td><td>{{ $row->amount }} {{ $row->currency->value }}</td><td>{{ $row->status->label() }}</td></tr>
@empty<tr><td colspan="4">No payout obligations.</td></tr>@endforelse</tbody></table>
@can('route payout batches')<p>Select only approved, unassigned obligations with the same currency and method.</p><button type="submit">Create immutable batch — do not execute</button>@endcan
</form>{{ $rows->links() }}</main>
@endif
@if($item)
<main><h2>{{ $item['payout_reference'] }}</h2><p>{{ $item['amount'] }} {{ $item['currency'] }} · {{ $item['status'] }} · Approval: {{ $item['approval_status'] ?? 'not requested' }}</p>
@if(!$item['approval_status'])
@can('request payout approvals')
@foreach(['wallet' => 'Original wallet', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque'] as $method => $label)
<section><h3>Request: {{ $label }}</h3><form method="post" action="{{ route('admin.payout-approvals.store') }}">@csrf
<input type="hidden" name="payout_id" value="{{ $item['id'] }}"><input type="hidden" name="method" value="{{ $method }}">
@if($method === 'bank_transfer')
<label>Bank name<input name="beneficiary[bank_name]" required maxlength="500" autocomplete="off"></label>
<label>Original account name<input name="beneficiary[account_name]" required maxlength="500" autocomplete="off"></label>
<label>Original account number<input name="beneficiary[account_number]" required maxlength="500" autocomplete="off"></label>
@elseif($method === 'cheque')
<label>Original payee name<input name="beneficiary[payee_name]" required maxlength="500" autocomplete="off"></label>
<label>Original payee address<input name="beneficiary[address]" required maxlength="500" autocomplete="off"></label>
@endif
<label>Review rationale, no credentials/personal data<textarea name="note" minlength="15" maxlength="1000" required></textarea></label><button type="submit">Submit immutable maker request</button></form></section>
@endforeach
@endcan
@endif
@if($item['approval_status'] === 'pending')
@can('review payout approvals')
<p><a href="{{ route('admin.payout-approvals.destination', $item['id']) }}" rel="noreferrer">Inspect original private destination (audited JSON)</a></p>
<form method="post" action="{{ route('admin.payout-approvals.decide', $item['id']) }}">@csrf
<label>Independent verdict<select name="decision" required><option value="">Choose</option><option value="approved">Approve original facts</option><option value="rejected">Reject original request</option></select></label>
<label>Review note<textarea name="note" minlength="15" maxlength="1000" required></textarea></label>
<label><input type="checkbox" name="original_facts_verified" value="1" required>I independently reviewed the original beneficiary, method, amount and currency.</label>
<button type="submit">Record checker verdict — do not execute</button></form>
@endcan
@endif
</main>
@endif
@if($batch)
<main><h2>Batch #{{ $batch['id'] }}</h2><p>{{ $batch['method'] }} · {{ $batch['currency'] }} · {{ $batch['status'] }}</p>
<p>{{ $batch['processed_count'] }} / {{ $batch['member_count'] }} terminal; {{ $batch['paid_count'] }} paid; {{ $batch['failed_count'] }} definitively refused.</p><p>Canonical paid amount: {{ $batch['paid_amount'] }} / {{ $batch['aggregate_amount'] }} {{ $batch['currency'] }}</p>
@can('route payout batches')
@if($batch['status'] === 'pending')<form method="post" action="{{ route('admin.payout-approvals.route-batch', $batch['id']) }}">@csrf<button type="submit">Atomically claim and queue correct executor</button></form>@endif
<form method="post" action="{{ route('admin.payout-approvals.refresh-batch', $batch['id']) }}">@csrf<button type="submit">Refresh canonical outcomes / finalize eligible batch</button></form>
@endcan
<p>External records are independently confirmed through the external review console. Refresh never sends money or treats a timeout as final refusal.</p></main>
@endif
<footer>Two active authorized people are required for maker/checker. No reset, reassignment or automatic resend action exists. Sensitive form validation failures do not retain the original destination in old input.</footer>
</body></html>
