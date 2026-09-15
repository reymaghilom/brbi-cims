<?php

namespace App\Services\ClientFolders;

use App\Models\ClientFolder;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of a Client Folder that "already contains saved records" — and so may no
 * longer be permanently deleted by any role. Used by PurgeClientFolder (authoritative, at delete
 * time) and by ClientFolderBrowser (to render the matching delete dialog).
 *
 * A folder is still EMPTY while it holds only what folder creation writes: the folder row itself
 * (number, initial name, creator, informational assignment, timestamps) and its own identity history
 * (the client_folder.created / client_folder.renamed audit entries).
 *
 * It is NON-EMPTY as soon as any folder-owned operational record exists — including soft-deleted
 * rows — or any operational audit history exists for it. The history clause is what keeps a folder
 * non-deletable after its work was later cleared, deleted, or reset back to Pending / 0%.
 */
class ClientFolderSavedRecords
{
    // Identity-only history. Everything else a folder records (including Co-Maker added/removed,
    // which share the client_folders module) is operational history.
    private const IDENTITY_AUDIT_ACTIONS = ['client_folder.created', 'client_folder.renamed'];

    public function hasSavedRecords(ClientFolder $folder): bool
    {
        return $this->whereHasSavedRecords(ClientFolder::query()->whereKey($folder->getKey()))->exists();
    }

    /** Constrains a ClientFolder query to folders that already contain saved records. */
    public function whereHasSavedRecords(Builder $folders): Builder
    {
        return $folders->where(function (Builder $query): void {
            $query->whereHas('information')
                ->orWhereHas('addresses')
                ->orWhereHas('cibiReports')
                ->orWhereHas('coMakers')
                ->orWhereHas('incomeSources', fn (Builder $sources) => $sources->withTrashed())
                ->orWhereHas('residenceBusinessReport')
                ->orWhereHas('residenceChecks')
                ->orWhereHas('businessChecks')
                // Covers every activity type (Barangay, Neighbor, Bank/Coop, Asset, custom) and,
                // through it, their targets, notes and Supporting Proof.
                ->orWhereHas('activities', fn (Builder $activities) => $activities->withTrashed())
                ->orWhereHas('mediaReferences', fn (Builder $media) => $media->withTrashed())
                ->orWhereHas('generatedReports')
                ->orWhereHas('completionResults')
                ->orWhereHas('auditLogs', fn (Builder $audits) => $audits->whereNotIn('action', self::IDENTITY_AUDIT_ACTIONS));
        });
    }
}
