<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /**
     * `created_at` is a DB-level `CURRENT_TIMESTAMP` default (AuditLog has no PHP-side timestamps
     * to set it explicitly), evaluated by MySQL under this connection's own session time_zone —
     * now forced to UTC via config/database.php's mysql `timezone` setting, matching APP_TIMEZONE
     * (UTC) and the same "stored UTC, converted to Asia/Manila only at display time" convention
     * every other timestamp in this app already follows (e.g. `$model->updated_at->timezone(...)`
     * in Recent Activity/module cards). The raw value is genuine UTC, not already-Manila wall
     * clock — parsing it as UTC and converting once here keeps every caller correct without
     * needing its own conversion.
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Carbon::parse($value, 'UTC')->timezone(config('cims.display_timezone')) : null,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }
}
