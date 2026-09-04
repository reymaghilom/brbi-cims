<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Services\Notifications\ScheduledTodayNotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small, focused endpoint the header bell polls every ~30 seconds so newly
 * created scheduled/due notifications can appear without a full page
 * refresh. ScheduledTodayNotificationFeed remains the single authoritative
 * source for matching/scoping/isolation — this controller only renders it.
 *
 * A GET request: read-only, never marks anything read and never creates a
 * notification row.
 */
class CiActivityNotificationFeedController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== UserRole::CreditInvestigator) {
            return response()->json([
                'html' => '',
                'unread_count' => 0,
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
