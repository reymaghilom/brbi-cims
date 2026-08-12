<?php

namespace App\Actions\ClientFolders;

use App\Models\ActivityNote;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AddActivityNote
{
    public function execute(User $actor, ClientFolder $folder, CiActivity $activity, array $data): ActivityNote
    {
        return DB::transaction(function () use ($actor, $folder, $activity, $data): ActivityNote {
            $note = $activity->notes()->create($data + ['user_id' => $actor->id]);

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'activity_note.created',
                'module' => 'ci_activities',
                'description' => 'A note was added to a CI activity.',
                'metadata' => ['activity_id' => $activity->id, 'note_id' => $note->id, 'follow_up_needed' => $note->follow_up_needed],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $note;
        });
    }
}
