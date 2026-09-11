<?php

namespace App\Console\Commands;

use App\Enums\ClientFolderStatus;
use App\Models\ClientFolder;
use App\Services\Progress\ClientProgressService;
use Illuminate\Console\Command;

/**
 * Folder progress/status is persisted and refreshed on each mandatory-work change. This brings
 * existing folders onto the mandatory-requirements formula in one pass. --dry-run only reads.
 */
class RecalculateClientFolderProgress extends Command
{
    protected $signature = 'cims:recalculate-folder-progress
        {--dry-run : Show the folders whose progress or status would change, without saving}
        {--folder= : Only this Client Folder id}';

    protected $description = 'Recalculate Client Folder progress and status from the mandatory investigation requirements';

    public function handle(ClientProgressService $progress): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $processed = 0;

        ClientFolder::query()
            ->when($this->option('folder'), fn ($query, $id) => $query->whereKey((int) $id))
            ->orderBy('id')
            ->chunkById(200, function ($folders) use ($progress, $dryRun, &$changed, &$processed): void {
                foreach ($folders as $folder) {
                    $processed++;
                    $result = $progress->calculate($folder);
                    $status = $result->isComplete ? ClientFolderStatus::Completed : ClientFolderStatus::OnProgress;
                    if ((float) $folder->progress_percent === (float) $result->percentage && $folder->status === $status) {
                        continue;
                    }

                    $changed++;
                    $this->line(sprintf('Folder #%d: %s%% %s -> %s%% %s',
                        $folder->id, (float) $folder->progress_percent, $folder->status->value, $result->percentage, $status->value));
                    if (! $dryRun) {
                        $progress->recalculate($folder);
                    }
                }
            });

        $this->info(($dryRun ? 'Dry run: ' : '')."{$changed} of {$processed} Client Folders ".($dryRun ? 'would change.' : 'updated.'));

        return self::SUCCESS;
    }
}
