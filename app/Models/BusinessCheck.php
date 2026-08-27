<?php

namespace App\Models;

use App\Services\ClientFolders\Contracts\HasCiParticipants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCheck extends Model implements HasCiParticipants
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(BusinessCheckPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function photoGroups(): HasMany
    {
        return $this->hasMany(BusinessCheckPhotoGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    public function businessPhotos(): HasMany
    {
        return $this->photos()->where('category', 'business');
    }

    public function competitorPhotos(): HasMany
    {
        return $this->photos()->where('category', 'competitor');
    }

    public function mapScreenshotUploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'map_screenshot_uploaded_by');
    }

    public function hasMapScreenshot(): bool
    {
        return filled($this->map_screenshot_path) || $this->hasCloudMapScreenshot();
    }

    /** True once the saved Map Screenshot was uploaded to Cloudinary (new uploads only — a historical screenshot keeps resolving through `map_screenshot_path` on local storage instead). */
    public function hasCloudMapScreenshot(): bool
    {
        return filled($this->map_screenshot_cloud_public_id);
    }

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_check_contributors')->withPivot('position')->withTimestamps();
    }

    public function ciPrimaryUserId(): ?int
    {
        return $this->ci_user_id;
    }
}
