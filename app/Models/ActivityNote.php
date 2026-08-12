<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityNote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['follow_up_needed' => 'boolean'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(CiActivity::class, 'ci_activity_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
