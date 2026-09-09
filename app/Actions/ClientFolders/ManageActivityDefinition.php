<?php

namespace App\Actions\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Activity Type Management — operates on the reusable ActivityDefinition itself, never on a
 * CiActivity instance. Every entry point re-reads the definition under a lock and refuses any
 * canonical/system definition, so a forged request can never rename, deactivate or delete
 * Barangay / Neighbor / Asset / Bank-Coop Check. Deactivation and renaming leave every existing
 * CiActivity row (and its person scope, status, schedule and history) untouched; permanent
 * deletion is only ever allowed while the definition has no CiActivity referencing it at all.
 */
class ManageActivityDefinition
{
    public function rename(User $actor, ClientFolder $folder, ActivityDefinition $definition, string $name): ActivityDefinition
    {
        return DB::transaction(function () use ($actor, $folder, $definition, $name): ActivityDefinition {
            $definition = $this->lockCustomDefinition($definition);
            $previousName = $definition->name;
            $newName = ActivityDefinition::normalizeName($name);

            if ($newName !== $previousName) {
                $definition->update(['name' => $newName]);
                $this->recordAudit($actor, $folder, $definition, 'activity_definition.renamed', 'A reusable CI activity type was renamed.', [
                    'previous_name' => $previousName,
                ]);
            }

            return $definition;
        });
    }

    public function setActivation(User $actor, ClientFolder $folder, ActivityDefinition $definition, bool $active): ActivityDefinition
    {
        return DB::transaction(function () use ($actor, $folder, $definition, $active): ActivityDefinition {
            $definition = $this->lockCustomDefinition($definition);

            if ($definition->is_active !== $active) {
                $definition->update(['is_active' => $active]);
                $this->recordAudit(
                    $actor,
                    $folder,
                    $definition,
                    $active ? 'activity_definition.activated' : 'activity_definition.deactivated',
                    $active
                        ? 'A reusable CI activity type was activated.'
                        : 'A reusable CI activity type was deactivated.',
                );
            }

            return $definition;
        });
    }

    public function deletePermanently(User $actor, ClientFolder $folder, ActivityDefinition $definition): void
    {
        DB::transaction(function () use ($actor, $folder, $definition): void {
            $definition = $this->lockCustomDefinition($definition);

            // Authoritative usage check inside the same transaction/lock: a definition that any
            // CiActivity still references can never be deleted, whatever the client sent.
            $isUsed = $definition->activities()->lockForUpdate()->first(['ci_activities.id']) !== null;
            abort_if($isUsed, 422, 'This activity type is used by existing CI Activities and cannot be permanently deleted.');

            $definition->delete();
            $this->recordAudit($actor, $folder, $definition, 'activity_definition.deleted', 'An unused reusable CI activity type was permanently deleted.');
        });
    }

    private function lockCustomDefinition(ActivityDefinition $definition): ActivityDefinition
    {
        $locked = ActivityDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
        abort_unless($locked->isCustom(), 404);

        return $locked;
    }

    private function recordAudit(
        User $actor,
        ClientFolder $folder,
        ActivityDefinition $definition,
        string $action,
        string $description,
        array $metadata = [],
    ): void {
        AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => $action,
            'module' => 'activity_definitions',
            'description' => $description,
            'metadata' => $metadata + [
                'activity_definition_id' => $definition->id,
                'activity_title' => $definition->name,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
