<?php

namespace App\Http\Controllers;

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
 */
class CiActivityNotificationFeedController extends Controller
{
    public function __invoke(Request $request, ProcessDueCiActivityReminders $processor): JsonResponse
    {
        $user = $request->user();

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
