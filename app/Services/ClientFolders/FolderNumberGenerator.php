<?php

namespace App\Services\ClientFolders;

use App\Models\ClientFolder;
use App\Models\FolderNumberSequence;
use Illuminate\Support\Facades\DB;

class FolderNumberGenerator
{
    public function next(int $year): string
    {
        DB::table('folder_number_sequences')->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = FolderNumberSequence::query()->lockForUpdate()->findOrFail($year);

        do {
            $sequence->last_number++;
            $folderNumber = sprintf('BRBI-CI-%d-%05d', $year, $sequence->last_number);
        } while (ClientFolder::withTrashed()->where('folder_number', $folderNumber)->exists());

        $sequence->save();

        return $folderNumber;
    }
}
