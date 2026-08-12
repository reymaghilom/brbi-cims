<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramMessageMedia extends Model
{
    protected $guarded = [];

    public function telegramMessage(): BelongsTo
    {
        return $this->belongsTo(TelegramMessage::class);
    }

    public function mediaReference(): BelongsTo
    {
        return $this->belongsTo(MediaReference::class);
    }
}
