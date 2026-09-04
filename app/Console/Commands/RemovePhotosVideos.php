<?php

namespace App\Console\Commands;

use App\Services\Maintenance\PhotosVideosRemoval;
use Illuminate\Console\Command;

class RemovePhotosVideos extends Command
{
    protected $signature = 'cims:remove-photos-videos {--execute : Permanently remove the inventoried feature data and files}';

    protected $description = 'Inventory or permanently remove the retired Photos & Videos feature';

    public function handle(PhotosVideosRemoval $removal): int
    {
        $result = $this->option('execute') ? $removal->execute() : $removal->inventory();
        $this->components->info($this->option('execute') ? 'Photos & Videos removal completed.' : 'Photos & Videos removal dry run.');
        foreach ($result['counts'] as $name => $count) {
            $this->line("{$name}: {$count}");
        }
        $this->newLine();
        $this->line('Exact file targets:');
        foreach ($result['files'] as $file) {
            $this->line('  '.$file);
        }
        $this->newLine();
        $this->line('Exact feature directory targets (primary and legacy CI Team roots):');
        foreach ($result['directories'] as $directory) {
            $this->line('  '.$directory);
        }

        return self::SUCCESS;
    }
}
