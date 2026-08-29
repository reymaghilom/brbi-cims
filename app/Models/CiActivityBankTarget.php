<?php

namespace App\Models;

use App\Enums\ActivityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
