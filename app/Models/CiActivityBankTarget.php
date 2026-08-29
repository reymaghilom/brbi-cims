<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CiActivityBankTarget extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ActivityStatus::class,
            'scheduled_at' => 'datetime',
            'scheduled_has_time' => 'boolean',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(CiActivity::class, 'ci_activity_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array{0: Carbon|null, 1: bool} */
    public static function normalizeScheduleInput(ActivityStatus $status, mixed $date, mixed $time = null): array
    {
        if (! in_array($status, [ActivityStatus::Scheduled, ActivityStatus::FollowUp], true) || blank($date)) {
            return [null, false];
        }

        return CiActivity::normalizeScheduleInput($date, $time);
    }

    public static function deriveParentStatus(iterable $statuses): ActivityStatus
    {
        $validStatuses = [];

        foreach ($statuses as $status) {
            $status = $status instanceof ActivityStatus
                ? $status
                : (is_string($status) ? ActivityStatus::tryFrom($status) : null);

            if ($status !== null) {
                $validStatuses[] = $status;
            }
        }

        if ($validStatuses !== [] && collect($validStatuses)->every(fn (ActivityStatus $status): bool => $status === ActivityStatus::Completed)) {
            return ActivityStatus::Completed;
        }

        if (in_array(ActivityStatus::FollowUp, $validStatuses, true)) {
            return ActivityStatus::FollowUp;
        }

        if (in_array(ActivityStatus::Scheduled, $validStatuses, true)) {
            return ActivityStatus::Scheduled;
        }

        return ActivityStatus::Pending;
    }
}
