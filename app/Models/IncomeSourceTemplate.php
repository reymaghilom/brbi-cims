<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncomeSourceTemplate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['compatibility_tags' => 'array', 'is_fallback' => 'boolean', 'is_active' => 'boolean'];
    }

    /**
     * Business Report templates that deliberately have NO Business Name input, mapped to the one
     * business name their records always carry. The template IS the business identity for these
     * six, so the name is derived rather than typed — which is why this map is the single place it
     * lives: the authoritative save stores it (see SaveBusinessIncomeSource / CreateIncomeSource)
     * and the read paths derive the same value for historical rows saved before it existed (see
     * IncomeSource::displayName() / resolvedBusinessName()).
     *
     * Remittance is deliberately the short label, never the long template name.
     *
     * This is a display/identity default only. It is never an identity key: template duplicate
     * rules stay keyed on the template (and, for Other Business, on its exact normalized category
     * set) exactly as before — never on business_name.
     */
    public const DEFAULT_BUSINESS_NAMES = [
        'leasing_agricultural' => 'LEASING OPERATIONS: AGRICULTURAL REAL ESTATE',
        'leasing_poultry_farm' => 'LEASING OF POULTRY FARM OPERATIONS',
        'farming_corn' => 'FARMING: CORN PRODUCTION',
        'farming_sugarcane' => 'FARMING: SUGARCANE PRODUCTION',
        'remittance_income' => 'Remittance',
        'other_business_source_of_income' => 'OTHER BUSINESS/SOURCE OF INCOME',
    ];

    /** The mapped default for this template type, or null for every template that owns a real Business Name input. */
    public static function defaultBusinessNameFor(?string $templateType): ?string
    {
        return self::DEFAULT_BUSINESS_NAMES[$templateType] ?? null;
    }

    public function businessReportSchema(): array
    {
        return config("business-report-templates.{$this->template_type}.schema", []);
    }

    public function scopeActiveBusiness(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->where('form_handler', 'dedicated-business');
    }

    public function incomeSources(): HasMany
    {
        return $this->hasMany(IncomeSource::class);
    }
}
