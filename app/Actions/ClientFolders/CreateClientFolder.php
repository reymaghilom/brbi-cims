<?php

namespace App\Actions\ClientFolders;

use App\Enums\ClientFolderStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ClientNameFormatter;
use App\Services\ClientFolders\FolderNumberGenerator;
use Illuminate\Support\Facades\DB;

class CreateClientFolder
{
    public function __construct(
        private readonly FolderNumberGenerator $numbers,
        private readonly ClientNameFormatter $names,
        private readonly SeedCiActivities $seedActivities,
    ) {}

    public function execute(User $actor, array $data): ClientFolder
    {
        return DB::transaction(function () use ($actor, $data): ClientFolder {
            $assignedCiId = $actor->role === UserRole::CreditInvestigator
                ? $actor->id
                : (int) $data['assigned_ci_id'];

            $folder = ClientFolder::create([
                'folder_number' => $this->numbers->next(now()->timezone(config('cims.display_timezone'))->year),
                'display_name' => $this->names->format($data['last_name'], $data['first_name'], $data['middle_name'], $data['suffix']),
                'last_name' => $data['last_name'],
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'],
                'suffix' => $data['suffix'],
                'assigned_ci_id' => $assignedCiId,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'status' => ClientFolderStatus::OnProgress,
                'progress_percent' => 0,
            ]);

            $this->seedActivities->execute($folder);

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'client_folder.created',
                'module' => 'client_folders',
                'description' => 'A client folder was created.',
                'metadata' => ['folder_number' => $folder->folder_number, 'assigned_ci_id' => $assignedCiId],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $folder;
        });
    }
}
