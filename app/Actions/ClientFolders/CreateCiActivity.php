<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateCiActivity
{
    public function __construct(
        private readonly CiActivitiesCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, array $data): CiActivity
    {
        return DB::transaction(function () use ($actor, $folder, $data): CiActivity {
            $definition = ($data['create_new_activity_type'] ?? false)
                ? $this->resolveCustomDefinition($actor, $folder, $data['new_activity_type'])
                : ActivityDefinition::query()->where('is_active', true)->findOrFail($data['activity_definition_id']);
            $status = ActivityStatus::from($data['status']);
            $activity = $folder->activities()->create([
                'co_maker_id' => $data['co_maker_id'] ?? null,
                'activity_definition_id' => $definition->id,
                'name' => $definition->name,
                'target' => $data['target'] ?? null,
                'status' => $status,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'creator_id' => $actor->id,
                'updated_by' => $actor->id,
                'completed_at' => $status === ActivityStatus::Completed ? now() : null,
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'ci_activity.created',
                'module' => 'ci_activities',
                'description' => 'A CI activity was created.',
                'metadata' => [
                    'activity_id' => $activity->id,
                    'co_maker_id' => $activity->co_maker_id,
                    'activity_definition_id' => $definition->id,
                    'activity_title' => $definition->name,
                    'status' => $status->value,
                    'scheduled_at' => $activity->scheduled_at?->toISOString(),
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->completion->evaluate($folder);
            $this->progress->recalculate($folder);

            return $activity;
        });
    }

    private function resolveCustomDefinition(User $actor, ClientFolder $folder, string $name): ActivityDefinition
    {
        $name = ActivityDefinition::normalizeName($name);
        if (ActivityDefinition::isDedicatedModuleName($name)) {
            throw ValidationException::withMessages([
                'new_activity_type' => 'Residence Check and Business Check use their dedicated Client Folder modules.',
            ]);
        }

        $existing = ActivityDefinition::equivalentToName($name);
        if ($existing) {
            if (! $existing->is_active) {
                throw ValidationException::withMessages([
                    'new_activity_type' => 'An inactive activity type with this name already exists.',
                ]);
            }

            return $existing;
        }

        $key = ActivityDefinition::normalizedNameKey($name);
        $slug = Str::slug($key, '_') ?: 'activity';
        $code = 'custom_'.Str::limit($slug, 62, '').'_'.substr(hash('sha256', $key), 0, 10);
        $definition = ActivityDefinition::query()->createOrFirst(
            ['code' => $code],
            [
                'name' => $name,
                'sort_order' => ((int) ActivityDefinition::query()->max('sort_order')) + 10,
                'is_required' => false,
                'is_active' => true,
            ],
        );

        if (ActivityDefinition::normalizedNameKey($definition->name) !== $key || ! $definition->is_active) {
            throw ValidationException::withMessages([
                'new_activity_type' => 'This activity type conflicts with an existing definition.',
            ]);
        }

        if ($definition->wasRecentlyCreated) {
            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'activity_definition.created',
                'module' => 'activity_definitions',
                'description' => 'A reusable CI activity type was created.',
                'metadata' => [
                    'activity_definition_id' => $definition->id,
                    'activity_title' => $definition->name,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        }

        return $definition;
    }
}
