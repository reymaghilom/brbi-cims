<?php

namespace App\Console\Commands;

use App\Actions\ClientFolders\SeedCiActivities;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Console\Command;

class BackfillDefaultCiActivities extends Command
{
    protected $signature = 'cims:backfill-default-ci-activities {--chunk=200 : Number of records processed per database chunk}';

    protected $description = 'Backfill missing Barangay Check and Neighbor Check activities for existing person contexts';

    public function handle(SeedCiActivities $seedActivities): int
    {
        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 5000],
        ]);
        if ($chunkSize === false) {
            $this->error('The --chunk option must be an integer between 1 and 5000.');

            return self::FAILURE;
        }

        $foldersProcessed = 0;
        $applicantContexts = 0;
        $coMakerContexts = 0;
        $activitiesCreated = 0;
        $activitiesRestored = 0;
        $activitiesAlreadyActive = 0;

        ClientFolder::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($folders) use (
                $seedActivities,
                $chunkSize,
                &$foldersProcessed,
                &$applicantContexts,
                &$coMakerContexts,
                &$activitiesCreated,
                &$activitiesRestored,
                &$activitiesAlreadyActive,
            ): void {
                foreach ($folders as $folder) {
                    $foldersProcessed++;
                    $applicantContexts++;
                    $result = $seedActivities->execute($folder);
                    $activitiesCreated += $result['created'];
                    $activitiesRestored += $result['restored'];
                    $activitiesAlreadyActive += $result['already_active'];

                    $folder->coMakers()
                        ->orderBy('id')
                        ->chunkById($chunkSize, function ($coMakers) use (
                            $folder,
                            $seedActivities,
                            &$coMakerContexts,
                            &$activitiesCreated,
                            &$activitiesRestored,
                            &$activitiesAlreadyActive,
                        ): void {
                            foreach ($coMakers as $coMaker) {
                                /** @var CoMaker $coMaker */
                                $coMakerContexts++;
                                $result = $seedActivities->execute($folder, $coMaker);
                                $activitiesCreated += $result['created'];
                                $activitiesRestored += $result['restored'];
                                $activitiesAlreadyActive += $result['already_active'];
                            }
                        });
                }
            });

        $this->info('Default CI activity backfill completed.');
        $this->line("ClientFolders processed: {$foldersProcessed}");
        $this->line("Applicant contexts processed: {$applicantContexts}");
        $this->line("Co-Maker contexts processed: {$coMakerContexts}");
        $this->line("Activities created: {$activitiesCreated}");
        $this->line("Activities restored: {$activitiesRestored}");
        $this->line("Activities already active: {$activitiesAlreadyActive}");

        return self::SUCCESS;
    }
}
