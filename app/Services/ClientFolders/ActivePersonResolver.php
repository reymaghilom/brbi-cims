<?php

namespace App\Services\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Resolves and validates which person (the Applicant, or a specific CoMaker) a request is
 * acting on. Applicant is always represented as null / a null co_maker_id — every person-scoped
 * table's co_maker_id column follows that same convention.
 */
class ActivePersonResolver
{
    /** Validation rule for a co_maker_id input: nullable, and must belong to this folder. */
    public static function rule(ClientFolder $folder): array
    {
        return ['nullable', 'integer', Rule::exists('co_makers', 'id')->where('client_folder_id', $folder->id)];
    }

    /**
     * Resolves a raw co_maker_id (already validated to belong to the folder) into its CoMaker.
     * Re-derives ownership from the folder relation itself rather than trusting the id alone.
     */
    public static function resolve(ClientFolder $folder, mixed $coMakerId): ?CoMaker
    {
        return blank($coMakerId) ? null : $folder->coMakers()->findOrFail((int) $coMakerId);
    }

    /**
     * GET-route convenience: only trusts co_maker_id when person=co-maker is also present,
     * mirroring the person-switcher's own logic in client-folders/show.blade.php.
     */
    public static function resolveFromQuery(ClientFolder $folder, Request $request): ?CoMaker
    {
        return $request->query('person') === 'co-maker'
            ? self::resolve($folder, $request->query('co_maker_id'))
            : null;
    }

    /** Query-string fragment that carries the active person forward across links/redirects. */
    public static function queryParams(?CoMaker $activePerson): array
    {
        return $activePerson ? ['person' => 'co-maker', 'co_maker_id' => $activePerson->id] : [];
    }

    /** Aborts 404 if an existing record does not belong to the resolved active person. */
    public static function assertOwnedBy(Model $record, ?CoMaker $activePerson): void
    {
        $ownerId = $record->getAttribute('co_maker_id');
        abort_unless(
            ($ownerId === null && $activePerson === null) || ($ownerId !== null && (int) $ownerId === $activePerson?->id),
            404,
        );
    }
}
