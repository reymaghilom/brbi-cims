<?php

namespace App\Actions\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;

class CreateIncomeSource
{
    public function __construct(
        private readonly IncomeSourcesCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, array $data): IncomeSource
    {
        return DB::transaction(function () use ($actor, $folder, $data): IncomeSource {
            $template = IncomeSourceTemplate::query()->whereKey($data['income_source_template_id'])->where('is_active', true)->firstOrFail();
            $cibiReport = $folder->cibiReport;
            $source = $folder->incomeSources()->create([
                'income_source_template_id' => $template->id,
                'template_type' => $template->template_type,
                'template_version' => $template->version,
                'source_name' => $data['source_name'] ?? '',
                'business_name' => $data['business_name'] ?? null,
                'applicant_name_snapshot' => $folder->display_name,
                'branch_name' => $data['branch_name'] ?? $cibiReport?->branch_name,
                'account_officer_name' => $data['account_officer_name'] ?? $cibiReport?->account_officer_name,
                'amount_applied' => $data['amount_applied'] ?? $cibiReport?->amount_applied,
                'state' => RecordState::Draft,
                'last_edited_by' => $actor->id,
                'revision' => 1,
                'sort_order' => ((int) $folder->incomeSources()->max('sort_order')) + 1,
            ]);

            if ($template->is_fallback) {
                $source->generalReport()->create();
            } else {
                $businessName = $data['business_name'] ?? $data['source_name'] ?? '';
                $source->businessReport()->create([
                    'business_name' => $businessName,
                    'report_category' => $template->business_category ?: $template->name,
                    'start_date' => $data['start_date'] ?? null,
                    'submitted_date' => $data['submitted_date'] ?? null,
                ]);
            }

            $this->completion->evaluateFolder($folder);
            $this->progress->recalculate($folder);
            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'income_source.created', 'module' => 'income_sources',
                'description' => 'An income source was created.',
                'metadata' => ['income_source_id' => $source->id, 'template_type' => $source->template_type, 'template_version' => $source->template_version],
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);

            return $source->load('template');
        });
    }
}
