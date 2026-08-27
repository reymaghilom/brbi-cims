<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;

class DeleteIncomeSource
{
    public function __construct(private readonly IncomeSourcesCompletionEvaluator $completion, private readonly ClientProgressService $progress) {}

    public function execute(User $actor, ClientFolder $folder, IncomeSource $source): void
    {
        DB::transaction(function () use ($actor, $folder, $source): void {
            $sourceId = $source->id;
            $templateType = $source->template_type;
            $coMakerId = $source->co_maker_id;
            $displayName = $source->displayName();

            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'income_source.deleted', 'module' => 'income_sources',
                'description' => 'A business/income source was permanently deleted.',
                'metadata' => ['income_source_id' => $sourceId, 'co_maker_id' => $coMakerId, 'template_type' => $templateType, 'display_name' => $displayName],
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);

            $source->forceDelete();
            $this->completion->evaluateFolder($folder);
            $this->progress->recalculate($folder);
        });
    }
}
