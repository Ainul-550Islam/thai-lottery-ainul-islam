<!doctype html>
<html lang="en" class="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0B0904">
<title>Player prize claim review</title>
<style @cspNonce>
body{font:15px/1.6 system-ui,-apple-system,sans-serif;background:#0B0904;color:#FFF6D6;margin:0;padding:24px 16px}
.skip-link{position:absolute;left:-9999px;top:0;background:#D4AF37;color:#0B0904;padding:8px 14px;font-weight:700;border-radius:8px;z-index:50}
.skip-link:focus{left:16px;top:16px}
main,header{max-width:950px;margin:16px auto;padding:22px;background:#141007;border:1px solid rgba(212,175,55,.28);border-radius:14px}
a{color:#F5E6B8;text-decoration:underline}
a:hover,a:focus-visible{color:#D4AF37}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;min-width:500px}
td,th{padding:12px;text-align:left;border-bottom:1px solid rgba(212,175,55,.16)}
th{color:#D4AF37;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
label{display:block;margin:14px 0;color:#EDE2BF;font-weight:600}
textarea,select,input{font:inherit;box-sizing:border-box;border-radius:8px}
textarea,select{display:block;width:100%;max-width:100%;margin-top:6px;padding:10px 12px;background:#0B0904;color:#FFF6D6;border:1px solid rgba(212,175,55,.35)}
input[type=checkbox]{width:18px;height:18px;accent-color:#D4AF37;vertical-align:middle;margin-right:8px}
button{min-height:44px;background:linear-gradient(135deg,#F5E6B8,#D4AF37);color:#0B0904;font-weight:800;border:0;padding:12px 20px;border-radius:8px;cursor:pointer}
:focus-visible{outline:2px solid #D4AF37;outline-offset:2px}
.notice{padding:14px;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.35);border-left:4px solid #D4AF37;border-radius:10px;color:#F5E6B8}
.error{padding:12px;background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.45);border-radius:10px;color:#FCA5A5}
.status{padding:12px;background:rgba(52,211,153,.12);border:1px solid rgba(52,211,153,.4);border-radius:10px;color:#A7F3D0}
dt{font-weight:700;color:#D4AF37}
dd{margin:4px 0 12px}
</style>
</head>
<body>
<a class="skip-link" href="#main-content">Skip to main content</a>
<header role="banner">
<nav aria-label="Claim review navigation"><a href="{{ route('admin.player-prize-claims.index') }}">Player claims</a> · <a href="{{ route('admin.payout-approvals.index') }}">Financial maker–checker workflow</a></nav>
</header>
<main id="main-content" tabindex="-1">
<h1>Player prize claim review</h1>
<p class="notice">Review the original owned winning bet and exact prize facts before deciding. This decision reviews the player’s ownership assertion only. It does not approve a destination, send a transfer, or mark a prize paid.</p>
@if(session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
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
<div class="table-wrap"><table><thead><tr><th scope="col">Bet</th><th scope="col">Reference</th><th scope="col">Amount</th><th scope="col">Claim</th><th scope="col">Review</th></tr></thead><tbody>
@forelse($items as $claim)<tr><td>{{ $claim['bet_id'] }}</td><td>{{ $claim['payout_reference'] }}</td><td>{{ $claim['amount'] }} {{ $claim['currency'] }}</td><td>{{ $claim['status'] }}</td><td><a href="{{ route('admin.player-prize-claims.show', $claim['payout_id']) }}">Original claim</a></td></tr>
@empty<tr><td colspan="5">No compatible claims in this page.</td></tr>@endforelse
</tbody></table></div>{{ $rows->links() }}
@endif
</main>
</body>
</html>
