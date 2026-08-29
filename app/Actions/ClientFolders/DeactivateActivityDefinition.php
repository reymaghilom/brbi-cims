<?php

namespace App\Actions\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateActivityDefinition
{
    public const RESULT_DELETED = 'deleted';

    public const RESULT_DEACTIVATED = 'deactivated';

    public function execute(User $actor, ClientFolder $folder, ActivityDefinition $definition): string
    {
        return DB::transaction(function () use ($actor, $folder, $definition): string {
            $definition = ActivityDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            abort_unless($definition->isCustom(), 404);

            $hasHistoricalActivities = $definition->activities()
                ->lockForUpdate()
                ->first(['ci_activities.id']) !== null;

            if (! $hasHistoricalActivities) {
                $definition->delete();
                $this->recordAudit($actor, $folder, $definition, self::RESULT_DELETED);

                return self::RESULT_DELETED;
            }

            if ($definition->is_active) {
                $definition->update(['is_active' => false]);
                $this->recordAudit($actor, $folder, $definition, self::RESULT_DEACTIVATED);
            }

            return self::RESULT_DEACTIVATED;
        });
    }

    private function recordAudit(
        User $actor,
        ClientFolder $folder,
        ActivityDefinition $definition,
        string $result,
    ): void {
        AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => $result === self::RESULT_DELETED
                ? 'activity_definition.deleted'
                : 'activity_definition.deactivated',
            'module' => 'activity_definitions',
            'description' => $result === self::RESULT_DELETED
                ? 'An unused reusable CI activity type was permanently deleted.'
                : 'A reusable CI activity type was deactivated.',
            'metadata' => [
                'activity_definition_id' => $definition->id,
                'activity_title' => $definition->name,
                'removal_mode' => $result,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
