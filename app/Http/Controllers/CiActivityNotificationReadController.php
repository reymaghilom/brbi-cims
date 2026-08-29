<?php

namespace App\Http\Controllers;

use App\Models\CiActivity;
use App\Notifications\CiActivityScheduledReminder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CiActivityNotificationReadController extends Controller
{
    public function __invoke(Request $request, string $notification): RedirectResponse
    {
        $databaseNotification = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->where('type', CiActivityScheduledReminder::class)
            ->firstOrFail();

        $activity = CiActivity::query()
            ->whereKey((int) data_get($databaseNotification->data, 'ci_activity_id'))
            ->where('client_folder_id', (int) data_get($databaseNotification->data, 'client_folder_id'))
            ->firstOrFail();

        abort_unless($activity->creator_id === $request->user()->id, 404);

        $databaseNotification->markAsRead();

        return redirect()->route('client-folders.activities.index', [
            $activity->client_folder_id,
        ] + ($activity->co_maker_id ? [
            'person' => 'co-maker',
            'co_maker_id' => $activity->co_maker_id,
        ] : []));
    }
}
