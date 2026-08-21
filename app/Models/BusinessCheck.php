<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCheck extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ci_date' => 'date'];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function coMaker(): BelongsTo
    {
        return $this->belongsTo(CoMaker::class);
    }

    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ci_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(BusinessCheckPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function businessPhotos(): HasMany
    {
        return $this->photos()->where('category', 'business');
    }

    public function competitorPhotos(): HasMany
    {
        return $this->photos()->where('category', 'competitor');
    }
}
