<!doctype html>
<html lang="en" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0B0904">
<title>Payout approval intake</title>
<style @cspNonce>
body{font:15px/1.6 system-ui,-apple-system,sans-serif;max-width:1100px;margin:0 auto;padding:24px 16px;background:#0B0904;color:#FFF6D6}
.skip-link{position:absolute;left:-9999px;top:0;background:#D4AF37;color:#0B0904;padding:8px 14px;font-weight:700;border-radius:8px;z-index:50}
.skip-link:focus{left:16px;top:16px}
header,footer,section,.panel{background:#141007;border:1px solid rgba(212,175,55,.28);padding:20px;border-radius:14px;margin:16px 0}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:480px}
td,th{text-align:left;padding:12px;border-bottom:1px solid rgba(212,175,55,.16)}
th{color:#D4AF37;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
a{color:#F5E6B8;text-decoration:underline}
a:hover,a:focus-visible{color:#D4AF37}
label{display:block;margin:14px 0;color:#EDE2BF;font-weight:600}
input,textarea,select,button{font:inherit;padding:10px 12px;box-sizing:border-box;max-width:100%;border-radius:8px}
input:not([type=checkbox]),textarea,select{display:block;width:100%;margin-top:6px;background:#0B0904;color:#FFF6D6;border:1px solid rgba(212,175,55,.35)}
input[type=checkbox]{width:18px;height:18px;accent-color:#D4AF37;vertical-align:middle;margin-right:8px}
button{min-height:44px;background:linear-gradient(135deg,#F5E6B8,#D4AF37);color:#0B0904;font-weight:800;border:0;cursor:pointer;padding:10px 18px}
:focus-visible{outline:2px solid #D4AF37;outline-offset:2px}
.notice{padding:16px;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.35);border-left:4px solid #D4AF37;border-radius:10px;color:#F5E6B8}
.alert-error{padding:14px;background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.45);border-radius:10px;color:#FCA5A5}
.alert-status{padding:14px;background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.4);border-radius:10px;color:#A7F3D0}
</style>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header role="banner">
<nav aria-label="Payout workflow navigation">
<a href="{{ route('admin.payout-approvals.index') }}">Payout approval intake</a> ·
<a href="{{ route('admin.payout-transfers.index') }}">External final-result review</a>
@can('review player prize claims') · <a href="{{ route('admin.player-prize-claims.index') }}">Player assertion review</a>@endcan
</nav>
<h1>Maker–checker payout workflow</h1>
</header>
<main id="main-content" tabindex="-1">
<p class="notice">Request and approval never pay a prize. Amount, currency and beneficiary identity come from the persisted obligation. Batch routing credits only approved wallet prizes; bank/cheque routing opens records only. Unknown outcomes stay held.</p>
@if(session('status'))<p class="alert-status" role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div class="alert-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($rows)
<section class="panel"><h2>Recorded obligations</h2><form method="post" action="{{ route('admin.payout-approvals.create-batch') }}">@csrf
<div class="table-wrap"><table><thead><tr><th scope="col">Select</th><th scope="col">Payout</th><th scope="col">Amount</th><th scope="col">Status</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td><input type="checkbox" name="payout_ids[]" value="{{ $row->id }}" aria-label="Select payout {{ $row->id }}"></td><td><a href="{{ route('admin.payout-approvals.show', $row) }}">{{ $row->reference_number }}</a></td><td>{{ $row->amount }} {{ $row->currency->value }}</td><td>{{ $row->status->label() }}</td></tr>
@empty<tr><td colspan="4">No payout obligations.</td></tr>@endforelse</tbody></table></div>
@can('route payout batches')<p>Select only approved, unassigned obligations with the same currency and method.</p><button type="submit">Create immutable batch — do not execute</button>@endcan
</form>{{ $rows->links() }}</section>
@endif
@if($item)
<section class="panel"><h2>{{ $item['payout_reference'] }}</h2><p>{{ $item['amount'] }} {{ $item['currency'] }} · {{ $item['status'] }} · Approval: {{ $item['approval_status'] ?? 'not requested' }}</p>
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
</section>
@endif
@if($batch)
<section class="panel"><h2>Batch #{{ $batch['id'] }}</h2><p>{{ $batch['method'] }} · {{ $batch['currency'] }} · {{ $batch['status'] }}</p>
<p>{{ $batch['processed_count'] }} / {{ $batch['member_count'] }} terminal; {{ $batch['paid_count'] }} paid; {{ $batch['failed_count'] }} definitively refused.</p><p>Canonical paid amount: {{ $batch['paid_amount'] }} / {{ $batch['aggregate_amount'] }} {{ $batch['currency'] }}</p>
@can('route payout batches')
@if($batch['status'] === 'pending')<form method="post" action="{{ route('admin.payout-approvals.route-batch', $batch['id']) }}">@csrf<button type="submit">Atomically claim and queue correct executor</button></form>@endif
<form method="post" action="{{ route('admin.payout-approvals.refresh-batch', $batch['id']) }}">@csrf<button type="submit">Refresh canonical outcomes / finalize eligible batch</button></form>
@endcan
<p>External records are independently confirmed through the external review console. Refresh never sends money or treats a timeout as final refusal.</p></section>
@endif
</main>
<footer role="contentinfo">Two active authorized people are required for maker/checker. No reset, reassignment or automatic resend action exists. Sensitive form validation failures do not retain the original destination in old input.</footer>
</body>
</html>
