<?php

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetDefaultCiActivities extends Command
{
    protected $signature = 'cims:reset-default-ci-activities
        {client_folder_id : ID of the exact ClientFolder to reset}
        {--co-maker= : ID of a Co-Maker belonging to the selected ClientFolder}';

    protected $description = 'Reset Barangay Check and Neighbor Check for one exact Applicant or Co-Maker context';

    public function handle(): int
    {
        $folderId = filter_var($this->argument('client_folder_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($folderId === false) {
            $this->error('The client_folder_id must be a positive integer.');

            return self::FAILURE;
        }

        $folder = ClientFolder::query()->find($folderId);
        if ($folder === null) {
            $this->error('The selected ClientFolder was not found.');

            return self::FAILURE;
        }

        $coMaker = $this->resolveCoMaker($folder);
        if ($coMaker === false) {
            return self::FAILURE;
        }

        $contextLabel = $coMaker instanceof CoMaker
            ? "Co-Maker {$coMaker->id} in ClientFolder {$folder->id}"
            : "Applicant in ClientFolder {$folder->id}";
        if ($this->input->isInteractive()
            && ! $this->confirm("Reset Barangay Check and Neighbor Check for {$contextLabel}?")) {
            $this->warn('Default CI activity reset cancelled.');

            return self::SUCCESS;
        }

        [$matched, $reset] = DB::transaction(function () use ($folder, $coMaker): array {
            ClientFolder::query()->whereKey($folder->id)->lockForUpdate()->firstOrFail();

            if ($coMaker instanceof CoMaker) {
                $folder->coMakers()->whereKey($coMaker->id)->lockForUpdate()->firstOrFail();
            }

            $activities = CiActivity::query()
                ->where('client_folder_id', $folder->id)
                ->where('co_maker_id', $coMaker instanceof CoMaker ? $coMaker->id : null)
                ->whereHas('definition', fn ($query) => $query->whereIn('code', [
                    ActivityDefinition::BARANGAY_CHECK_CODE,
                    ActivityDefinition::NEIGHBOR_CHECK_CODE,
                ]))
                ->lockForUpdate()
                ->get();
            $reset = 0;

            foreach ($activities as $activity) {
                $activity->forceFill([
                    'status' => ActivityStatus::Pending,
                    'scheduled_at' => null,
                    'scheduled_has_time' => false,
                    'reminder_sent_at' => null,
                    'remarks' => null,
                    'completed_at' => null,
                    'submitted_at' => null,
                    'submitted_by' => null,
                    'submission_note' => null,
                ]);

                if ($activity->isDirty()) {
                    $activity->save();
                    $reset++;
                }
            }

            return [$activities->count(), $reset];
        });

        $this->info('Default CI activity reset completed.');
        $this->line("Context: {$contextLabel}");
        $this->line("Mandatory defaults found: {$matched}");
        $this->line("Mandatory defaults reset: {$reset}");

        return self::SUCCESS;
    }

    private function resolveCoMaker(ClientFolder $folder): CoMaker|false|null
    {
        $option = $this->option('co-maker');
        if ($option === null) {
            return null;
        }

        $coMakerId = filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($coMakerId === false) {
            $this->error('The --co-maker option must be a positive integer.');

            return false;
        }

        $coMaker = $folder->coMakers()->find($coMakerId);
        if ($coMaker === null) {
            $this->error('The selected Co-Maker does not belong to the selected ClientFolder.');

            return false;
        }

        return $coMaker;
    }
}
