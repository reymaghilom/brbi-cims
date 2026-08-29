<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class CiActivity extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ActivityStatus::class,
            'visit_date' => 'date',
            'scheduled_at' => 'datetime',
            'scheduled_has_time' => 'boolean',
            'reminder_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ActivityDefinition::class, 'activity_definition_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function coMaker(): BelongsTo
    {
        return $this->belongsTo(CoMaker::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function assignedInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_ci_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ActivityNote::class);
    }

    public function mediaReferences(): BelongsToMany
    {
        return $this->belongsToMany(MediaReference::class, 'activity_media')->withPivot('label')->withTimestamps();
    }

    public function scopeScheduledTodayForCreator(Builder $query, User|int $creator, ?string $timezone = null): Builder
    {
        $timezone ??= config('cims.display_timezone');
        $today = now($timezone);

        return $query
            ->where('creator_id', $creator instanceof User ? $creator->id : $creator)
            ->where('status', ActivityStatus::Scheduled)
            ->whereNull('completed_at')
            ->whereBetween('scheduled_at', [
                $today->copy()->startOfDay()->utc(),
                $today->copy()->endOfDay()->utc(),
            ]);
    }

    /** @return array{0: Carbon|null, 1: bool} */
    public static function normalizeScheduleInput(mixed $dateOrDateTime, mixed $time = null, ?string $timezone = null): array
    {
        if (blank($dateOrDateTime)) {
            return [null, true];
        }

        $timezone ??= config('cims.display_timezone');
        $value = trim((string) $dateOrDateTime);
        $isDateOnly = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        $hasTime = ! $isDateOnly || filled($time);
        $localSchedule = $isDateOnly
            ? Carbon::createFromFormat('!Y-m-d H:i', $value.' '.($hasTime ? trim((string) $time) : '08:00'), $timezone)
            : Carbon::parse($value, $timezone);

        return [$localSchedule->utc(), $hasTime];
    }
}
