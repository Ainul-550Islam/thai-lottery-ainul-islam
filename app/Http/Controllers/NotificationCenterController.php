<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Owner-scoped notification center projection.
 *
 * Reads only the authenticated user's notification rows. Mark-as-read remains
 * on the existing API controller; this page does not create a browser-only
 * notification mutation.
 */
final class NotificationCenterController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $records = Notification::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->limit(100)
            ->get([
                'id', 'event_type', 'channel', 'priority', 'status',
                'subject', 'body', 'created_at', 'read_at', 'expires_at',
            ])
            ->map(static fn (Notification $notification): array => [
                'reference' => hash('sha256', 'notification|'.$user->getKey().'|'.$notification->getKey()),
                'type' => $notification->event_type?->value,
                'channel' => $notification->channel?->value,
                'priority' => $notification->priority?->value,
                'status' => $notification->status?->value,
                'subject' => $notification->subject,
                'body' => $notification->body,
                'created_at' => $notification->created_at?->toIso8601String(),
                'read_at' => $notification->read_at?->toIso8601String(),
                'expires_at' => $notification->expires_at?->toIso8601String(),
            ])
            ->all();

        return view('notifications/index', [
            'records' => $records,
            'state' => $records === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }
}
