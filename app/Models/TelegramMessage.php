<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['chat_id_encrypted' => 'encrypted', 'sent_at' => 'datetime'];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function mediaItems(): HasMany
    {
        return $this->hasMany(TelegramMessageMedia::class);
    }
}
