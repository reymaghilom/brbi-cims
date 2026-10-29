<?php

namespace App\Http\Controllers;

use App\Services\Integrations\N8n\ReminderOutboxDispatcher;
use App\Services\Notifications\ProcessDueCiActivityReminders;
use App\Services\Notifications\ScheduledTodayNotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Small, focused endpoint the header bell polls every ~30 seconds so newly
 * created scheduled/due notifications can appear without a full page
 * refresh. ScheduledTodayNotificationFeed remains the authoritative source
 * for matching/scoping/isolation after the throttled processor pass.
 *
 * Its request-driven reminder pass is a no-cron fallback. Database state is
 * still the authoritative duplicate guard; the cache key only avoids doing
 * the same due-item query on every poll.
 *
 * The same request also gives the n8n reminder outbox a bounded no-cron retry pass: at most one
 * pass per minute across ALL users (global cache key), at most OUTBOX_EVENTS_PER_PASS events,
 * each bounded by the dispatcher's own timeout. The dispatcher's row-locked claim keeps
 * overlapping passes safe, and superseded versions are never sent.
 */
class CiActivityNotificationFeedController extends Controller
{
    private const OUTBOX_EVENTS_PER_PASS = 2;

    public function __invoke(Request $request, ProcessDueCiActivityReminders $processor, ReminderOutboxDispatcher $outbox): JsonResponse
    {
        $user = $request->user();

        // Runs for every signed-in bell poll (not only CI users), so pending schedule changes and
        // cancellations are retried whenever anyone has the app open.
        try {
            if (config('services.n8n_reminders.enabled')
                && Cache::add('n8n-reminders:outbox-feed-pass', true, now()->addMinute())) {
                $outbox->deliverPending(self::OUTBOX_EVENTS_PER_PASS);
            }
        } catch (Throwable $exception) {
            Log::error('n8n reminder outbox fallback failed.', [
                'exception' => $exception,
            ]);
        }

        if (! $user->role->worksAsCreditInvestigator()) {
            return response()->json([
                'html' => '',
                'unread_count' => 0,
            ]);
        }

        try {
            if (Cache::add('ci-activity-reminders:feed-user:'.$user->id, true, now()->addMinute())) {
                $processor->process($user);
            }
        } catch (Throwable $exception) {
            Log::error('Notification bell reminder fallback failed.', [
                'user_id' => $user->id,
                'exception' => $exception,
            ]);
        }

        $feed = ScheduledTodayNotificationFeed::build($user);

        return response()->json([
            'html' => view('partials.notifications.scheduled-today-panel', [
                'scheduledTodayCount' => $feed->items->count(),
                'scheduledTodayItems' => $feed->items,
                'scheduledTodayNotifications' => $feed->notificationsByKey,
            ])->render(),
            'unread_count' => $feed->unreadCount,
        ]);
    }
}
