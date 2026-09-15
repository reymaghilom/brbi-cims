<?php

use App\Services\ClientFolders\ClientFolderFileCleanup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('file-cleanup:retry {--limit=100}', function (ClientFolderFileCleanup $cleanup): int {
    $limit = max(1, (int) $this->option('limit'));
    $attempted = $cleanup->retryPending($limit);
    $pending = DB::table('pending_file_cleanups')->count();
    $this->info("Attempted {$attempted} cleanup task(s); {$pending} remain pending.");

    return 0;
})->purpose('Retry durable post-commit file cleanup tasks');

Schedule::command('ci-activities:send-reminders')->everyMinute()->withoutOverlapping();
