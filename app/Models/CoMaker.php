<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoMaker extends Model
{
    use HasFactory;

    protected $guarded = [];

    /** Mirrors the column default so a just-created Co-Maker reports its first revision. */
    protected $attributes = ['revision' => 1];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }
}
