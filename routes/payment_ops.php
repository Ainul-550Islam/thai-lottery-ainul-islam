<?php

declare(strict_types=1);

use App\Services\Operations\ProviderReadinessService;
use App\Services\Payment\ManualDisbursementQueueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:manage-payment-disbursements', 'throttle:30,1'])->prefix('ops/payments')->name('payment_ops.')->group(function (): void {
    Route::get('/readiness', static function (ProviderReadinessService $readiness) {
        $results = $readiness->gateways();
        $failures = array_values(array_filter($results, static fn ($result): bool => $result->enabled && ! $result->ready));
        $realMoneyEnabled = (bool) config('real_money.enabled', false);
        $readyEnabled = array_filter($results, static fn ($result): bool => $result->enabled && $result->ready);
        $ready = $failures === [] && (! $realMoneyEnabled || $readyEnabled !== []);

        return response()->json([
            'ready' => $ready,
            'real_money_enabled' => $realMoneyEnabled,
            'gateways' => array_values($results),
        ], $ready ? 200 : 503);
    })->name('readiness');

    Route::get('/manual-disbursements', static function (Request $request) {
        $status = $request->query('status');
        $query = DB::table('manual_disbursement_tasks')->orderBy('sla_due_at')->orderBy('id');
        if (is_string($status) && in_array($status, ['pending', 'claimed', 'completed'], true)) {
            $query->where('status', $status);
        }
        $tasks = $query->limit(100)->get([
            'id', 'withdrawal_id', 'method', 'status', 'claimed_by', 'proof_reference',
            'sla_due_at', 'claimed_at', 'completed_at', 'last_notified_at', 'notification_count',
        ]);

        return response()->json(['tasks' => $tasks]);
    })->name('manual-disbursements.index');

    Route::post('/manual-disbursements/{task}/claim', static function (Request $request, int $task, ManualDisbursementQueueService $queue) {
        $key = (string) $request->header('Idempotency-Key', '');
        $result = $queue->claim($task, (int) $request->user()->getKey(), $key);

        return response()->json(['task' => $result]);
    })->name('manual-disbursements.claim');

    Route::post('/manual-disbursements/{task}/complete', static function (Request $request, int $task, ManualDisbursementQueueService $queue) {
        $validated = $request->validate(
            ['proof_reference' => ['required', 'string', 'max:190']],
            ['proof_reference.required' => trans('payment_ops.errors.manual_disbursement_proof_invalid'), 'proof_reference.max' => trans('payment_ops.errors.manual_disbursement_proof_invalid')],
        );
        $key = (string) $request->header('Idempotency-Key', '');
        $result = $queue->complete($task, (int) $request->user()->getKey(), (string) $validated['proof_reference'], $key);

        return response()->json(['task' => $result]);
    })->name('manual-disbursements.complete');
});
