<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payment\ManualDisbursementQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class PaymentsManualDisbursementSweepCommand extends Command
{
    protected $signature = 'payments:manual-disbursement-sweep';

    protected $description = 'Notify operators about manual payout tasks past their SLA; never moves money.';

    public function handle(ManualDisbursementQueueService $queue): int
    {
        $email = trim((string) config('payment_ops.manual_disbursement.notification_email', ''));
        $tasks = $queue->overdueForSweep((int) config('payment_ops.manual_disbursement.sweep_batch_size', 100));
        foreach ($tasks as $task) {
            $subject = __('payment_ops.manual_disbursement_overdue_subject', ['id' => $task->id]);
            $body = __('payment_ops.manual_disbursement_overdue_body', [
                'id' => $task->id,
                'withdrawal' => $task->withdrawal_id,
                'method' => $task->method,
                'due' => $task->sla_due_at,
            ]);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                Mail::raw($body, static fn ($message) => $message->to($email)->subject($subject));
            } else {
                Log::critical('Manual disbursement task is overdue and operator email is not configured.', [
                    'task_id' => (int) $task->id,
                    'withdrawal_id' => (int) $task->withdrawal_id,
                    'method' => $task->method,
                ]);
            }
            $queue->recordNotification((int) $task->id);
        }
        $this->info(__('payment_ops.manual_disbursement_sweep_complete', ['count' => count($tasks)]));

        return self::SUCCESS;
    }
}
