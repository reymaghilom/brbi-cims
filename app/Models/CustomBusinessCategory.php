<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CI-created checkbox option for the "Other Business / Source of Income" catalog, offered
 * alongside — never instead of — the default options config/business-report-templates.php defines.
 *
 * IDENTITY. The row id is the identity; `name` is only a label. The option key a Business Report
 * stores is derived from the id (optionKey()), so renaming a category can never split it into a
 * second one, and every report that already selected it stays attached to this exact row. That also
 * means custom categories drop straight into the existing combination-uniqueness rule, which
 * compares stored option keys and never labels (see StoreIncomeSourceRequest).
 *
 * REMOVAL. A category no one has used yet is genuinely deleted. One that already appears in saved
 * report data is deactivated instead: it leaves the catalog for new selections while every existing
 * report keeps both the selection and a correct label for it.
 */
class CustomBusinessCategory extends Model
{
    use HasFactory;

    /** Every stored option key for a custom category carries this prefix; nothing else does. */
    public const KEY_PREFIX = 'custom_';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** This category's stable stored key — derived from the id, so a rename never changes it. */
    public function optionKey(): string
    {
        return self::KEY_PREFIX.$this->getKey();
    }

    /** Is this a custom category's key rather than one of the default catalog keys? */
    public static function isCustomKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^'.self::KEY_PREFIX.'\d+$/', $key) === 1;
    }

    /** The catalog rows the Other Business form renders, in the same shape as the config's own. */
    public static function catalogChoices(): array
    {
        return static::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (self $category): array => [
                'key' => $category->optionKey(),
                'label' => $category->name,
                'custom_id' => $category->getKey(),
            ])
            ->all();
    }

    /**
     * Labels for custom keys a report already has saved, including deactivated ones — an existing
     * report must keep showing its own selection with a real name, never a bare key.
     *
     * @param  array<int, mixed>  $keys
     * @return array<string, string>
     */
    public static function labelsForKeys(array $keys): array
    {
        $ids = collect($keys)
            ->filter(fn (mixed $key): bool => static::isCustomKey($key))
            ->map(fn (string $key): int => (int) substr($key, strlen(self::KEY_PREFIX)))
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return static::query()
            ->whereKey($ids)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (self $category): array => [$category->optionKey() => $category->name])
            ->all();
    }

    /**
     * Add the selected custom choices to the normal output schema in saved selection order.
     * Default choices remain untouched and custom labels come from their stable database identity,
     * including inactive categories retained for historical reports.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, mixed>  $selectedKeys
     * @return array<string, mixed>
     */
    public static function resolveOutputSchema(array $schema, array $selectedKeys): array
    {
        $groups = (array) ($schema['income_source_groups'] ?? []);
        $knownKeys = collect($groups)->flatten(1)->pluck('key')->all();
        $customLabels = static::labelsForKeys($selectedKeys);

        foreach ($selectedKeys as $key) {
            if (! static::isCustomKey($key) || in_array($key, $knownKeys, true) || ! isset($customLabels[$key])) {
                continue;
            }

            $groups['business'][] = ['key' => $key, 'label' => $customLabels[$key]];
            $knownKeys[] = $key;
        }

        $schema['income_source_groups'] = $groups;

        return $schema;
    }

    /** Trimmed and case-folded, the one comparison used for duplicate checkbox names. */
    public static function normalizeName(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }
}
