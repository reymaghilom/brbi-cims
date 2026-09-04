<?php

namespace App\Models;

use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResidenceBusinessDocumentation extends Model
{
    use HasFactory, SoftDeletes;

    public const CATEGORY_RESIDENCE = 'residence';

    public const CATEGORY_BUSINESS = 'business';

    protected $guarded = [];

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function coMaker(): BelongsTo
    {
        return $this->belongsTo(CoMaker::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mapScreenshot(): BelongsTo
    {
        return $this->belongsTo(MediaReference::class, 'map_screenshot_media_id');
    }

    /**
     * All local media (pictures and videos) attached to this documentation set — excludes the map
     * screenshot itself, which is tracked separately via mapScreenshot(). The exclusion is expressed
     * as a correlated subquery (rather than checking $this->map_screenshot_media_id directly) so it
     * also applies correctly inside withCount()/withExists(), which resolve this relation against an
     * unhydrated base model instance where that attribute wouldn't yet be set.
     */
    public function media(): HasMany
    {
        return $this->hasMany(MediaReference::class, 'residence_business_documentation_id')
            ->whereNotIn('media_references.id', function ($query) {
                $query->select('map_screenshot_media_id')
                    ->from('residence_business_documentations')
                    ->whereColumn('residence_business_documentations.id', 'media_references.residence_business_documentation_id')
                    ->whereNotNull('map_screenshot_media_id');
            });
    }

    /** Alias matching Laravel's pluralized lookup for the {mediaReference} route parameter — required for Route::scopeBindings() to auto-scope that nested parameter to this documentation set. */
    public function mediaReferences(): HasMany
    {
        return $this->media();
    }

    public function pictures(): HasMany
    {
        return $this->media()->where('media_type', MediaType::Photo->value);
    }

    public function videos(): HasMany
    {
        return $this->media()->where('media_type', MediaType::Video->value);
    }

    public function telegramDeliveries(): HasMany
    {
        return $this->hasMany(DocumentationTelegramDelivery::class);
    }

    public function latestTelegramDelivery(): HasOne
    {
        return $this->hasOne(DocumentationTelegramDelivery::class)->latestOfMany();
    }

    public function isResidence(): bool
    {
        return $this->category === self::CATEGORY_RESIDENCE;
    }

    public function isLegacyBusiness(): bool
    {
        return ! $this->isResidence() && blank($this->business_name);
    }

    public function businessDisplayName(): string
    {
        return filled($this->business_name) ? $this->business_name : 'Legacy / Unassigned';
    }
}
