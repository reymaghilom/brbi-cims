<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ActivityDefinition extends Model
{
    use HasFactory;

    public const NEW_TYPE_VALUE = '__new__';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_active' => 'boolean'];
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CiActivity::class);
    }

    public static function normalizeName(string $name): string
    {
        return Str::squish($name);
    }

    public static function normalizedNameKey(string $name): string
    {
        return Str::lower(self::normalizeName($name));
    }

    public static function equivalentToName(string $name): ?self
    {
        return self::query()
            ->whereRaw('LOWER(name) = ?', [self::normalizedNameKey($name)])
            ->first();
    }

    public static function isDedicatedModuleName(string $name): bool
    {
        $key = Str::of($name)
            ->lower()
            ->replaceMatches('/[^\pL\pN]+/u', ' ')
            ->squish()
            ->toString();

        return in_array($key, ['residence check', 'business check'], true);
    }
}
