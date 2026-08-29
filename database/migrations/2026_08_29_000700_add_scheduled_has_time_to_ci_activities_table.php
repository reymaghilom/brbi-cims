<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->boolean('scheduled_has_time')->default(true)->after('scheduled_at');
        });

        $this->convertSchedules(config('cims.display_timezone'), 'UTC');
        $this->convertAuditSchedules(config('cims.display_timezone'), 'UTC');
    }

    public function down(): void
    {
        $this->convertSchedules('UTC', config('cims.display_timezone'));
        $this->convertAuditSchedules('UTC', config('cims.display_timezone'));

        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->dropColumn('scheduled_has_time');
        });
    }

    private function convertSchedules(string $sourceTimezone, string $targetTimezone): void
    {
        DB::table('ci_activities')
            ->whereNotNull('scheduled_at')
            ->select(['id', 'scheduled_at'])
            ->orderBy('id')
            ->chunkById(200, function ($activities) use ($sourceTimezone, $targetTimezone): void {
                foreach ($activities as $activity) {
                    $converted = Carbon::parse((string) $activity->scheduled_at, $sourceTimezone)
                        ->timezone($targetTimezone)
                        ->format('Y-m-d H:i:s');

                    DB::table('ci_activities')->where('id', $activity->id)->update([
                        'scheduled_at' => $converted,
                    ]);
                }
            });
    }

    private function convertAuditSchedules(string $sourceTimezone, string $targetTimezone): void
    {
        DB::table('audit_logs')
            ->where('module', 'ci_activities')
            ->whereNotNull('metadata')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($events) use ($sourceTimezone, $targetTimezone): void {
                foreach ($events as $event) {
                    $metadata = json_decode((string) $event->metadata, true);
                    if (! is_array($metadata) || blank($metadata['scheduled_at'] ?? null)) {
                        continue;
                    }

                    $sourceWallClock = Carbon::parse((string) $metadata['scheduled_at'])->format('Y-m-d H:i:s.u');
                    $targetWallClock = Carbon::parse($sourceWallClock, $sourceTimezone)
                        ->timezone($targetTimezone)
                        ->format('Y-m-d H:i:s.u');
                    $metadata['scheduled_at'] = Carbon::parse($targetWallClock, 'UTC')->toISOString();

                    DB::table('audit_logs')->where('id', $event->id)->update([
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }
};
