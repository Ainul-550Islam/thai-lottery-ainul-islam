<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Player prize claim review</title>
<style>body{font:16px/1.6 system-ui,sans-serif;background:#f1f5f9;color:#172033;margin:0}main{max-width:950px;margin:40px auto;padding:28px;background:white;border-radius:12px}a{color:#145cc5}table{width:100%;border-collapse:collapse}td,th{padding:12px;text-align:left;border-bottom:1px solid #dbe2ea}label{display:block;margin:16px 0}textarea,select{display:block;max-width:100%;padding:8px}button{background:#174a8b;color:white;border:0;padding:12px 20px;border-radius:6px}.notice{padding:14px;background:#fff8db}.error{color:#9f1239}dt{font-weight:700}dd{margin-bottom:12px}</style></head>
<body><main>
<nav><a href="{{ route('admin.player-prize-claims.index') }}">Player claims</a> · <a href="{{ route('admin.payout-approvals.index') }}">Financial maker–checker workflow</a></nav>
<h1>Player prize claim review</h1>
<p class="notice">Review the original owned winning bet and exact prize facts before deciding. This decision reviews the player’s ownership assertion only. It does not approve a destination, send a transfer, or mark a prize paid.</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
@if($item)
<dl><dt>Original bet</dt><dd>{{ $item['bet_id'] }}</dd><dt>Payout reference</dt><dd>{{ $item['payout_reference'] }}</dd><dt>Original amount</dt><dd>{{ $item['amount'] }} {{ $item['currency'] }}</dd><dt>Claim status</dt><dd>{{ $item['status'] }}</dd><dt>Claimed at</dt><dd>{{ $item['claimed_at'] }}</dd><dt>Claim deadline</dt><dd>{{ $item['window_closes_at'] }}</dd></dl>
@if(in_array($item['status'], ['submitted','under_review'], true) && $item['window_open'])
<form method="post" action="{{ route('admin.player-prize-claims.decide', $item['payout_id']) }}">@csrf
<label>Decision<select name="decision" required><option value="">Select a decision</option><option value="approved">Approve player assertion</option><option value="rejected">Reject player assertion</option></select></label>
<label>Review rationale (15–1000 characters; stored as a hash, never enter banking credentials)<textarea name="note" minlength="15" maxlength="1000" rows="4" required></textarea></label>
<label><input type="checkbox" name="original_facts_verified" value="1" required> I independently checked the original owned winning bet and exact amount and currency.</label>
<button type="submit">Record claim review</button></form>
@else<p>No review action is available for this claim.</p>@endif
@else
<p>The list is paginated by candidate ID. Invalid historical snapshots are withheld for controlled reconciliation, not repaired or approved here.</p>
<table><thead><tr><th>Bet</th><th>Reference</th><th>Amount</th><th>Claim</th><th>Review</th></tr></thead><tbody>
@forelse($items as $claim)<tr><td>{{ $claim['bet_id'] }}</td><td>{{ $claim['payout_reference'] }}</td><td>{{ $claim['amount'] }} {{ $claim['currency'] }}</td><td>{{ $claim['status'] }}</td><td><a href="{{ route('admin.player-prize-claims.show', $claim['payout_id']) }}">Original claim</a></td></tr>
@empty<tr><td colspan="5">No compatible claims in this page.</td></tr>@endforelse
</tbody></table>{{ $rows->links() }}
@endif
</main></body></html>
