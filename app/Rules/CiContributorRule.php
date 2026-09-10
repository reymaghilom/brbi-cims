<?php

namespace App\Rules;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class CiContributorRule
{
    /**
     * Active CI/Senior CI accounts are selectable. IDs already attached to this exact record
     * remain valid so an inactive historical contributor survives an unrelated edit.
     *
     * @param  iterable<int, int|string>  $historicalIds
     */
    public static function exists(iterable $historicalIds = []): Exists
    {
        $historicalIds = collect($historicalIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();

        return Rule::exists('users', 'id')->where(function ($query) use ($historicalIds): void {
            $query->where(function ($eligible) use ($historicalIds): void {
                $eligible->where(function ($active): void {
                    $active->whereIn('role', UserRole::creditInvestigatorRoles())
                        ->where('status', UserStatus::Active->value);
                });

                if ($historicalIds !== []) {
                    $eligible->orWhereIn('id', $historicalIds);
                }
            });
        });
    }
}
