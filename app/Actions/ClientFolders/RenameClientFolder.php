<?php

namespace App\Actions\ClientFolders;

use App\Exceptions\NoChangesDetectedException;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ClientNameFormatter;
use Illuminate\Support\Facades\DB;

class RenameClientFolder
{
    public function __construct(private readonly ClientNameFormatter $names) {}

    /** @param array{last_name: string, first_name: string, middle_name: ?string, suffix: ?string} $data */
    public function execute(User $actor, ClientFolder $folder, array $data): void
    {
        $displayName = $this->names->format(
            $data['last_name'],
            $data['first_name'],
            $data['middle_name'],
            $data['suffix'],
        );
        $changes = [...$data, 'display_name' => $displayName];

        if (! $folder->fill($changes)->isDirty(array_keys($changes))) {
            throw new NoChangesDetectedException('No changes detected.');
        }

        DB::transaction(function () use ($actor, $folder, $changes, $displayName): void {
            $previousName = (string) $folder->getOriginal('display_name');
            $folder->fill($changes);
            $folder->updated_by = $actor->id;
            $folder->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'client_folder.renamed',
                'module' => 'client_folders',
                'description' => 'A client folder was renamed.',
                'metadata' => ['previous_name' => $previousName, 'new_name' => $displayName],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
