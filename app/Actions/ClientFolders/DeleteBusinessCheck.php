<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\PrivateMediaStorage;
use Illuminate\Support\Facades\DB;

class DeleteBusinessCheck
{
    public function __construct(
        private readonly PrivateMediaStorage $storage,
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
    ) {}

    public function execute(User $actor, ClientFolder $folder, BusinessCheck $check): void
    {
        DB::transaction(function () use ($actor, $folder, $check): void {
            $checkId = $check->id;
            $coMakerId = $check->co_maker_id;
            $paths = $check->photos()->get(['path', 'thumbnail_path'])->flatMap(fn ($photo) => [$photo->path, $photo->thumbnail_path])->all();
            $this->storage->deleteStoredFiles($paths);
            $check->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'business_check.deleted',
                'module' => 'residence_business_report',
                'description' => 'A Business Check was deleted.',
                'metadata' => ['business_check_id' => $checkId, 'co_maker_id' => $coMakerId],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->completion->evaluate($folder, $coMakerId);
        });
    }
}
