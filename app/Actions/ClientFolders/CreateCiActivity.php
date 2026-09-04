<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
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
        private readonly SaveCiActivityBankTarget $saveBankTarget,
        private readonly SaveCiActivityAssetTarget $saveAssetTarget,
    ) {}

    public function execute(User $actor, ClientFolder $folder, array $data): CiActivity
    {
        return DB::transaction(function () use ($actor, $folder, $data): CiActivity {
            $definition = ActivityDefinition::query()->where('is_active', true)->findOrFail($data['activity_definition_id']);
            $definition = ActivityDefinition::query()->whereKey($definition->id)->lockForUpdate()->firstOrFail();
            $this->ensureNotAlreadyAdded(
                $folder,
                $definition,
                $data['co_maker_id'] ?? null,
                'activity_definition_id',
            );
            $isBankCoopCheck = $definition->code === ActivityDefinition::BANK_COOP_CHECK_CODE;
            $isAssetCheck = $definition->code === ActivityDefinition::ASSET_CHECK_CODE;
            $isMultiTargetCheck = $isBankCoopCheck || $isAssetCheck;
            $status = $isMultiTargetCheck ? ActivityStatus::Pending : ActivityStatus::from($data['status']);
            [$scheduledAt, $scheduledHasTime] = $isMultiTargetCheck
                ? [null, false]
                : (in_array($status, [ActivityStatus::Scheduled, ActivityStatus::FollowUp], true)
                    ? CiActivity::normalizeScheduleInput($data['scheduled_at'] ?? null, $data['scheduled_time'] ?? null)
                    : [null, true]);
            $activity = $folder->activities()->create([
                'co_maker_id' => $data['co_maker_id'] ?? null,
                'activity_definition_id' => $definition->id,
                'name' => $definition->name,
                'target' => $data['target'] ?? null,
                'status' => $status,
                'scheduled_at' => $scheduledAt,
                'scheduled_has_time' => $scheduledHasTime,
                'remarks' => $isMultiTargetCheck ? null : ($data['remarks'] ?? null),
                'creator_id' => $actor->id,
                'updated_by' => $actor->id,
                'completed_at' => $status === ActivityStatus::Completed ? now() : null,
            ]);

            if ($isBankCoopCheck) {
                foreach ($data['bank_targets'] as $targetData) {
                    $targetStatus = ActivityStatus::from($targetData['status']);
                    [$targetSchedule, $targetHasTime] = CiActivityBankTarget::normalizeScheduleInput(
                        $targetStatus,
                        $targetData['scheduled_at'] ?? null,
                        $targetData['scheduled_time'] ?? null,
                    );
                    $activity->bankTargets()->create([
                        'inquiry_type' => $targetData['inquiry_type'],
                        'institution_name' => $targetData['institution_name'],
                        'branch_location' => $targetData['inquiry_type'] === CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY
                            ? null
                            : ($targetData['branch_location'] ?? null),
                        'status' => $targetStatus,
                        'scheduled_at' => $targetSchedule,
                        'scheduled_has_time' => $targetHasTime,
                        'remarks' => $targetData['remarks'] ?? null,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                }

                $status = $this->saveBankTarget->synchronizeParentStatus($activity, $actor);
                $activity->refresh();
            }

            if ($isAssetCheck) {
                foreach ($data['asset_targets'] as $targetData) {
                    $targetStatus = ActivityStatus::from($targetData['status']);
                    [$targetSchedule, $targetHasTime] = CiActivityAssetTarget::normalizeScheduleInput(
                        $targetStatus,
                        $targetData['scheduled_at'] ?? null,
                        $targetData['scheduled_time'] ?? null,
                    );
                    $activity->assetTargets()->create([
                        'assessor_type' => $targetData['assessor_type'],
                        'office_location' => $targetData['office_location'],
                        'status' => $targetStatus,
                        'scheduled_at' => $targetSchedule,
                        'scheduled_has_time' => $targetHasTime,
                        'remarks' => $targetData['remarks'] ?? null,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                }

                $status = $this->saveAssetTarget->synchronizeParentStatus($activity, $actor);
                $activity->refresh();
            }

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
                    'scheduled_has_time' => $activity->scheduled_has_time,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            if (! $isMultiTargetCheck && $status === ActivityStatus::Scheduled) {
                $actor->notify(new CiActivityScheduledReminder(
                    $activity,
                    CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED,
                ));
            }

            $this->completion->evaluate($folder);
            $this->progress->recalculate($folder);

            return $activity;
        });
    }

    public function createDefinition(User $actor, ClientFolder $folder, string $name): ActivityDefinition
    {
        return DB::transaction(
            fn (): ActivityDefinition => $this->resolveCustomDefinition($actor, $folder, $name),
        );
    }

    private function ensureNotAlreadyAdded(
        ClientFolder $folder,
        ActivityDefinition $definition,
        ?int $coMakerId,
        string $validationField,
    ): void {
        $alreadyExists = $folder->activities()
            ->where('activity_definition_id', $definition->id)
            ->where('co_maker_id', $coMakerId)
            ->lockForUpdate()
            ->first(['ci_activities.id']) !== null;

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                $validationField => $coMakerId === null
                    ? 'This activity already exists for the current Applicant.'
                    : 'This activity already exists for this Co-Maker.',
            ]);
        }
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
        $code = ActivityDefinition::CUSTOM_CODE_PREFIX.Str::limit($slug, 62, '').'_'.substr(hash('sha256', $key), 0, 10);
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
