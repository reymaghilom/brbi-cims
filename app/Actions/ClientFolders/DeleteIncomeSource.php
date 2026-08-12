<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteIncomeSource
{
    public function __construct(private readonly IncomeSourcesCompletionEvaluator $completion, private readonly ClientProgressService $progress) {}

    public function execute(User $actor, ClientFolder $folder, IncomeSource $source): void
    {
        $references = collect(['mediaReferences', 'generatedReports', 'cibiSummaries', 'photoReportSections'])
            ->filter(fn (string $relation): bool => $source->{$relation}()->exists());
        if ($references->isNotEmpty()) {
            throw ValidationException::withMessages(['income_source' => 'This income source cannot be removed while it is linked to media, generated reports, CI / BI summaries, or photo-report sections.']);
        }

        DB::transaction(function () use ($actor, $folder, $source): void {
            $source->delete();
            $this->completion->evaluateFolder($folder);
            $this->progress->recalculate($folder);
            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'income_source.deleted', 'module' => 'income_sources',
                'description' => 'An income source was moved out of the active folder.',
                'metadata' => ['income_source_id' => $source->id, 'template_type' => $source->template_type],
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
